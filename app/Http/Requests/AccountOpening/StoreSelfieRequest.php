<?php

namespace App\Http\Requests\AccountOpening;

use Illuminate\Foundation\Http\FormRequest;

/** Etapa 5 — selfie capturada pela câmera do dispositivo. */
class StoreSelfieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selfie' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120', // 5 MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'selfie.required' => 'Envie a selfie.',
            'selfie.image' => 'A selfie deve ser uma imagem.',
            'selfie.mimes' => 'Formato não permitido (use JPG, PNG ou WEBP).',
            'selfie.mimetypes' => 'Formato não permitido (use JPG, PNG ou WEBP).',
            'selfie.max' => 'A selfie deve ter no máximo 5 MB.',
        ];
    }
}
