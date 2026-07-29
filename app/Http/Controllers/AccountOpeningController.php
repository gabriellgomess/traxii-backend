<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountOpening\CompleteLivenessRequest;
use App\Http\Requests\AccountOpening\StoreAccountOpeningRequest;
use App\Http\Requests\AccountOpening\StoreDocumentsRequest;
use App\Http\Requests\AccountOpening\StoreSelfieRequest;
use App\Http\Requests\AccountOpening\SubmitAccountOpeningRequest;
use App\Http\Requests\AccountOpening\UpdateAddressRequest;
use App\Http\Requests\AccountOpening\UpdatePersonalDataRequest;
use App\Models\AccountOpening;
use App\Models\AccountOpeningDocument;
use App\Services\AccountOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fluxo público de abertura de conta PF (wizard do whitelabel).
 * Sem regra de negócio aqui: validação nos FormRequests, regras no Service.
 */
class AccountOpeningController extends Controller
{
    public function __construct(private readonly AccountOpeningService $service) {}

    /** POST /api/public/account-openings — etapa 1 (cria o rascunho) */
    public function store(StoreAccountOpeningRequest $request): JsonResponse
    {
        $result = $this->service->start(
            $request->safe()->except(['domain', 'ref']),
            $request->validated('domain'),
            $request->ip(),
            $request->validated('ref'),
        );

        return response()->json([
            'data' => [
                ...$this->service->progress($result['opening']),
                // Entregue uma única vez; necessário em todas as demais chamadas
                'resume_token' => $result['resume_token'],
            ],
        ], 201);
    }

    /** GET /api/public/account-openings/{uuid} — retomada do wizard */
    public function show(Request $request, AccountOpening $opening): JsonResponse
    {
        return $this->progressResponse($opening);
    }

    /** PUT /api/public/account-openings/{uuid}/personal-data — etapa 1 (edição) */
    public function updatePersonalData(
        UpdatePersonalDataRequest $request,
        AccountOpening $opening,
    ): JsonResponse {
        $opening = $this->service->updatePersonalData($opening, $request->validated(), $request->ip());

        return $this->progressResponse($opening);
    }

    /** PUT /api/public/account-openings/{uuid}/address — etapa 2 */
    public function updateAddress(UpdateAddressRequest $request, AccountOpening $opening): JsonResponse
    {
        $opening = $this->service->updateAddress($opening, $request->validated(), $request->ip());

        return $this->progressResponse($opening);
    }

    /** POST /api/public/account-openings/{uuid}/documents — etapa 3 */
    public function storeDocuments(StoreDocumentsRequest $request, AccountOpening $opening): JsonResponse
    {
        $files = array_intersect_key(
            $request->allFiles(),
            array_flip(AccountOpeningDocument::REQUIRED_UPLOAD_TYPES),
        );

        $opening = $this->service->storeDocuments($opening, $files, $request->ip());

        return $this->progressResponse($opening);
    }

    /** POST /api/public/account-openings/{uuid}/liveness — etapa 4 */
    public function completeLiveness(CompleteLivenessRequest $request, AccountOpening $opening): JsonResponse
    {
        $opening = $this->service->completeLiveness(
            $opening,
            $request->validated('challenges'),
            $request->ip(),
        );

        return $this->progressResponse($opening);
    }

    /** POST /api/public/account-openings/{uuid}/selfie — etapa 5 */
    public function storeSelfie(StoreSelfieRequest $request, AccountOpening $opening): JsonResponse
    {
        $opening = $this->service->storeSelfie($opening, $request->file('selfie'), $request->ip());

        return $this->progressResponse($opening);
    }

    /** POST /api/public/account-openings/{uuid}/submit — etapa 6 (aceites + envio) */
    public function submit(SubmitAccountOpeningRequest $request, AccountOpening $opening): JsonResponse
    {
        $opening = $this->service->submit($opening, $request->ip());

        return response()->json([
            'data' => $this->service->progress($opening),
            'message' => 'Cadastro enviado para análise. Você receberá um retorno em breve.',
        ]);
    }

    private function progressResponse(AccountOpening $opening): JsonResponse
    {
        return response()->json(['data' => $this->service->progress($opening)]);
    }
}
