<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;

/** Etapa 1 — cria o rascunho da abertura de conta (endpoint público). */
class StoreAccountOpeningRequest extends FormRequest
{
    use PersonalDataRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->personalDataRules(),
            // Domínio onde a dist whitelabel está instalada (resolve o tenant)
            'domain' => ['nullable', 'string', 'max:255'],
            // Código de indicação do gerente comercial (link/QR)
            'ref' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return $this->personalDataMessages();
    }
}
