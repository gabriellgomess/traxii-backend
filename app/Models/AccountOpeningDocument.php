<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_opening_id', 'type', 'path', 'original_name', 'mime_type', 'size'])]
#[Hidden(['path'])]
class AccountOpeningDocument extends Model
{
    public const TYPE_DOCUMENT_FRONT = 'document_front';

    public const TYPE_DOCUMENT_BACK = 'document_back';

    public const TYPE_ADDRESS_PROOF = 'address_proof';

    public const TYPE_SELFIE = 'selfie';

    /** Documentos exigidos na etapa 3 (a selfie é coletada na etapa 5). */
    public const REQUIRED_UPLOAD_TYPES = [
        self::TYPE_DOCUMENT_FRONT,
        self::TYPE_DOCUMENT_BACK,
        self::TYPE_ADDRESS_PROOF,
    ];

    public function accountOpening(): BelongsTo
    {
        return $this->belongsTo(AccountOpening::class);
    }
}
