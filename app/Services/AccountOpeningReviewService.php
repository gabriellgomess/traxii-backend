<?php

namespace App\Services;

use App\Models\AccountOpening;
use App\Models\AccountOpeningEvent;
use App\Models\AccountOpeningPendency;
use App\Models\User;
use App\Repositories\AccountOpeningRepository;
use App\Support\AccountOpeningNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Regras de negócio da revisão (backoffice do Gestor).
 *
 * Reprovação: fluxo terminal — motivo real fica interno (gestor/auditoria);
 * o cliente recebe apenas uma mensagem padrão.
 * Pendência: item específico a corrigir (ex.: selfie ruim) — o cliente recebe
 * a mensagem escrita pelo operador e um link seguro para reenviar os itens.
 */
class AccountOpeningReviewService
{
    public function __construct(
        private readonly AccountOpeningRepository $repository,
        private readonly AccountOpeningNotifier $notifier,
    ) {}

    public function startAnalysis(AccountOpening $opening, User $reviewer, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_PENDING],
            to: AccountOpening::STATUS_IN_ANALYSIS,
            ip: $ip,
        );
    }

    public function approve(AccountOpening $opening, User $reviewer, ?string $ip): AccountOpening
    {
        $opening = $this->transition(
            $opening,
            $reviewer,
            from: [
                AccountOpening::STATUS_PENDING,
                AccountOpening::STATUS_IN_ANALYSIS,
                AccountOpening::STATUS_PENDING_CUSTOMER,
            ],
            to: AccountOpening::STATUS_APPROVED,
            ip: $ip,
        );

        $this->notifier->approved($opening);

        return $opening;
    }

    public function reject(AccountOpening $opening, User $reviewer, string $internalReason, ?string $ip): AccountOpening
    {
        $opening = $this->transition(
            $opening,
            $reviewer,
            from: [
                AccountOpening::STATUS_PENDING,
                AccountOpening::STATUS_IN_ANALYSIS,
                AccountOpening::STATUS_PENDING_CUSTOMER,
            ],
            to: AccountOpening::STATUS_REJECTED,
            ip: $ip,
            extra: ['rejection_reason' => $internalReason],
        );

        // Fluxo terminal: o cliente recebe apenas a mensagem padrão,
        // nunca o motivo interno.
        $this->notifier->rejected($opening);

        return $opening;
    }

    /**
     * Registra uma pendência: itens a reenviar + mensagem ao cliente.
     * Gera o link de resolução — o token em claro só existe nesta resposta.
     *
     * @param  list<string>  $items
     * @return array{opening: AccountOpening, resolution_url: ?string}
     */
    public function createPendency(
        AccountOpening $opening,
        User $reviewer,
        array $items,
        string $message,
        ?string $ip,
    ): array {
        $plainToken = Str::random(64);
        $items = array_values(array_unique($items));

        [$opening, $pendency] = DB::transaction(function () use ($opening, $reviewer, $items, $message, $plainToken, $ip) {
            /** @var AccountOpeningPendency $pendency */
            $pendency = $opening->pendencies()->create([
                'requested_items' => $items,
                'message' => $message,
                'token_hash' => hash('sha256', $plainToken),
                'status' => AccountOpeningPendency::STATUS_OPEN,
                'created_by' => $reviewer->id,
            ]);

            $opening = $this->transition(
                $opening,
                $reviewer,
                from: [AccountOpening::STATUS_PENDING, AccountOpening::STATUS_IN_ANALYSIS],
                to: AccountOpening::STATUS_PENDING_CUSTOMER,
                ip: $ip,
                event: AccountOpeningEvent::EVENT_PENDENCY_CREATED,
                eventPayload: ['items' => $items, 'message' => $message],
            );

            return [$opening, $pendency];
        });

        $pendency->setRelation('accountOpening', $opening->loadMissing('company'));
        $resolutionUrl = $pendency->resolutionUrl($plainToken);

        $this->notifier->pendency($opening, $message, $items, $resolutionUrl);

        return ['opening' => $opening, 'resolution_url' => $resolutionUrl];
    }

    /** Pendência resolvida fora do link (ex.: presencial) — volta para análise. */
    public function resumeAnalysis(AccountOpening $opening, User $reviewer, ?string $ip): AccountOpening
    {
        $opening = $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_PENDING_CUSTOMER],
            to: AccountOpening::STATUS_IN_ANALYSIS,
            ip: $ip,
        );

        $opening->pendencies()
            ->where('status', AccountOpeningPendency::STATUS_OPEN)
            ->update([
                'status' => AccountOpeningPendency::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);

        return $opening;
    }

    /* -----------------------------------------------------------------
     | Estados de conta (pós-aprovação): bloquear / desativar
     |------------------------------------------------------------------
     */

    public function block(AccountOpening $opening, User $reviewer, ?string $reason, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_APPROVED],
            to: AccountOpening::STATUS_BLOCKED,
            ip: $ip,
            eventPayload: $reason ? ['reason' => $reason] : [],
        );
    }

    public function unblock(AccountOpening $opening, User $reviewer, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_BLOCKED],
            to: AccountOpening::STATUS_APPROVED,
            ip: $ip,
        );
    }

    public function deactivate(AccountOpening $opening, User $reviewer, ?string $reason, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_APPROVED, AccountOpening::STATUS_BLOCKED],
            to: AccountOpening::STATUS_DEACTIVATED,
            ip: $ip,
            eventPayload: $reason ? ['reason' => $reason] : [],
        );
    }

    public function reactivate(AccountOpening $opening, User $reviewer, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_DEACTIVATED],
            to: AccountOpening::STATUS_APPROVED,
            ip: $ip,
        );
    }

    /**
     * Exclusão definitiva da proposta (dados + documentos do disco).
     * Contas ativas (aprovadas ou bloqueadas) não podem ser excluídas —
     * desative antes, se for realmente necessário.
     */
    public function delete(AccountOpening $opening): void
    {
        if (in_array($opening->status, [AccountOpening::STATUS_APPROVED, AccountOpening::STATUS_BLOCKED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Contas ativas não podem ser excluídas. Desative a conta antes.'],
            ]);
        }

        DB::transaction(function () use ($opening) {
            // Registros de documentos/eventos/pendências caem por cascade
            $opening->delete();
        });

        // Arquivos do disco privado (fora da transação — melhor esforço)
        \Illuminate\Support\Facades\Storage::disk('local')
            ->deleteDirectory("account-openings/{$opening->uuid}");
    }

    private function transition(
        AccountOpening $opening,
        User $reviewer,
        array $from,
        string $to,
        ?string $ip,
        array $extra = [],
        string $event = AccountOpeningEvent::EVENT_STATUS_CHANGED,
        array $eventPayload = [],
    ): AccountOpening {
        if (! in_array($opening->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transição inválida: o cadastro está \"{$opening->status}\"."],
            ]);
        }

        $fromStatus = $opening->status;

        return DB::transaction(function () use ($opening, $reviewer, $fromStatus, $to, $ip, $extra, $event, $eventPayload) {
            $opening = $this->repository->update($opening, [
                ...$extra,
                'status' => $to,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ]);

            $payload = [
                'from_status' => $fromStatus,
                'to_status' => $to,
                ...$eventPayload,
            ];

            if (isset($extra['rejection_reason'])) {
                $payload['reason'] = $extra['rejection_reason'];
            }

            $this->repository->recordEvent(
                $opening,
                $event,
                $payload,
                userId: $reviewer->id,
                ip: $ip,
            );

            return $opening;
        });
    }
}
