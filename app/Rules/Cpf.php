<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validação matemática de CPF (dígitos verificadores).
 *
 * Não existe API pública gratuita e confiável para consulta de situação
 * cadastral de CPF (a consulta oficial é o serviço pago do Serpro); quando
 * houver integração KYC, ela deve ser plugada na camada de Service.
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D/', '', (string) $value);

        if (! self::isValid($cpf)) {
            $fail('O CPF informado é inválido.');
        }
    }

    public static function isValid(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $length) {
            $sum = 0;
            foreach (str_split(substr($cpf, 0, $length)) as $index => $digit) {
                $sum += (int) $digit * (($length + 1) - $index);
            }
            $checkDigit = (10 * $sum) % 11 % 10;
            if ($checkDigit !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }
}
