<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;

/** Etapa 1 — edição dos dados pessoais de um rascunho existente. */
class UpdatePersonalDataRequest extends FormRequest
{
    use PersonalDataRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->personalDataRules();
    }

    public function messages(): array
    {
        return $this->personalDataMessages();
    }
}
