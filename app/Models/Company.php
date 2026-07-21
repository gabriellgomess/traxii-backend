<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'domain', 'primary_color', 'secondary_color', 'logo_path', 'banner_path', 'is_active'])]
class Company extends Model
{
    protected $appends = ['logo_url', 'banner_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accountOpenings(): HasMany
    {
        return $this->hasMany(AccountOpening::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->logo_path
                ? Storage::disk('public')->url($this->logo_path)
                : null,
        );
    }

    protected function bannerUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->banner_path
                ? Storage::disk('public')->url($this->banner_path)
                : null,
        );
    }

    /** Payload público do tema whitelabel (sem dados sensíveis). */
    public function toTheme(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
        ];
    }
}
