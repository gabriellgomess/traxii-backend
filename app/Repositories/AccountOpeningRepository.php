<?php

namespace App\Repositories;

use App\Models\AccountOpening;
use App\Models\AccountOpeningDocument;
use App\Models\AccountOpeningEvent;
use App\Models\User;

/**
 * Acesso a dados de abertura de conta. Toda query fica aqui;
 * regra de negócio permanece na camada de Service.
 */
class AccountOpeningRepository
{
    public function create(array $data): AccountOpening
    {
        return AccountOpening::query()->create($data);
    }

    public function update(AccountOpening $opening, array $data): AccountOpening
    {
        $opening->update($data);

        return $opening->refresh();
    }

    public function findByUuid(string $uuid): ?AccountOpening
    {
        return AccountOpening::query()
            ->with('documents')
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Existe outra proposta ativa (não rejeitada) da mesma empresa com o
     * mesmo valor no campo? Propostas rejeitadas não bloqueiam nova tentativa.
     */
    public function activeDuplicateExists(
        int $companyId,
        string $field,
        string $value,
        ?int $ignoreId = null,
    ): bool {
        return AccountOpening::query()
            ->where('company_id', $companyId)
            ->where($field, $value)
            ->where('status', '!=', AccountOpening::STATUS_REJECTED)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    /** E-mail já usado por um usuário do sistema (users.email é único global). */
    public function userEmailExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /** Cria ou substitui o documento do mesmo tipo, devolvendo o registro atual. */
    public function upsertDocument(AccountOpening $opening, string $type, array $data): AccountOpeningDocument
    {
        return $opening->documents()->updateOrCreate(['type' => $type], $data);
    }

    public function findDocument(AccountOpening $opening, string $type): ?AccountOpeningDocument
    {
        return $opening->documents()->where('type', $type)->first();
    }

    public function recordEvent(
        AccountOpening $opening,
        string $event,
        ?array $payload = null,
        ?int $userId = null,
        ?string $ip = null,
    ): AccountOpeningEvent {
        return $opening->events()->create([
            'user_id' => $userId,
            'event' => $event,
            'payload' => $payload,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
