<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_opening_id', 'requested_items', 'message', 'token_hash',
    'status', 'created_by', 'resolved_at',
])]
#[Hidden(['token_hash'])]
class AccountOpeningPendency extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    /** Itens que podem ser solicitados numa pendência. */
    public const REQUESTABLE_ITEMS = [
        AccountOpeningDocument::TYPE_DOCUMENT_FRONT,
        AccountOpeningDocument::TYPE_DOCUMENT_BACK,
        AccountOpeningDocument::TYPE_ADDRESS_PROOF,
        AccountOpeningDocument::TYPE_SELFIE,
    ];

    protected function casts(): array
    {
        return [
            'requested_items' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function accountOpening(): BelongsTo
    {
        return $this->belongsTo(AccountOpening::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Link de resolução no whitelabel da empresa (token em claro só na criação). */
    public function resolutionUrl(string $plainToken): ?string
    {
        $domain = $this->accountOpening?->company?->domain;

        if (! $domain) {
            return null;
        }

        return sprintf(
            'https://%s/pendencia/%s?t=%s',
            $domain,
            $this->accountOpening->uuid,
            $plainToken,
        );
    }
}
