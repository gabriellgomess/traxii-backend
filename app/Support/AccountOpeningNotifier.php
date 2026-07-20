<?php

namespace App\Support;

use App\Mail\AccountOpeningApprovedMail;
use App\Mail\AccountOpeningPendencyMail;
use App\Mail\AccountOpeningRejectedMail;
use App\Models\AccountOpening;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notificações por e-mail ao proponente da abertura de conta.
 *
 * Desligadas por padrão: só enviam com MAIL_NOTIFICATIONS_ENABLED=true
 * (aba Environment do Easypanel) e credenciais MAIL_* configuradas.
 * Falha de envio nunca derruba a operação — apenas registra no log.
 */
class AccountOpeningNotifier
{
    public function approved(AccountOpening $opening): void
    {
        $this->send($opening, new AccountOpeningApprovedMail($opening));
    }

    /** Mensagem padrão — o motivo interno da reprovação nunca é enviado. */
    public function rejected(AccountOpening $opening): void
    {
        $this->send($opening, new AccountOpeningRejectedMail($opening));
    }

    /**
     * @param  list<string>  $items
     */
    public function pendency(
        AccountOpening $opening,
        string $message,
        array $items,
        ?string $resolutionUrl,
    ): void {
        $this->send(
            $opening,
            new AccountOpeningPendencyMail($opening, $message, $items, $resolutionUrl),
        );
    }

    private function send(AccountOpening $opening, Mailable $mail): void
    {
        if (! config('services.notifications.enabled')) {
            Log::info('E-mail de abertura de conta não enviado (notificações desativadas).', [
                'opening' => $opening->uuid,
                'mailable' => $mail::class,
            ]);

            return;
        }

        try {
            Mail::to($opening->email)->send($mail);
        } catch (Throwable $e) {
            Log::error('Falha ao enviar e-mail de abertura de conta.', [
                'opening' => $opening->uuid,
                'mailable' => $mail::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
