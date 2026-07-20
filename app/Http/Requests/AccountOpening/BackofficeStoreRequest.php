<?php

namespace App\Http\Requests\AccountOpening;

use App\Support\BrazilianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro manual de cliente pelo backoffice (app Gestor).
 * super_admin escolhe a empresa; usuário de empresa fica restrito à própria
 * (regra aplicada no controller). Documentos são opcionais neste fluxo.
 */
class BackofficeStoreRequest extends FormRequest
{
    use PersonalDataRules;

    private const FILE_RULES = [
        'file',
        'mimes:jpg,jpeg,png,webp,pdf',
        'max:10240',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],

            ...$this->personalDataRules(),

            'zip_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:150'],
            'number' => ['required', 'string', 'max:10'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', Rule::in(BrazilianStates::UFS)],

            'document_front' => ['sometimes', ...self::FILE_RULES],
            'document_back' => ['sometimes', ...self::FILE_RULES],
            'address_proof' => ['sometimes', ...self::FILE_RULES],
            'selfie' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->personalDataMessages(),
            'company_id.exists' => 'Empresa inválida.',
            'zip_code.required' => 'Informe o CEP.',
            'zip_code.regex' => 'CEP inválido (use 00000-000).',
            'street.required' => 'Informe o logradouro.',
            'number.required' => 'Informe o número.',
            'neighborhood.required' => 'Informe o bairro.',
            'city.required' => 'Informe a cidade.',
            'state.required' => 'Informe o estado.',
            'state.in' => 'Estado inválido.',
            '*.mimes' => 'Arquivo inválido (use JPG, PNG, WEBP ou PDF).',
            '*.max' => 'Arquivo muito grande (máx. 10 MB).',
        ];
    }
}
