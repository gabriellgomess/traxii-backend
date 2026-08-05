<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionControllerTest extends TestCase
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

    public function test_company_admin_creates_a_commission(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/commissions', [
                'name' => 'Padrão',
                'default_percentage' => 2.5,
                'default_amount' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Padrão')
            ->assertJsonPath('data.default_percentage', '2.50')
            ->assertJsonPath('data.default_amount', '50.00');

        $this->assertDatabaseHas('commissions', [
            'company_id' => $this->companyA->id,
            'name' => 'Padrão',
        ]);
    }

    public function test_guest_cannot_access_commissions(): void
    {
        $this->postJson('/api/commissions', ['name' => 'Padrão'])
            ->assertUnauthorized();
    }

    public function test_manager_cannot_access_commissions(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_COMPANY_MANAGER,
            'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/commissions')
            ->assertForbidden();
    }

    public function test_rejects_missing_fields(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/commissions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'default_percentage', 'default_amount']);
    }

    public function test_rejects_percentage_above_100(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/commissions', [
                'name' => 'Padrão',
                'default_percentage' => 150,
                'default_amount' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_percentage');
    }

    public function test_rejects_negative_amount(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/commissions', [
                'name' => 'Padrão',
                'default_percentage' => 10,
                'default_amount' => -5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_amount');
    }

    public function test_rejects_duplicate_name_within_same_company(): void
    {
        Commission::query()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Padrão',
            'default_percentage' => 2,
            'default_amount' => 10,
        ]);

        $this->actingAsAdmin()
            ->postJson('/api/commissions', [
                'name' => 'Padrão',
                'default_percentage' => 3,
                'default_amount' => 20,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_allows_same_name_in_different_companies(): void
    {
        Commission::query()->create([
            'company_id' => $this->companyB->id,
            'name' => 'Padrão',
            'default_percentage' => 2,
            'default_amount' => 10,
        ]);

        $this->actingAsAdmin()
            ->postJson('/api/commissions', [
                'name' => 'Padrão',
                'default_percentage' => 3,
                'default_amount' => 20,
            ])
            ->assertCreated();
    }

    public function test_index_only_lists_commissions_of_own_company(): void
    {
        Commission::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'A1',
            'default_percentage' => 1, 'default_amount' => 10,
        ]);
        Commission::query()->create([
            'company_id' => $this->companyB->id, 'name' => 'B1',
            'default_percentage' => 1, 'default_amount' => 10,
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/commissions')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'A1');
    }

    public function test_cannot_view_update_or_delete_commission_of_another_company(): void
    {
        $commission = Commission::query()->create([
            'company_id' => $this->companyB->id, 'name' => 'B1',
            'default_percentage' => 1, 'default_amount' => 10,
        ]);

        $this->actingAsAdmin()->getJson("/api/commissions/{$commission->id}")->assertNotFound();
        $this->actingAsAdmin()
            ->putJson("/api/commissions/{$commission->id}", [
                'name' => 'Hackeado', 'default_percentage' => 1, 'default_amount' => 1,
            ])
            ->assertNotFound();
        $this->actingAsAdmin()->deleteJson("/api/commissions/{$commission->id}")->assertNotFound();

        $this->assertDatabaseHas('commissions', ['id' => $commission->id, 'name' => 'B1']);
    }

    public function test_updates_a_commission(): void
    {
        $commission = Commission::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'Padrão',
            'default_percentage' => 2, 'default_amount' => 10,
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/commissions/{$commission->id}", [
                'name' => 'Padrão atualizado',
                'default_percentage' => 5,
                'default_amount' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Padrão atualizado');
    }

    public function test_deletes_a_commission(): void
    {
        $commission = Commission::query()->create([
            'company_id' => $this->companyA->id, 'name' => 'Padrão',
            'default_percentage' => 2, 'default_amount' => 10,
        ]);

        $this->actingAsAdmin()
            ->deleteJson("/api/commissions/{$commission->id}")
            ->assertOk();

        $this->assertDatabaseMissing('commissions', ['id' => $commission->id]);
    }
}
