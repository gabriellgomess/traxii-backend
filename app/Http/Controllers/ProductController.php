<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Produtos (operações) da empresa (whitelabel) — cadastrados pelo próprio
 * admin no painel comercial. Sempre escopados à empresa do usuário
 * autenticado; o company_id nunca vem do payload.
 */
class ProductController extends Controller
{
    /** GET /api/products */
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('operation')
            ->get();

        return response()->json(['data' => $products]);
    }

    /** POST /api/products */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['company_id'] = $request->user()->company_id;

        $product = Product::query()->create($data);

        return response()->json(['data' => $product], 201);
    }

    /** GET /api/products/{product} */
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $product);

        return response()->json(['data' => $product]);
    }

    /** PUT/PATCH /api/products/{product} */
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $product);

        $product->update($this->validated($request, $product));

        return response()->json(['data' => $product->refresh()]);
    }

    /** DELETE /api/products/{product} */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnedByAuthor($request, $product);

        $commissions = $product->commissions()->count();

        if ($commissions > 0) {
            throw ValidationException::withMessages([
                'product' => [
                    'Este produto possui '.$commissions.' '.
                    ($commissions === 1 ? 'comissão vinculada' : 'comissões vinculadas').
                    ' e não pode ser excluído.',
                ],
            ]);
        }

        $product->delete();

        return response()->json(['message' => 'Produto removido.']);
    }

    private function assertOwnedByAuthor(Request $request, Product $product): void
    {
        if ($product->company_id !== $request->user()->company_id) {
            abort(404, 'Recurso não encontrado.');
        }
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'operation' => [
                'required', 'string', 'max:150',
                Rule::unique('products', 'operation')
                    ->where('company_id', $companyId)
                    ->ignore($product?->id),
            ],
        ], [
            'operation.required' => 'Informe a operação do produto.',
            'operation.unique' => 'Já existe um produto com esta operação.',
        ]);
    }
}
