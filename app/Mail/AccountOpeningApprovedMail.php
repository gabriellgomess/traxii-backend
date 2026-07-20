<?php

namespace App\Mail;

use App\Models\AccountOpening;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountOpeningApprovedMail extends Mailable
{
    public function __construct(public readonly AccountOpening $opening)
    {
        $this->opening->loadMissing('company');
    }

    public function envelope(): Envelope
    {
        $company = $this->opening->company?->name ?? 'Sua instituição';

        return new Envelope(subject: "{$company} · Sua conta foi aprovada!");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-opening.approved',
            with: [
                'opening' => $this->opening,
                'company' => $this->opening->company,
            ],
        );
    }
}
