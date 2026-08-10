<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::query()->create([
            'name' => 'NovaBank', 'domain' => 'novabank.com.br',
            'primary_color' => '#1437C9', 'secondary_color' => '#FF7A1A',
        ]);
        $this->companyB = Company::query()->create([
            'name' => 'Verde Pay', 'domain' => 'verdepay.com.br',
            'primary_color' => '#0B8A5C', 'secondary_color' => '#FFC53D',
        ]);

        $this->admin = User::factory()->create([
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->companyA->id,
        ]);
    }

    private function actingAsAdmin(): static
    {
        $this->actingAs($this->admin, 'sanctum');

        return $this;
    }

    public function test_company_admin_creates_a_product(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/products', ['operation' => 'CDB Pós-fixado'])
            ->assertCreated()
            ->assertJsonPath('data.operation', 'CDB Pós-fixado');

        $this->assertDatabaseHas('products', [
            'company_id' => $this->companyA->id,
            'operation' => 'CDB Pós-fixado',
        ]);
    }

    public function test_guest_cannot_access_products(): void
    {
        $this->postJson('/api/products', ['operation' => 'CDB'])
            ->assertUnauthorized();
    }

    public function test_manager_cannot_access_products(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_COMPANY_MANAGER,
            'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/products')
            ->assertForbidden();
    }

    public function test_rejects_missing_operation(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);
    }

    public function test_rejects_duplicate_operation_within_same_company(): void
    {
        Product::query()->create(['company_id' => $this->companyA->id, 'operation' => 'CDB']);

        $this->actingAsAdmin()
            ->postJson('/api/products', ['operation' => 'CDB'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operation');
    }

    public function test_allows_same_operation_in_different_companies(): void
    {
        Product::query()->create(['company_id' => $this->companyB->id, 'operation' => 'CDB']);

        $this->actingAsAdmin()
            ->postJson('/api/products', ['operation' => 'CDB'])
            ->assertCreated();
    }

    public function test_index_only_lists_products_of_own_company(): void
    {
        Product::query()->create(['company_id' => $this->companyA->id, 'operation' => 'CDB']);
        Product::query()->create(['company_id' => $this->companyB->id, 'operation' => 'LCA']);

        $response = $this->actingAsAdmin()->getJson('/api/products')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.operation', 'CDB');
    }

    public function test_cannot_view_update_or_delete_product_of_another_company(): void
    {
        $product = Product::query()->create(['company_id' => $this->companyB->id, 'operation' => 'LCA']);

        $this->actingAsAdmin()->getJson("/api/products/{$product->id}")->assertNotFound();
        $this->actingAsAdmin()
            ->putJson("/api/products/{$product->id}", ['operation' => 'Hackeado'])
            ->assertNotFound();
        $this->actingAsAdmin()->deleteJson("/api/products/{$product->id}")->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'operation' => 'LCA']);
    }

    public function test_updates_a_product(): void
    {
        $product = Product::query()->create(['company_id' => $this->companyA->id, 'operation' => 'CDB']);

        $this->actingAsAdmin()
            ->putJson("/api/products/{$product->id}", ['operation' => 'CDB atualizado'])
            ->assertOk()
            ->assertJsonPath('data.operation', 'CDB atualizado');
    }

    public function test_deletes_a_product(): void
    {
        $product = Product::query()->create(['company_id' => $this->companyA->id, 'operation' => 'CDB']);

        $this->actingAsAdmin()
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_cannot_delete_product_with_linked_commissions(): void
    {
        $product = Product::query()->create(['company_id' => $this->companyA->id, 'operation' => 'CDB']);
        Commission::query()->create([
            'company_id' => $this->companyA->id, 'product_id' => $product->id,
            'name' => 'Padrão', 'default_percentage' => 2, 'default_amount' => 10,
        ]);

        $this->actingAsAdmin()
            ->deleteJson("/api/products/{$product->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
