<?php

namespace App\Http\Requests\AccountOpening;

use App\Rules\CellPhone;
use App\Rules\Cpf;
use App\Rules\FullName;
use App\Support\BrazilianStates;
use Illuminate\Validation\Rule;

/**
 * Regras da etapa 1 (dados pessoais), compartilhadas entre criação e edição.
 *
 * Sem campo de senha: o correntista não define senha no cadastro público.
 * Se/quando o cadastro for aprovado, uma senha provisória é gerada e
 * enviada por e-mail (fluxo de aprovação — Fase 4 do roadmap).
 */
trait PersonalDataRules
{
    protected function personalDataRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120', new FullName],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'cpf' => ['required', 'string', new Cpf],
            'document_type' => ['required', 'string', Rule::in(['rg', 'cnh'])],
            'document_number' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{3,20}$/'],
            'document_issuer' => ['required', 'string', 'max:20'],
            'document_issuer_uf' => ['required', 'string', Rule::in(BrazilianStates::UFS)],
            'birth_date' => [
                'required',
                'date_format:Y-m-d',
                'after:'.now()->subYears(120)->format('Y-m-d'),
                'before_or_equal:'.now()->subYears(18)->format('Y-m-d'),
            ],
            'phone' => ['required', 'string', new CellPhone],
        ];
    }

    protected function personalDataMessages(): array
    {
        return [
            'full_name.required' => 'Informe seu nome completo.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'cpf.required' => 'Informe seu CPF.',
            'document_type.required' => 'Selecione o tipo de documento.',
            'document_type.in' => 'Tipo de documento inválido (use RG ou CNH).',
            'document_number.required' => 'Informe o número do documento.',
            'document_number.regex' => 'Número de documento inválido.',
            'document_issuer.required' => 'Informe o órgão emissor.',
            'document_issuer_uf.required' => 'Informe a UF de emissão.',
            'document_issuer_uf.in' => 'UF de emissão inválida.',
            'birth_date.required' => 'Informe sua data de nascimento.',
            'birth_date.date_format' => 'Data de nascimento inválida.',
            'birth_date.after' => 'Data de nascimento inválida.',
            'birth_date.before_or_equal' => 'É preciso ter 18 anos ou mais para abrir a conta.',
            'phone.required' => 'Informe seu celular.',
        ];
    }
}
