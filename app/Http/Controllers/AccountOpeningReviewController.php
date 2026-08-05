<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountOpening\BackofficeStoreRequest;
use App\Models\AccountOpening;
use App\Models\AccountOpeningDocument;
use App\Models\AccountOpeningPendency;
use App\Services\AccountOpeningReviewService;
use App\Services\AccountOpeningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backoffice de aberturas de conta.
 *
 * Escopo de leitura: master/analista (Percapital) veem todas as empresas;
 * admin do whitelabel vê a própria empresa; gerente vê só as propostas que
 * vieram do seu link. Escrita (decisões) é exclusiva de master e analistas.
 */
class AccountOpeningReviewController extends Controller
{
    public function __construct(
        private readonly AccountOpeningReviewService $service,
        private readonly AccountOpeningService $openingService,
    ) {}

    /** POST /api/account-openings — cadastro manual pelo backoffice */
    public function store(BackofficeStoreRequest $request): JsonResponse
    {
        $this->authorizeReview($request);

        $user = $request->user();
        $validated = $request->validated();

        // super_admin escolhe a empresa; usuário de empresa fica na própria
        $companyId = $user->isSuperAdmin()
            ? (int) ($validated['company_id'] ?? 0)
            : (int) $user->company_id;

        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => ['Selecione a empresa (whitelabel) do cliente.'],
            ]);
        }

        $files = [];
        foreach (['document_front', 'document_back', 'address_proof', 'selfie'] as $type) {
            if ($request->hasFile($type)) {
                $files[$type] = $request->file($type);
            }
        }

        unset(
            $validated['company_id'],
            $validated['document_front'],
            $validated['document_back'],
            $validated['address_proof'],
            $validated['selfie'],
        );

        $opening = $this->openingService->createFromBackoffice(
            $validated,
            $companyId,
            $files,
            $user,
            $request->ip(),
        );

        return response()->json(['data' => $opening], 201);
    }

    /** GET /api/account-openings?status=&company_id=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request)
            ->with(['company:id,name', 'reviewer:id,name', 'manager:id,name'])
            ->orderByRaw('COALESCE(submitted_at, created_at) DESC');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereNot('status', AccountOpening::STATUS_DRAFT);
        }

        if ($request->user()->seesAllCompanies() && ($companyId = $request->query('company_id'))) {
            $query->where('company_id', $companyId);
        }

        // Filtro por gerente comercial (master/analista e admin do whitelabel)
        if (! $request->user()->isManager() && ($managerId = $request->query('manager_id'))) {
            $query->where('manager_id', $managerId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function (Builder $q) use ($search, $digits) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('cpf', 'like', "%{$digits}%");
                }
            });
        }

        $page = $query->paginate(25);

        // Contagem por status (respeitando o escopo) para os filtros da UI
        $counts = $this->scopedQuery($request)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => $counts,
            ],
        ]);
    }

    /** GET /api/account-openings/{uuid} */
    public function show(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);

        $opening->load([
            'company:id,name,domain,primary_color,secondary_color,logo_path',
            'documents:id,account_opening_id,type,original_name,mime_type,size,created_at',
            'events' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
            'pendencies' => fn ($q) => $q->with('creator:id,name')->orderByDesc('id'),
            'reviewer:id,name',
            'creator:id,name',
            'manager:id,name,email,referral_code',
        ]);

        return response()->json(['data' => $opening]);
    }

    /** GET /api/account-openings/{uuid}/documents/{document} — streaming do disco privado */
    public function document(
        Request $request,
        AccountOpening $opening,
        AccountOpeningDocument $document,
    ): StreamedResponse {
        $this->authorizeOpening($request, $opening);
        abort_unless($document->account_opening_id === $opening->id, 404);
        abort_unless(
            Storage::disk('local')->exists($document->path),
            404,
            'Arquivo não encontrado no armazenamento.',
        );

        return Storage::disk('local')->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime_type],
        );
    }

    /** POST /api/account-openings/{uuid}/start-analysis */
    public function startAnalysis(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        return response()->json([
            'data' => $this->service->startAnalysis($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/approve */
    public function approve(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        return response()->json([
            'data' => $this->service->approve($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/reject */
    public function reject(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Informe o motivo da reprovação.'],
        );

        return response()->json([
            'data' => $this->service->reject($opening, $request->user(), $data['reason'], $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/pendency — cria pendência (itens + mensagem ao cliente) */
    public function createPendency(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', Rule::in(AccountOpeningPendency::REQUESTABLE_ITEMS)],
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'items.required' => 'Selecione ao menos um item a reenviar.',
            'items.*.in' => 'Item inválido.',
            'message.required' => 'Escreva a mensagem que o cliente vai receber.',
        ]);

        $result = $this->service->createPendency(
            $opening,
            $request->user(),
            $data['items'],
            $data['message'],
            $request->ip(),
        );

        return response()->json([
            'data' => $result['opening'],
            'resolution_url' => $result['resolution_url'],
        ]);
    }

    /** POST /api/account-openings/{uuid}/resume-analysis — pendência resolvida fora do link */
    public function resumeAnalysis(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        return response()->json([
            'data' => $this->service->resumeAnalysis($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/block — bloqueia a conta (reversível) */
    public function block(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->service->block($opening, $request->user(), $data['reason'] ?? null, $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/unblock */
    public function unblock(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        return response()->json([
            'data' => $this->service->unblock($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/deactivate — desativa a conta */
    public function deactivate(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->service->deactivate($opening, $request->user(), $data['reason'] ?? null, $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/reactivate */
    public function reactivate(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        return response()->json([
            'data' => $this->service->reactivate($opening, $request->user(), $request->ip()),
        ]);
    }

    /** DELETE /api/account-openings/{uuid} — exclusão definitiva */
    public function destroy(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);
        $this->authorizeReview($request);

        $this->service->delete($opening);

        return response()->json(['message' => 'Proposta excluída definitivamente.']);
    }

    /** Query base restrita ao escopo do usuário logado. */
    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = AccountOpening::query();

        // Percapital (master/analista) enxerga todas as empresas
        if ($user->seesAllCompanies()) {
            return $query;
        }

        // Gerente comercial: propostas indicadas por ele e, se for gerente
        // "topo" na hierarquia, também as indicadas pelos seus subgerentes
        if ($user->isManager()) {
            return $query->whereIn('manager_id', $user->visibleManagerIds());
        }

        // Whitelabel: apenas a própria empresa
        // (company_id null em usuário mal configurado não enxerga nada)
        return $query->where('company_id', $user->company_id ?? -1);
    }

    private function authorizeOpening(Request $request, AccountOpening $opening): void
    {
        $user = $request->user();

        if ($user->seesAllCompanies()) {
            return;
        }

        if ($user->isManager()) {
            if (! in_array($opening->manager_id, $user->visibleManagerIds(), true)) {
                abort(403, 'Você não tem acesso a este cadastro.');
            }

            return;
        }

        if ($opening->company_id !== $user->company_id) {
            abort(403, 'Você não tem acesso a este cadastro.');
        }
    }

    /**
     * Decisões sobre a proposta são exclusivas da Percapital (master e
     * analistas). Whitelabel e gerentes têm acesso somente leitura.
     */
    private function authorizeReview(Request $request): void
    {
        if (! $request->user()->canReviewOpenings()) {
            abort(403, 'Apenas a Percapital pode alterar propostas.');
        }
    }
}
