<?php

namespace App\Mail;

use App\Models\AccountOpening;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Reprovação — o cliente recebe apenas a mensagem padrão.
 * O motivo real fica registrado internamente (rejection_reason/auditoria).
 */
class AccountOpeningRejectedMail extends Mailable
{
    public function __construct(public readonly AccountOpening $opening)
    {
        $this->opening->loadMissing('company');
    }

    public function envelope(): Envelope
    {
        $company = $this->opening->company?->name ?? 'Sua instituição';

        return new Envelope(subject: "{$company} · Sobre a sua solicitação de conta");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-opening.rejected',
            with: [
                'opening' => $this->opening,
                'company' => $this->opening->company,
            ],
        );
    }
}
