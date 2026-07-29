<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validação matemática de CNPJ (dígitos verificadores). */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid(preg_replace('/\D/', '', (string) $value))) {
            $fail('O CNPJ informado é inválido.');
        }
    }

    public static function isValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $position) {
            $weight = $position === 12 ? 5 : 6;
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += ((int) $cnpj[$i]) * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $cnpj[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
