<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tela experimental (categoria de contas) — remova junto com a migration,
// o controller e a rota se a tela não for mantida.
#[Fillable(['company_id', 'name', 'min_movement', 'max_movement'])]
class AccountCategory extends Model
{
    protected function casts(): array
    {
        return [
            'min_movement' => 'decimal:2',
            'max_movement' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
