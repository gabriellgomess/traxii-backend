<?php

namespace App\Services;

use App\Models\Company;

/**
 * Resolve a empresa (tenant) a partir do domínio onde a dist whitelabel
 * está instalada — mesma convenção do endpoint público de tema:
 * sem correspondência, devolve a primeira empresa ativa.
 */
class CompanyResolver
{
    public function resolveByDomain(?string $domain): ?Company
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('/^www\./', '', $domain);

        $company = null;

        if ($domain !== '') {
            $company = Company::query()
                ->where('is_active', true)
                ->where('domain', $domain)
                ->first();
        }

        return $company ?? Company::query()->where('is_active', true)->orderBy('id')->first();
    }
}
