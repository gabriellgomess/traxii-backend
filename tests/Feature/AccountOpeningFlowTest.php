<?php

namespace Tests\Feature;

use App\Models\AccountOpening;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountOpeningFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::query()->create([
            'name' => 'NovaBank',
            'domain' => 'novabank.com.br',
            'primary_color' => '#1437C9',
            'secondary_color' => '#FF7A1A',
            'is_active' => true,
        ]);
    }

    private function validPersonalData(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Maria da Silva',
            'email' => 'maria@example.com',
            'password' => 'SenhaForte@123',
            'password_confirmation' => 'SenhaForte@123',
            'cpf' => '529.982.247-25',
            'document_type' => 'rg',
            'document_number' => '12.345.678-9',
            'document_issuer' => 'SSP',
            'document_issuer_uf' => 'SP',
            'birth_date' => '1990-05-10',
            'phone' => '(11) 98888-7777',
            'domain' => 'novabank.com.br',
        ], $overrides);
    }

    private function createDraft(): array
    {
        $response = $this->postJson('/api/public/account-openings', $this->validPersonalData());
        $response->assertCreated();

        $data = $response->json('data');

        return [
            'uuid' => $data['uuid'],
            'headers' => ['X-Opening-Token' => $data['resume_token']],
        ];
    }

    private function uploadAllDocuments(string $uuid, array $headers): void
    {
        // create() com MIME explícito: fake()->image() exigiria a extensão GD
        $this->postJson("/api/public/account-openings/{$uuid}/documents", [
            'document_front' => UploadedFile::fake()->create('frente.jpg', 500, 'image/jpeg'),
            'document_back' => UploadedFile::fake()->create('verso.jpg', 500, 'image/jpeg'),
            'address_proof' => UploadedFile::fake()->create('conta-luz.pdf', 500, 'application/pdf'),
        ], $headers)->assertOk();
    }

    /* ----------------------------------------------------------------
     | Etapa 1 — dados pessoais
     |-----------------------------------------------------------------
     */

    public function test_creates_draft_with_valid_personal_data(): void
    {
        $response = $this->postJson('/api/public/account-openings', $this->validPersonalData());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_step', 2)
            ->assertJsonPath('data.personal_data.cpf', '52998224725')
            ->assertJsonPath('data.personal_data.phone', '11988887777');

        $this->assertNotEmpty($response->json('data.resume_token'));
        $this->assertDatabaseHas('account_openings', [
            'cpf' => '52998224725',
            'company_id' => $this->company->id,
            'status' => AccountOpening::STATUS_DRAFT,
        ]);
        $this->assertDatabaseHas('account_opening_events', ['event' => 'created']);

        // Senha nunca volta na resposta e é armazenada com hash
        $this->assertArrayNotHasKey('password', $response->json('data.personal_data'));
        $opening = AccountOpening::query()->first();
        $this->assertNotSame('SenhaForte@123', $opening->password);
    }

    public function test_rejects_invalid_cpf(): void
    {
        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'cpf' => '111.111.111-11',
        ]))->assertUnprocessable()->assertJsonValidationErrors('cpf');
    }

    public function test_rejects_duplicate_cpf_for_same_company(): void
    {
        $this->createDraft();

        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'email' => 'outra@example.com',
            'phone' => '(11) 97777-6666',
        ]))->assertUnprocessable()->assertJsonValidationErrors('cpf');
    }

    public function test_rejects_email_already_used_by_system_user(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@traxiinvest.com',
            'password' => 'password',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'email' => 'admin@traxiinvest.com',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_rejects_minor_applicant(): void
    {
        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'birth_date' => now()->subYears(17)->format('Y-m-d'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('birth_date');
    }

    public function test_rejects_weak_password(): void
    {
        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'password' => 'senhafraca',
            'password_confirmation' => 'senhafraca',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_rejects_invalid_ddd_and_single_name(): void
    {
        $this->postJson('/api/public/account-openings', $this->validPersonalData([
            'full_name' => 'Maria',
            'phone' => '(20) 98888-7777',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'phone']);
    }

    /* ----------------------------------------------------------------
     | Token de retomada
     |-----------------------------------------------------------------
     */

    public function test_wizard_routes_return_404_without_valid_token(): void
    {
        ['uuid' => $uuid] = $this->createDraft();

        $this->getJson("/api/public/account-openings/{$uuid}")->assertNotFound();

        $this->getJson("/api/public/account-openings/{$uuid}", [
            'X-Opening-Token' => str_repeat('x', 64),
        ])->assertNotFound();
    }

    public function test_resumes_wizard_with_valid_token(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->getJson("/api/public/account-openings/{$uuid}", $headers)
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.current_step', 2);
    }

    /* ----------------------------------------------------------------
     | Etapa 2 — endereço
     |-----------------------------------------------------------------
     */

    public function test_updates_address(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->putJson("/api/public/account-openings/{$uuid}/address", [
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Apto 12',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.address.zip_code', '01310100')
            ->assertJsonPath('data.current_step', 3);
    }

    public function test_rejects_invalid_zip_code(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->putJson("/api/public/account-openings/{$uuid}/address", [
            'zip_code' => '123',
            'street' => 'Rua A',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('zip_code');
    }

    /* ----------------------------------------------------------------
     | Etapa 3 — documentos
     |-----------------------------------------------------------------
     */

    public function test_uploads_documents_to_private_disk(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->uploadAllDocuments($uuid, $headers);

        $response = $this->getJson("/api/public/account-openings/{$uuid}", $headers);
        $response->assertOk()
            ->assertJsonPath('data.documents.document_front.uploaded', true)
            ->assertJsonPath('data.documents.document_back.uploaded', true)
            ->assertJsonPath('data.documents.address_proof.uploaded', true)
            ->assertJsonPath('data.current_step', 4);

        // Arquivos no disco privado, com nome aleatório (não o original)
        $files = Storage::disk('local')->allFiles("account-openings/{$uuid}");
        $this->assertCount(3, $files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('frente', $file);
        }

        // Resposta não expõe o path de armazenamento
        $this->assertArrayNotHasKey('path', $response->json('data.documents.document_front'));
    }

    public function test_rejects_executable_disguised_as_image(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        // UploadedFile real (não fake): o MIME é detectado pelo conteúdo,
        // como acontece em produção — extensão .jpg não engana o sniffing
        $path = tempnam(sys_get_temp_dir(), 'mal');
        file_put_contents($path, "MZ\x90\x00\x03".str_repeat("\x00", 60).'este-conteudo-nao-e-imagem');
        $fake = new UploadedFile($path, 'malware.jpg', 'image/jpeg', null, true);

        $this->postJson("/api/public/account-openings/{$uuid}/documents", [
            'document_front' => $fake,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('document_front');
    }

    public function test_rejects_oversized_document(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->postJson("/api/public/account-openings/{$uuid}/documents", [
            'document_front' => UploadedFile::fake()->create('grande.pdf', 9000, 'application/pdf'),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('document_front');
    }

    /* ----------------------------------------------------------------
     | Etapas 4 e 5 — prova de vida e selfie
     |-----------------------------------------------------------------
     */

    public function test_completes_liveness_and_uploads_selfie(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->postJson("/api/public/account-openings/{$uuid}/liveness", [
            'challenges' => ['turnLeft', 'turnRight', 'moveCloser', 'nod'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.liveness_completed', true)
            ->assertJsonPath('data.current_step', 5);

        $this->postJson("/api/public/account-openings/{$uuid}/selfie", [
            'selfie' => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.documents.selfie.uploaded', true)
            ->assertJsonPath('data.current_step', 6);
    }

    public function test_rejects_selfie_before_liveness(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->postJson("/api/public/account-openings/{$uuid}/selfie", [
            'selfie' => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('selfie');
    }

    /* ----------------------------------------------------------------
     | Etapa 6 — aceites e envio
     |-----------------------------------------------------------------
     */

    private function completeAllSteps(): array
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->putJson("/api/public/account-openings/{$uuid}/address", [
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $headers)->assertOk();

        $this->uploadAllDocuments($uuid, $headers);

        $this->postJson("/api/public/account-openings/{$uuid}/liveness", [
            'challenges' => ['turnLeft', 'turnRight', 'moveCloser', 'nod'],
        ], $headers)->assertOk();

        $this->postJson("/api/public/account-openings/{$uuid}/selfie", [
            'selfie' => UploadedFile::fake()->create('selfie.jpg', 300, 'image/jpeg'),
        ], $headers)->assertOk();

        return ['uuid' => $uuid, 'headers' => $headers];
    }

    public function test_submit_requires_all_acceptances(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->completeAllSteps();

        $this->postJson("/api/public/account-openings/{$uuid}/submit", [
            'accept_terms' => true,
            'accept_privacy' => true,
            'accept_truthfulness' => false,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('accept_truthfulness');
    }

    public function test_submit_rejects_incomplete_registration(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->createDraft();

        $this->postJson("/api/public/account-openings/{$uuid}/submit", [
            'accept_terms' => true,
            'accept_privacy' => true,
            'accept_truthfulness' => true,
        ], $headers)->assertUnprocessable();
    }

    public function test_submits_complete_registration_and_locks_it(): void
    {
        ['uuid' => $uuid, 'headers' => $headers] = $this->completeAllSteps();

        $this->postJson("/api/public/account-openings/{$uuid}/submit", [
            'accept_terms' => true,
            'accept_privacy' => true,
            'accept_truthfulness' => true,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.acceptances.terms', true)
            ->assertJsonPath('data.acceptances.privacy', true)
            ->assertJsonPath('data.acceptances.truthfulness', true);

        $this->assertDatabaseHas('account_openings', ['uuid' => $uuid, 'status' => 'pending']);
        $this->assertDatabaseHas('account_opening_events', ['event' => 'submitted']);

        // Depois de enviado, o cadastro fica bloqueado para edição
        $this->putJson("/api/public/account-openings/{$uuid}/address", [
            'zip_code' => '01310-100',
            'street' => 'Outra Rua',
            'number' => '2',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
