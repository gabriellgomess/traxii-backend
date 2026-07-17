<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Exige nome e sobrenome (ao menos duas palavras com 2+ letras). */
class FullName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $words = preg_split('/\s+/', trim((string) $value)) ?: [];
        $meaningful = array_filter(
            $words,
            fn (string $word): bool => mb_strlen(preg_replace('/[^\p{L}]/u', '', $word)) >= 2,
        );

        if (count($meaningful) < 2) {
            $fail('Informe seu nome completo (nome e sobrenome).');
        }
    }
}
