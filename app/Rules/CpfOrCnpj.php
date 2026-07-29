<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Aceita CPF (11 dígitos) ou CNPJ (14 dígitos), validando os verificadores. */
class CpfOrCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        $valid = match (strlen($digits)) {
            11 => Cpf::isValid($digits),
            14 => Cnpj::isValid($digits),
            default => false,
        };

        if (! $valid) {
            $fail('Informe um CPF ou CNPJ válido.');
        }
    }
}
