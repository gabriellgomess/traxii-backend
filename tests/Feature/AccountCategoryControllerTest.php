<?php

namespace Tests\Feature;

use App\Models\AccountCategory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountCategoryControllerTest extends TestCase
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

    public function test_company_admin_creates_a_category(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/account-categories', [
                'name' => 'Conta corrente',
                'min_movement' => 100,
                'max_movement' => 5000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Conta corrente');

        $this->assertDatabaseHas('account_categories', [
            'company_id' => $this->companyA->id,
            'name' => 'Conta corrente',
        ]);
    }

    public function test_guest_cannot_access_categories(): void
    {
        $this->postJson('/api/account-categories', ['name' => 'X'])
            ->assertUnauthorized();
    }

    public function test_manager_cannot_access_categories(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_COMPANY_MANAGER,
            'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/account-categories')
            ->assertForbidden();
    }

    public function test_rejects_missing_fields(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/account-categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'min_movement', 'max_movement']);
    }

    public function test_rejects_max_lower_than_min(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/account-categories', [
                'name' => 'Conta corrente',
                'min_movement' => 500,
                'max_movement' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_movement');
    }

    public function test_rejects_duplicate_name_within_same_company(): void
    {
        AccountCategory::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'Conta corrente',
            'min_movement' => 0, 'max_movement' => 100,
        ]);

        $this->actingAsAdmin()
            ->postJson('/api/account-categories', [
                'name' => 'Conta corrente', 'min_movement' => 0, 'max_movement' => 200,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_index_only_lists_categories_of_own_company(): void
    {
        AccountCategory::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'A1',
            'min_movement' => 0, 'max_movement' => 100,
        ]);
        AccountCategory::query()->create([
            'company_id' => $this->companyB->id, 'name' => 'B1',
            'min_movement' => 0, 'max_movement' => 100,
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/account-categories')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'A1');
    }

    public function test_cannot_view_update_or_delete_category_of_another_company(): void
    {
        $category = AccountCategory::query()->create([
            'company_id' => $this->companyB->id, 'name' => 'B1',
            'min_movement' => 0, 'max_movement' => 100,
        ]);

        $this->actingAsAdmin()->getJson("/api/account-categories/{$category->id}")->assertNotFound();
        $this->actingAsAdmin()
            ->putJson("/api/account-categories/{$category->id}", [
                'name' => 'Hackeado', 'min_movement' => 0, 'max_movement' => 1,
            ])
            ->assertNotFound();
        $this->actingAsAdmin()->deleteJson("/api/account-categories/{$category->id}")->assertNotFound();

        $this->assertDatabaseHas('account_categories', ['id' => $category->id, 'name' => 'B1']);
    }

    public function test_updates_a_category(): void
    {
        $category = AccountCategory::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'Conta corrente',
            'min_movement' => 0, 'max_movement' => 100,
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/account-categories/{$category->id}", [
                'name' => 'Conta corrente atualizada', 'min_movement' => 10, 'max_movement' => 200,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Conta corrente atualizada');
    }

    public function test_deletes_a_category(): void
    {
        $category = AccountCategory::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'Conta corrente',
            'min_movement' => 0, 'max_movement' => 100,
        ]);

        $this->actingAsAdmin()
            ->deleteJson("/api/account-categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('account_categories', ['id' => $category->id]);
    }
}
