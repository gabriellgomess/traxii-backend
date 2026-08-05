<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comissões da empresa (whitelabel) — cadastradas pelo próprio admin no
 * painel comercial. Sempre escopadas à empresa do usuário autenticado; o
 * company_id nunca vem do payload.
 */
class CommissionController extends Controller
{
    /** GET /api/commissions */
    public function index(Request $request): JsonResponse
    {
        $commissions = Commission::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $commissions]);
    }

    /** POST /api/commissions */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $request->user()->company_id;

        $commission = Commission::query()->create($data);

        return response()->json(['data' => $commission], 201);
    }

    /** GET /api/commissions/{commission} */
    public function show(Request $request, Commission $commission): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $commission);

        return response()->json(['data' => $commission]);
    }

    /** PUT/PATCH /api/commissions/{commission} */
    public function update(Request $request, Commission $commission): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $commission);

        $commission->update($this->validated($request, $commission));

        return response()->json(['data' => $commission->refresh()]);
    }

    /** DELETE /api/commissions/{commission} */
    public function destroy(Request $request, Commission $commission): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $commission);

        $commission->delete();

        return response()->json(['message' => 'Comissão removida.']);
    }

    private function assertOwnedByAuthor(Request $request, Commission $commission): void
    {
        if ($commission->company_id !== $request->user()->company_id) {
            abort(404, 'Recurso não encontrado.');
        }
    }

    private function validated(Request $request, ?Commission $commission = null): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('commissions', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($commission?->id),
            ],
            'default_percentage' => ['required', 'numeric', 'between:0,100'],
            'default_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ], [
            'name.required' => 'Informe o nome da comissão.',
            'name.unique' => 'Já existe uma comissão com este nome.',
            'default_percentage.required' => 'Informe a comissão padrão em %.',
            'default_percentage.between' => 'A comissão em % deve estar entre 0 e 100.',
            'default_amount.required' => 'Informe a comissão padrão em R$.',
            'default_amount.min' => 'A comissão em R$ não pode ser negativa.',
        ]);
    }
}
