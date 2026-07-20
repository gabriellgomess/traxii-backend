<?php

namespace App\Mail;

use App\Models\AccountOpening;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountOpeningPendencyMail extends Mailable
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public readonly AccountOpening $opening,
        public readonly string $pendencyMessage,
        public readonly array $items,
        public readonly ?string $resolutionUrl,
    ) {
        $this->opening->loadMissing('company');
    }

    public function envelope(): Envelope
    {
        $company = $this->opening->company?->name ?? 'Sua instituição';

        return new Envelope(subject: "{$company} · Pendência na sua abertura de conta");
    }

    public function content(): Content
    {
        $labels = [
            'document_front' => 'Documento de identificação (frente)',
            'document_back' => 'Documento de identificação (verso)',
            'address_proof' => 'Comprovante de endereço',
            'selfie' => 'Nova selfie',
        ];

        return new Content(
            view: 'emails.account-opening.pendency',
            with: [
                'opening' => $this->opening,
                'company' => $this->opening->company,
                'pendencyMessage' => $this->pendencyMessage,
                'itemLabels' => array_map(fn (string $i) => $labels[$i] ?? $i, $this->items),
                'resolutionUrl' => $this->resolutionUrl,
            ],
        );
    }
}
