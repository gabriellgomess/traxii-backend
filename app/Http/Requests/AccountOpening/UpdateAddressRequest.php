<?php

namespace App\Http\Requests\AccountOpening;

use App\Support\BrazilianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Etapa 2 — endereço residencial. */
class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'zip_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:150'],
            'number' => ['required', 'string', 'max:10'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', Rule::in(BrazilianStates::UFS)],
        ];
    }

    public function messages(): array
    {
        return [
            'zip_code.required' => 'Informe o CEP.',
            'zip_code.regex' => 'CEP inválido (use 00000-000).',
            'street.required' => 'Informe o logradouro.',
            'number.required' => 'Informe o número.',
            'neighborhood.required' => 'Informe o bairro.',
            'city.required' => 'Informe a cidade.',
            'state.required' => 'Informe o estado.',
            'state.in' => 'Estado inválido.',
        ];
    }
}
