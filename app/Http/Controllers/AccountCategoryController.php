<?php

namespace App\Http\Controllers;

use App\Models\AccountCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categorias de conta (tela experimental) — cadastradas pelo admin do
 * whitelabel. Sempre escopadas à empresa do usuário autenticado; o
 * company_id nunca vem do payload.
 *
 * Remova este controller junto com o model, a migration e a rota
 * correspondente caso a tela não seja mantida.
 */
class AccountCategoryController extends Controller
{
    /** GET /api/account-categories */
    public function index(Request $request): JsonResponse
    {
        $categories = AccountCategory::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /** POST /api/account-categories */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $request->user()->company_id;

        $category = AccountCategory::query()->create($data);

        return response()->json(['data' => $category], 201);
    }

    /** GET /api/account-categories/{account_category} */
    public function show(Request $request, AccountCategory $accountCategory): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $accountCategory);

        return response()->json(['data' => $accountCategory]);
    }

    /** PUT/PATCH /api/account-categories/{account_category} */
    public function update(Request $request, AccountCategory $accountCategory): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $accountCategory);

        $accountCategory->update($this->validated($request, $accountCategory));

        return response()->json(['data' => $accountCategory->refresh()]);
    }

    /** DELETE /api/account-categories/{account_category} */
    public function destroy(Request $request, AccountCategory $accountCategory): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $accountCategory);

        $accountCategory->delete();

        return response()->json(['message' => 'Categoria removida.']);
    }

    private function assertOwnedByAuthor(Request $request, AccountCategory $accountCategory): void
    {
        if ($accountCategory->company_id !== $request->user()->company_id) {
            abort(404, 'Recurso não encontrado.');
        }
    }

    private function validated(Request $request, ?AccountCategory $accountCategory = null): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('account_categories', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($accountCategory?->id),
            ],
            'min_movement' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'max_movement' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'gte:min_movement'],
        ], [
            'name.required' => 'Informe o nome da categoria.',
            'name.unique' => 'Já existe uma categoria com este nome.',
            'min_movement.required' => 'Informe o mínimo de movimentações.',
            'min_movement.min' => 'O mínimo de movimentações não pode ser negativo.',
            'max_movement.required' => 'Informe o máximo de movimentações.',
            'max_movement.gte' => 'O máximo de movimentações deve ser maior ou igual ao mínimo.',
        ]);
    }
}
