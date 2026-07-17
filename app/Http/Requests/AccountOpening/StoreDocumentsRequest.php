<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Etapa 3 — upload dos documentos (frente, verso e comprovante de residência).
 * Aceita envio parcial (um arquivo por vez) ou os três de uma vez.
 */
class StoreDocumentsRequest extends FormRequest
{
    private const FILE_RULES = [
        'file',
        // `mimes`/`mimetypes` validam o conteúdo real do arquivo (sniffing),
        // bloqueando executáveis/scripts renomeados para .jpg/.pdf
        'mimes:jpg,jpeg,png,webp,pdf',
        'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
        'max:8192', // 8 MB
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_front' => ['sometimes', ...self::FILE_RULES],
            'document_back' => ['sometimes', ...self::FILE_RULES],
            'address_proof' => ['sometimes', ...self::FILE_RULES],
        ];
    }

    public function messages(): array
    {
        $messages = [];
        foreach (['document_front', 'document_back', 'address_proof'] as $field) {
            $messages["{$field}.mimes"] = 'Formato não permitido (use JPG, PNG, WEBP ou PDF).';
            $messages["{$field}.mimetypes"] = 'Formato não permitido (use JPG, PNG, WEBP ou PDF).';
            $messages["{$field}.max"] = 'O arquivo deve ter no máximo 8 MB.';
        }

        return $messages;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasFile('document_front')
                && ! $this->hasFile('document_back')
                && ! $this->hasFile('address_proof')) {
                $validator->errors()->add('documents', 'Envie ao menos um documento.');
            }
        });
    }
}
