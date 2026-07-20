<?php

namespace App\Http\Controllers;

use App\Models\AccountOpening;
use App\Models\AccountOpeningDocument;
use App\Services\AccountOpeningReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backoffice de aberturas de conta (app Gestor).
 * super_admin enxerga todas as empresas; usuários de empresa, só a própria.
 */
class AccountOpeningReviewController extends Controller
{
    public function __construct(private readonly AccountOpeningReviewService $service) {}

    /** GET /api/account-openings?status=&company_id=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request)
            ->with(['company:id,name', 'reviewer:id,name'])
            ->orderByRaw('COALESCE(submitted_at, created_at) DESC');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereNot('status', AccountOpening::STATUS_DRAFT);
        }

        if ($request->user()->isSuperAdmin() && ($companyId = $request->query('company_id'))) {
            $query->where('company_id', $companyId);
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
            'reviewer:id,name',
            'creator:id,name',
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

        return response()->json([
            'data' => $this->service->startAnalysis($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/approve */
    public function approve(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);

        return response()->json([
            'data' => $this->service->approve($opening, $request->user(), $request->ip()),
        ]);
    }

    /** POST /api/account-openings/{uuid}/reject */
    public function reject(Request $request, AccountOpening $opening): JsonResponse
    {
        $this->authorizeOpening($request, $opening);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Informe o motivo da reprovação.'],
        );

        return response()->json([
            'data' => $this->service->reject($opening, $request->user(), $data['reason'], $request->ip()),
        ]);
    }

    /** Query base restrita ao escopo do usuário logado. */
    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = AccountOpening::query();

        if (! $user->isSuperAdmin()) {
            // company_id null (usuário mal configurado) não enxerga nada
            $query->where('company_id', $user->company_id ?? -1);
        }

        return $query;
    }

    private function authorizeOpening(Request $request, AccountOpening $opening): void
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && $opening->company_id !== $user->company_id) {
            abort(403, 'Você não tem acesso a este cadastro.');
        }
    }
}
