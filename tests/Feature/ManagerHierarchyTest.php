<?php

namespace Tests\Feature;

use App\Models\AccountOpening;
use App\Models\Commission;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagerHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $companyAdmin;

    private Commission $commission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'NovaBank', 'domain' => 'novabank.com.br',
            'primary_color' => '#1437C9', 'secondary_color' => '#FF7A1A',
        ]);

        $this->companyAdmin = User::factory()->create([
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->company->id,
        ]);

        $product = Product::query()->create([
            'company_id' => $this->company->id, 'operation' => 'CDB',
        ]);
        $this->commission = Commission::query()->create([
            'company_id' => $this->company->id, 'product_id' => $product->id,
            'name' => 'Padrão', 'default_percentage' => 2, 'default_amount' => 10,
        ]);
    }

    private function managerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Gerente Teste',
            'email' => 'gerente'.uniqid().'@example.com',
            'document' => '529.982.247-25',
            'phone' => '(11) 98888-7777',
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'commission_id' => $this->commission->id,
        ], $overrides);
    }

    private function createManager(array $overrides = []): User
    {
        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [...$this->managerPayload($overrides), 'role' => 'company_manager']);
        $response->assertCreated();

        $manager = User::query()->findOrFail($response->json('data.id'));
        // Senha provisória exigiria troca no primeiro acesso (423); os testes
        // de escopo autenticam como o gerente diretamente, sem esse fluxo.
        $manager->forceFill(['must_change_password' => false])->save();

        return $manager;
    }

    public function test_creates_submanager_linked_to_a_top_manager(): void
    {
        $manager = $this->createManager(['email' => 'gerente1@example.com']);

        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(['email' => 'sub1@example.com']),
                'role' => 'company_manager',
                'parent_manager_id' => $manager->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent_manager.id', $manager->id)
            ->assertJsonPath('data.parent_manager.name', $manager->name);

        $this->assertDatabaseHas('users', [
            'email' => 'sub1@example.com',
            'parent_manager_id' => $manager->id,
        ]);
    }

    public function test_rejects_parent_manager_from_another_company(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Verde Pay', 'domain' => 'verdepay.com.br',
            'primary_color' => '#0B8A5C', 'secondary_color' => '#FFC53D',
        ]);
        $outsider = User::factory()->create([
            'role' => User::ROLE_COMPANY_MANAGER,
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(),
                'role' => 'company_manager',
                'parent_manager_id' => $outsider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_manager_id');
    }

    public function test_rejects_submanager_as_parent_of_another_submanager(): void
    {
        $manager = $this->createManager(['email' => 'gerente2@example.com']);
        $subManager = $this->createManager([
            'email' => 'sub2@example.com',
        ]);
        // vincula sub2 como subgerente de gerente2 via update
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->putJson("/api/users/{$subManager->id}", [
                ...$this->managerPayload(['email' => 'sub2@example.com']),
                'parent_manager_id' => $manager->id,
            ])->assertOk();

        // agora tenta criar um terceiro gerente vinculado ao sub2 (subgerente de subgerente)
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(['email' => 'sub3@example.com']),
                'role' => 'company_manager',
                'parent_manager_id' => $subManager->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_manager_id');
    }

    public function test_manager_sees_own_and_submanagers_account_openings(): void
    {
        $manager = $this->createManager(['email' => 'topo@example.com']);
        $subManager = $this->createManager(['email' => 'sub@example.com']);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->putJson("/api/users/{$subManager->id}", [
                ...$this->managerPayload(['email' => 'sub@example.com']),
                'parent_manager_id' => $manager->id,
            ])->assertOk();

        $otherManager = $this->createManager(['email' => 'outro@example.com']);

        $openingFromManager = $this->makeOpening($manager->id);
        $openingFromSub = $this->makeOpening($subManager->id);
        $openingFromOther = $this->makeOpening($otherManager->id);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/account-openings')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('uuid');
        $this->assertTrue($ids->contains($openingFromManager->uuid));
        $this->assertTrue($ids->contains($openingFromSub->uuid));
        $this->assertFalse($ids->contains($openingFromOther->uuid));
    }

    public function test_submanager_only_sees_own_account_openings(): void
    {
        $manager = $this->createManager(['email' => 'topo2@example.com']);
        $subManager = $this->createManager(['email' => 'sub4@example.com']);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->putJson("/api/users/{$subManager->id}", [
                ...$this->managerPayload(['email' => 'sub4@example.com']),
                'parent_manager_id' => $manager->id,
            ])->assertOk();

        $openingFromManager = $this->makeOpening($manager->id);
        $openingFromSub = $this->makeOpening($subManager->id);

        $response = $this->actingAs($subManager, 'sanctum')
            ->getJson('/api/account-openings')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('uuid');
        $this->assertTrue($ids->contains($openingFromSub->uuid));
        $this->assertFalse($ids->contains($openingFromManager->uuid));
    }

    public function test_cannot_delete_manager_with_submanagers(): void
    {
        $manager = $this->createManager(['email' => 'topo3@example.com']);
        $subManager = $this->createManager(['email' => 'sub5@example.com']);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->putJson("/api/users/{$subManager->id}", [
                ...$this->managerPayload(['email' => 'sub5@example.com']),
                'parent_manager_id' => $manager->id,
            ])->assertOk();

        $this->actingAs($this->companyAdmin, 'sanctum')
            ->deleteJson("/api/users/{$manager->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $manager->id]);
    }

    public function test_rejects_manager_without_commission(): void
    {
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(['commission_id' => null]),
                'role' => 'company_manager',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commission_id');
    }

    public function test_rejects_commission_from_another_company(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Verde Pay', 'domain' => 'verdepay.com.br',
            'primary_color' => '#0B8A5C', 'secondary_color' => '#FFC53D',
        ]);
        $otherProduct = Product::query()->create([
            'company_id' => $otherCompany->id, 'operation' => 'LCA',
        ]);
        $otherCommission = Commission::query()->create([
            'company_id' => $otherCompany->id, 'product_id' => $otherProduct->id,
            'name' => 'Outra', 'default_percentage' => 1, 'default_amount' => 5,
        ]);

        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(['commission_id' => $otherCommission->id]),
                'role' => 'company_manager',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commission_id');
    }

    public function test_creates_manager_with_commission_linked(): void
    {
        $response = $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson('/api/users', [
                ...$this->managerPayload(['email' => 'comissionado@example.com']),
                'role' => 'company_manager',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.commission.id', $this->commission->id)
            ->assertJsonPath('data.commission.product.operation', 'CDB');

        $this->assertDatabaseHas('users', [
            'email' => 'comissionado@example.com',
            'commission_id' => $this->commission->id,
        ]);
    }

    private function makeOpening(int $managerId): AccountOpening
    {
        return AccountOpening::query()->create([
            'uuid' => (string) Str::uuid(),
            'resume_token_hash' => hash('sha256', Str::random(64)),
            'company_id' => $this->company->id,
            'status' => AccountOpening::STATUS_PENDING,
            'current_step' => 6,
            'submitted_via' => AccountOpening::SUBMITTED_VIA_WHITELABEL,
            'manager_id' => $managerId,
            'full_name' => 'Cliente Teste '.uniqid(),
            'email' => 'cliente'.uniqid().'@example.com',
            'password' => 'SenhaForte@123',
            'cpf' => (string) random_int(10000000000, 19999999999),
            'document_type' => 'rg',
            'document_number' => '123456',
            'document_issuer' => 'SSP',
            'document_issuer_uf' => 'SP',
            'birth_date' => '1990-01-01',
            'phone' => '11988887777',
        ]);
    }
}
