<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'company_id', 'parent_manager_id', 'commission_id',
    'document', 'document_type', 'phone', 'zip_code', 'street', 'number', 'complement',
    'neighborhood', 'city', 'state', 'latitude', 'longitude', 'referral_code',
    'must_change_password', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Percapital: administra a plataforma inteira. */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    /** Percapital: analisa e decide propostas de qualquer whitelabel. */
    public const ROLE_ANALYST = 'analyst';

    /** Whitelabel: administra a própria empresa e seus gerentes. */
    public const ROLE_COMPANY_ADMIN = 'company_admin';

    /** Whitelabel: gerente comercial, dono de indicações (link/QR). */
    public const ROLE_COMPANY_MANAGER = 'company_manager';

    /** @deprecated mantido por compatibilidade com registros antigos */
    public const ROLE_COMPANY_OPERATOR = 'company_operator';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'latitude' => 'float',
            'longitude' => 'float',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Propostas indicadas por este gerente. */
    public function referredOpenings(): HasMany
    {
        return $this->hasMany(AccountOpening::class, 'manager_id');
    }

    /** Gerente "acima" na hierarquia, quando este usuário é um subgerente. */
    public function parentManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_manager_id');
    }

    /** Subgerentes vinculados a este gerente. */
    public function subManagers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_manager_id');
    }

    /** Comissão vinculada a este gerente. */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /* -----------------------------------------------------------------
     | Papéis e permissões
     |------------------------------------------------------------------
     */

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isAnalyst(): bool
    {
        return $this->role === self::ROLE_ANALYST;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === self::ROLE_COMPANY_ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_COMPANY_MANAGER;
    }

    /** Gerente vinculado a outro gerente acima na hierarquia. */
    public function isSubManager(): bool
    {
        return $this->isManager() && $this->parent_manager_id !== null;
    }

    /**
     * IDs de gerente que este usuário pode enxerga ao filtrar contas/mapa:
     * um gerente-topo vê a si mesmo e seus subgerentes; um subgerente só
     * vê a si mesmo (hierarquia de 2 níveis, sem subgerente de subgerente).
     *
     * @return array<int>
     */
    public function visibleManagerIds(): array
    {
        if ($this->isSubManager()) {
            return [$this->id];
        }

        return [$this->id, ...$this->subManagers()->pluck('id')->all()];
    }

    /**
     * Enxerga dados de todas as empresas (Percapital).
     *
     * Não usar o nome `hasGlobalScope`: o Eloquent já define esse método
     * como estático (trait HasGlobalScopes) e redeclará-lo quebra o model.
     */
    public function seesAllCompanies(): bool
    {
        return $this->isSuperAdmin() || $this->isAnalyst();
    }

    /** Pode decidir propostas (aprovar, reprovar, pendência, excluir). */
    public function canReviewOpenings(): bool
    {
        return $this->isSuperAdmin() || $this->isAnalyst();
    }

    /** Gera um código de indicação único e curto para o gerente. */
    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
