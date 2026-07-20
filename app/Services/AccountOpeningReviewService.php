<?php

namespace App\Services;

use App\Models\AccountOpening;
use App\Models\AccountOpeningEvent;
use App\Models\User;
use App\Repositories\AccountOpeningRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras de negócio da revisão (backoffice do Gestor):
 * transições de status com auditoria e registro do revisor.
 */
class AccountOpeningReviewService
{
    public function __construct(
        private readonly AccountOpeningRepository $repository,
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
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_PENDING, AccountOpening::STATUS_IN_ANALYSIS],
            to: AccountOpening::STATUS_APPROVED,
            ip: $ip,
        );
    }

    public function reject(AccountOpening $opening, User $reviewer, string $reason, ?string $ip): AccountOpening
    {
        return $this->transition(
            $opening,
            $reviewer,
            from: [AccountOpening::STATUS_PENDING, AccountOpening::STATUS_IN_ANALYSIS],
            to: AccountOpening::STATUS_REJECTED,
            ip: $ip,
            extra: ['rejection_reason' => $reason],
        );
    }

    private function transition(
        AccountOpening $opening,
        User $reviewer,
        array $from,
        string $to,
        ?string $ip,
        array $extra = [],
    ): AccountOpening {
        if (! in_array($opening->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transição inválida: o cadastro está \"{$opening->status}\"."],
            ]);
        }

        $fromStatus = $opening->status;

        return DB::transaction(function () use ($opening, $reviewer, $fromStatus, $to, $ip, $extra) {
            $opening = $this->repository->update($opening, [
                ...$extra,
                'status' => $to,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ]);

            $this->repository->recordEvent(
                $opening,
                AccountOpeningEvent::EVENT_STATUS_CHANGED,
                ['from_status' => $fromStatus, 'to_status' => $to] + ($extra ? ['reason' => $extra['rejection_reason'] ?? null] : []),
                userId: $reviewer->id,
                ip: $ip,
            );

            return $opening;
        });
    }
}
