<?php

namespace App\Services;

use App\Models\AccountOpening;
use App\Models\AccountOpeningDocument;
use App\Models\AccountOpeningEvent;
use App\Models\AccountOpeningPendency;
use App\Models\User;
use App\Repositories\AccountOpeningRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Regras de negócio do fluxo público de abertura de conta PF (wizard).
 * Controllers apenas delegam para cá; queries ficam no Repository.
 */
class AccountOpeningService
{
    /** Disco privado — arquivos nunca ficam acessíveis por URL pública. */
    private const STORAGE_DISK = 'local';

    public function __construct(
        private readonly AccountOpeningRepository $repository,
        private readonly CompanyResolver $companyResolver,
    ) {}

    /* -----------------------------------------------------------------
     | Etapa 1 — criação do rascunho com os dados pessoais
     |------------------------------------------------------------------
     */

    /**
     * @return array{opening: AccountOpening, resume_token: string}
     */
    public function start(array $data, ?string $domain, ?string $ip): array
    {
        $company = $this->companyResolver->resolveByDomain($domain);

        if (! $company) {
            throw ValidationException::withMessages([
                'domain' => ['Nenhuma instituição disponível para abertura de conta.'],
            ]);
        }

        $data = $this->normalizePersonalData($data);
        $this->ensureUniquePersonalData($company->id, $data);

        $resumeToken = Str::random(64);

        $opening = DB::transaction(function () use ($company, $data, $resumeToken, $ip) {
            $opening = $this->repository->create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'resume_token_hash' => hash('sha256', $resumeToken),
                'company_id' => $company->id,
                'status' => AccountOpening::STATUS_DRAFT,
                'current_step' => 2,
                'submitted_via' => AccountOpening::SUBMITTED_VIA_WHITELABEL,
            ]);

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_CREATED, [
                'company_id' => $company->id,
            ], ip: $ip);

            return $opening;
        });

        return ['opening' => $opening, 'resume_token' => $resumeToken];
    }

    /* -----------------------------------------------------------------
     | Cadastro manual pelo backoffice (app Gestor)
     |------------------------------------------------------------------
     */

    /**
     * Cria a proposta completa (dados + endereço + documentos opcionais) em
     * nome de um operador autenticado, já enviada para análise.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function createFromBackoffice(
        array $data,
        int $companyId,
        array $files,
        User $creator,
        ?string $ip,
    ): AccountOpening {
        $data = $this->normalizePersonalData($data);
        $this->ensureUniquePersonalData($companyId, $data);

        return DB::transaction(function () use ($data, $companyId, $files, $creator, $ip) {
            $opening = $this->repository->create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'resume_token_hash' => hash('sha256', Str::random(64)),
                'company_id' => $companyId,
                'status' => AccountOpening::STATUS_PENDING,
                'current_step' => AccountOpening::TOTAL_STEPS,
                'submitted_via' => AccountOpening::SUBMITTED_VIA_BACKOFFICE,
                'created_by' => $creator->id,
                'submitted_at' => now(),
            ]);

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_CREATED, [
                'company_id' => $companyId,
                'via' => AccountOpening::SUBMITTED_VIA_BACKOFFICE,
            ], userId: $creator->id, ip: $ip);

            foreach ($files as $type => $file) {
                $this->storeDocumentFile($opening, $type, $file);
            }

            if ($files !== []) {
                $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_DOCUMENTS_UPLOADED, [
                    'types' => array_keys($files),
                ], userId: $creator->id, ip: $ip);
            }

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_SUBMITTED, [
                'from_status' => AccountOpening::STATUS_DRAFT,
                'to_status' => AccountOpening::STATUS_PENDING,
            ], userId: $creator->id, ip: $ip);

            return $opening->load('documents');
        });
    }

    /* -----------------------------------------------------------------
     | Resolução de pendência pelo cliente (link público com token)
     |------------------------------------------------------------------
     */

    /** Localiza a pendência aberta validando o token do link (timing-safe). */
    public function findOpenPendencyByToken(AccountOpening $opening, string $plainToken): AccountOpeningPendency
    {
        $pendency = $opening->openPendency();

        if (
            ! $pendency
            || $plainToken === ''
            || ! hash_equals($pendency->token_hash, hash('sha256', $plainToken))
        ) {
            abort(404, 'Pendência não encontrada ou já resolvida.');
        }

        return $pendency;
    }

    /**
     * Cliente reenvia os itens solicitados; cadastro volta para a análise.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function resolvePendency(
        AccountOpening $opening,
        AccountOpeningPendency $pendency,
        array $files,
        ?string $ip,
    ): AccountOpening {
        if ($opening->status !== AccountOpening::STATUS_PENDING_CUSTOMER) {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitação não está aguardando pendência.'],
            ]);
        }

        $missing = array_diff($pendency->requested_items ?? [], array_keys($files));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'files' => ['Envie todos os itens solicitados para concluir.'],
            ]);
        }

        return DB::transaction(function () use ($opening, $pendency, $files, $ip) {
            foreach ($files as $type => $file) {
                $this->storeDocumentFile($opening, $type, $file);
            }

            $pendency->update([
                'status' => AccountOpeningPendency::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);

            $opening = $this->repository->update($opening, [
                'status' => AccountOpening::STATUS_IN_ANALYSIS,
            ]);

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_DOCUMENTS_UPLOADED, [
                'types' => array_keys($files),
                'via' => 'pendency',
            ], ip: $ip);

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_PENDENCY_RESOLVED, [
                'items' => array_keys($files),
                'from_status' => AccountOpening::STATUS_PENDING_CUSTOMER,
                'to_status' => AccountOpening::STATUS_IN_ANALYSIS,
            ], ip: $ip);

            return $opening;
        });
    }

    public function updatePersonalData(AccountOpening $opening, array $data, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);

        $data = $this->normalizePersonalData($data);
        $this->ensureUniquePersonalData($opening->company_id, $data, $opening->id);

        $opening = $this->repository->update($opening, $data);
        $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_PERSONAL_DATA_UPDATED, ip: $ip);

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Etapa 2 — endereço
     |------------------------------------------------------------------
     */

    public function updateAddress(AccountOpening $opening, array $data, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);

        $data['zip_code'] = preg_replace('/\D/', '', $data['zip_code']);
        $data['state'] = strtoupper($data['state']);

        $opening = $this->repository->update($opening, [
            ...$data,
            'current_step' => max($opening->current_step, 3),
        ]);
        $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_ADDRESS_UPDATED, ip: $ip);

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Etapa 3 — documentos (frente, verso e comprovante de residência)
     |------------------------------------------------------------------
     */

    /**
     * @param  array<string, UploadedFile>  $files  chaves: document_front|document_back|address_proof
     */
    public function storeDocuments(AccountOpening $opening, array $files, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);

        foreach ($files as $type => $file) {
            $this->storeDocumentFile($opening, $type, $file);
        }

        $opening->load('documents');

        $hasAllDocuments = collect($opening->requiredDocumentTypes())
            ->every(fn (string $type) => $opening->hasDocument($type));

        if ($hasAllDocuments) {
            $opening = $this->repository->update($opening, [
                'current_step' => max($opening->current_step, 4),
            ]);
        }

        $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_DOCUMENTS_UPLOADED, [
            'types' => array_keys($files),
        ], ip: $ip);

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Etapa 4 — prova de vida (liveness)
     |------------------------------------------------------------------
     */

    public function completeLiveness(AccountOpening $opening, array $challenges, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);

        $opening = $this->repository->update($opening, [
            'liveness_completed_at' => now(),
            'liveness_challenges' => $challenges,
            'current_step' => max($opening->current_step, 5),
        ]);

        $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_LIVENESS_COMPLETED, [
            'challenges' => $challenges,
        ], ip: $ip);

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Etapa 5 — selfie
     |------------------------------------------------------------------
     */

    public function storeSelfie(AccountOpening $opening, UploadedFile $file, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);

        if (! $opening->liveness_completed_at) {
            throw ValidationException::withMessages([
                'selfie' => ['Conclua a prova de vida antes de enviar a selfie.'],
            ]);
        }

        $this->storeDocumentFile($opening, AccountOpeningDocument::TYPE_SELFIE, $file);

        $opening = $this->repository->update($opening, [
            'current_step' => max($opening->current_step, 6),
        ]);

        $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_SELFIE_CAPTURED, ip: $ip);

        return $opening->load('documents');
    }

    /* -----------------------------------------------------------------
     | Etapa 6 — aceites e envio para análise
     |------------------------------------------------------------------
     */

    public function submit(AccountOpening $opening, ?string $ip): AccountOpening
    {
        $this->assertEditable($opening);
        $this->assertReadyToSubmit($opening);

        $now = now();

        $opening = DB::transaction(function () use ($opening, $now, $ip) {
            $opening = $this->repository->update($opening, [
                'status' => AccountOpening::STATUS_PENDING,
                'terms_accepted_at' => $now,
                'privacy_accepted_at' => $now,
                'truthfulness_accepted_at' => $now,
                'acceptance_ip' => $ip,
                'submitted_at' => $now,
            ]);

            $this->repository->recordEvent($opening, AccountOpeningEvent::EVENT_SUBMITTED, [
                'from_status' => AccountOpening::STATUS_DRAFT,
                'to_status' => AccountOpening::STATUS_PENDING,
            ], ip: $ip);

            return $opening;
        });

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Consulta de progresso (retomada do wizard)
     |------------------------------------------------------------------
     */

    /** Payload público do estado do cadastro — nunca expõe senha, token ou paths. */
    public function progress(AccountOpening $opening): array
    {
        $opening->loadMissing('documents');

        $documents = collect([
            ...AccountOpeningDocument::REQUIRED_UPLOAD_TYPES,
            AccountOpeningDocument::TYPE_SELFIE,
        ])->mapWithKeys(function (string $type) use ($opening) {
            $document = $opening->documents->firstWhere('type', $type);

            return [$type => [
                'uploaded' => $document !== null,
                'original_name' => $document?->original_name,
                'size' => $document?->size,
            ]];
        });

        return [
            'uuid' => $opening->uuid,
            'status' => $opening->status,
            'current_step' => $opening->current_step,
            'total_steps' => AccountOpening::TOTAL_STEPS,
            'personal_data' => [
                'full_name' => $opening->full_name,
                'email' => $opening->email,
                'cpf' => $opening->cpf,
                'document_type' => $opening->document_type,
                'document_number' => $opening->document_number,
                'document_issuer' => $opening->document_issuer,
                'document_issuer_uf' => $opening->document_issuer_uf,
                'birth_date' => $opening->birth_date?->format('Y-m-d'),
                'phone' => $opening->phone,
            ],
            'address' => [
                'zip_code' => $opening->zip_code,
                'street' => $opening->street,
                'number' => $opening->number,
                'complement' => $opening->complement,
                'neighborhood' => $opening->neighborhood,
                'city' => $opening->city,
                'state' => $opening->state,
            ],
            'documents' => $documents,
            'liveness_completed' => $opening->liveness_completed_at !== null,
            'acceptances' => [
                'terms' => $opening->terms_accepted_at !== null,
                'privacy' => $opening->privacy_accepted_at !== null,
                'truthfulness' => $opening->truthfulness_accepted_at !== null,
            ],
            'submitted_at' => $opening->submitted_at?->toIso8601String(),
        ];
    }

    /** Comparação em tempo constante do token de retomada. */
    public function tokenMatches(AccountOpening $opening, string $plainToken): bool
    {
        return hash_equals($opening->resume_token_hash, hash('sha256', $plainToken));
    }

    /* -----------------------------------------------------------------
     | Internos
     |------------------------------------------------------------------
     */

    private function normalizePersonalData(array $data): array
    {
        $data['cpf'] = preg_replace('/\D/', '', $data['cpf']);
        $data['phone'] = preg_replace('/\D/', '', $data['phone']);
        $data['email'] = strtolower(trim($data['email']));
        $data['full_name'] = trim(preg_replace('/\s+/', ' ', $data['full_name']));
        $data['document_type'] = strtolower($data['document_type']);
        $data['document_issuer_uf'] = strtoupper($data['document_issuer_uf']);

        return $data;
    }

    /**
     * Duplicidade por tenant: CPF, e-mail e celular não podem ter outra
     * proposta ativa na mesma empresa; e-mail também não pode colidir com
     * um usuário do sistema (users.email é único global).
     */
    private function ensureUniquePersonalData(int $companyId, array $data, ?int $ignoreId = null): void
    {
        $errors = [];

        if ($this->repository->activeDuplicateExists($companyId, 'cpf', $data['cpf'], $ignoreId)) {
            $errors['cpf'] = ['Já existe uma solicitação de abertura de conta para este CPF.'];
        }

        if ($this->repository->activeDuplicateExists($companyId, 'email', $data['email'], $ignoreId)
            || $this->repository->userEmailExists($data['email'])) {
            $errors['email'] = ['Este e-mail já está em uso.'];
        }

        if ($this->repository->activeDuplicateExists($companyId, 'phone', $data['phone'], $ignoreId)) {
            $errors['phone'] = ['Este celular já está em uso em outra solicitação.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Grava o arquivo no disco privado com nome aleatório (hash) e extensão
     * derivada do MIME real detectado — nunca do nome enviado pelo cliente.
     */
    private function storeDocumentFile(AccountOpening $opening, string $type, UploadedFile $file): void
    {
        $previous = $this->repository->findDocument($opening, $type);

        $path = $file->store("account-openings/{$opening->uuid}", self::STORAGE_DISK);

        if ($path === false) {
            throw ValidationException::withMessages([
                $type => ['Não foi possível armazenar o arquivo. Tente novamente.'],
            ]);
        }

        $this->repository->upsertDocument($opening, $type, [
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 150),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ]);

        // Substituição: remove o arquivo anterior do disco após gravar o novo
        if ($previous && $previous->path !== $path) {
            Storage::disk(self::STORAGE_DISK)->delete($previous->path);
        }
    }

    private function assertEditable(AccountOpening $opening): void
    {
        if (! $opening->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Este cadastro já foi enviado para análise e não pode ser alterado.'],
            ]);
        }
    }

    private function assertReadyToSubmit(AccountOpening $opening): void
    {
        $opening->loadMissing('documents');
        $errors = [];

        if (! $opening->zip_code || ! $opening->street || ! $opening->city || ! $opening->state) {
            $errors['address'] = ['Preencha o endereço completo antes de finalizar.'];
        }

        foreach ($opening->requiredDocumentTypes() as $type) {
            if (! $opening->hasDocument($type)) {
                $errors[$type] = ['Envie todos os documentos obrigatórios antes de finalizar.'];
                break;
            }
        }

        if (! $opening->liveness_completed_at) {
            $errors['liveness'] = ['Conclua a prova de vida antes de finalizar.'];
        }

        if (! $opening->hasDocument(AccountOpeningDocument::TYPE_SELFIE)) {
            $errors['selfie'] = ['Envie a selfie antes de finalizar.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
