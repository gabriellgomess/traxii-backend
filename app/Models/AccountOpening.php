<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'resume_token_hash', 'company_id', 'status', 'current_step', 'submitted_via',
    'created_by', 'manager_id', 'full_name', 'email', 'password', 'cpf', 'document_type',
    'document_number', 'document_issuer', 'document_issuer_uf', 'birth_date', 'phone',
    'zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state',
    'latitude', 'longitude',
    'liveness_completed_at', 'liveness_challenges', 'terms_accepted_at',
    'privacy_accepted_at', 'truthfulness_accepted_at', 'acceptance_ip',
    'submitted_at', 'reviewed_at', 'reviewed_by', 'rejection_reason',
])]
#[Hidden(['password', 'resume_token_hash'])]
class AccountOpening extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_ANALYSIS = 'in_analysis';

    public const STATUS_PENDING_CUSTOMER = 'pending_customer';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    // Estados de conta (pós-aprovação)
    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const DOCUMENT_TYPE_RG = 'rg';

    public const DOCUMENT_TYPE_CNH = 'cnh';

    public const SUBMITTED_VIA_WHITELABEL = 'whitelabel';

    public const SUBMITTED_VIA_BACKOFFICE = 'backoffice';

    public const TOTAL_STEPS = 6;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'birth_date' => 'date:Y-m-d',
            'liveness_challenges' => 'array',
            'liveness_completed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'truthfulness_accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccountOpeningDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccountOpeningEvent::class);
    }

    public function pendencies(): HasMany
    {
        return $this->hasMany(AccountOpeningPendency::class);
    }

    public function openPendency(): ?AccountOpeningPendency
    {
        return $this->pendencies()
            ->where('status', AccountOpeningPendency::STATUS_OPEN)
            ->latest('id')
            ->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Gerente comercial que indicou o cliente (link/QR). */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** O proponente só pode alterar dados enquanto o cadastro não foi enviado. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function hasDocument(string $type): bool
    {
        return $this->documents->contains(fn (AccountOpeningDocument $doc) => $doc->type === $type);
    }

    /**
     * Documentos obrigatórios na etapa 3, conforme o tipo escolhido.
     * A CNH digital (a mais comum hoje) já traz frente e verso no mesmo
     * PDF, enviado no slot "frente" — o verso fica opcional.
     */
    public function requiredDocumentTypes(): array
    {
        if ($this->document_type === self::DOCUMENT_TYPE_CNH) {
            return [
                AccountOpeningDocument::TYPE_DOCUMENT_FRONT,
                AccountOpeningDocument::TYPE_ADDRESS_PROOF,
            ];
        }

        return AccountOpeningDocument::REQUIRED_UPLOAD_TYPES;
    }

    public function hasAllAcceptances(): bool
    {
        return $this->terms_accepted_at !== null
            && $this->privacy_accepted_at !== null
            && $this->truthfulness_accepted_at !== null;
    }
}
