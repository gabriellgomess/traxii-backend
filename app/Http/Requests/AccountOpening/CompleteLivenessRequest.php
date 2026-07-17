<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;

/** Etapa 4 — registro da conclusão da prova de vida (desafios cumpridos). */
class CompleteLivenessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenges' => ['required', 'array', 'min:1', 'max:10'],
            'challenges.*' => ['required', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'challenges.required' => 'Informe os desafios concluídos na prova de vida.',
        ];
    }
}
