<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_opening_id', 'user_id', 'event', 'payload', 'ip', 'created_at'])]
class AccountOpeningEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_PERSONAL_DATA_UPDATED = 'personal_data_updated';

    public const EVENT_ADDRESS_UPDATED = 'address_updated';

    public const EVENT_DOCUMENTS_UPLOADED = 'documents_uploaded';

    public const EVENT_LIVENESS_COMPLETED = 'liveness_completed';

    public const EVENT_SELFIE_CAPTURED = 'selfie_captured';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_PENDENCY_CREATED = 'pendency_created';

    public const EVENT_PENDENCY_RESOLVED = 'pendency_resolved';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function accountOpening(): BelongsTo
    {
        return $this->belongsTo(AccountOpening::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
