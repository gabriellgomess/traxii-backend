<?php

namespace App\Http\Controllers;

use App\Models\AccountOpening;
use App\Services\AccountOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolução pública de pendência pelo cliente (link com token).
 */
class AccountOpeningPendencyController extends Controller
{
    private const FILE_RULES = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

    public function __construct(private readonly AccountOpeningService $service) {}

    /** GET /api/public/account-openings/{uuid}/pendency?t= */
    public function show(Request $request, AccountOpening $opening): JsonResponse
    {
        $pendency = $this->service->findOpenPendencyByToken(
            $opening,
            (string) $request->query('t', ''),
        );

        return response()->json([
            'data' => [
                'full_name' => $opening->full_name,
                'message' => $pendency->message,
                'requested_items' => $pendency->requested_items,
                'company' => $opening->company?->toTheme(),
            ],
        ]);
    }

    /** POST /api/public/account-openings/{uuid}/pendency/resolve (multipart) */
    public function resolve(Request $request, AccountOpening $opening): JsonResponse
    {
        $pendency = $this->service->findOpenPendencyByToken(
            $opening,
            (string) $request->input('t', ''),
        );

        $request->validate([
            'document_front' => ['sometimes', ...self::FILE_RULES],
            'document_back' => ['sometimes', ...self::FILE_RULES],
            'address_proof' => ['sometimes', ...self::FILE_RULES],
            'selfie' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            '*.mimes' => 'Arquivo inválido (use JPG, PNG, WEBP ou PDF).',
            '*.max' => 'Arquivo muito grande (máx. 10 MB).',
        ]);

        $files = [];
        foreach ($pendency->requested_items ?? [] as $type) {
            if ($request->hasFile($type)) {
                $files[$type] = $request->file($type);
            }
        }

        $opening = $this->service->resolvePendency($opening, $pendency, $files, $request->ip());

        return response()->json([
            'data' => ['status' => $opening->status],
        ]);
    }
}
