<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;

/** Etapa 6 — aceites obrigatórios e envio do cadastro para análise. */
class SubmitAccountOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'accept_truthfulness' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'É necessário aceitar os Termos de Uso.',
            'accept_privacy.accepted' => 'É necessário aceitar a Política de Privacidade.',
            'accept_truthfulness.accepted' => 'É necessário declarar a veracidade das informações.',
        ];
    }
}
