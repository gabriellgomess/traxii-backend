<?php

namespace App\Http\Middleware;

use App\Models\AccountOpening;
use App\Services\AccountOpeningService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege as rotas públicas do wizard: o cadastro só pode ser lido/alterado
 * por quem possui o token de retomada entregue na criação (header
 * X-Opening-Token). Token ausente ou inválido responde 404 — nunca 403 —
 * para não permitir enumeração de cadastros existentes.
 */
class EnsureAccountOpeningToken
{
    public function __construct(private readonly AccountOpeningService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $opening = $request->route('opening');
        $token = (string) $request->header('X-Opening-Token', '');

        if (! $opening instanceof AccountOpening
            || $token === ''
            || ! $this->service->tokenMatches($opening, $token)) {
            abort(404, 'Cadastro não encontrado.');
        }

        return $next($request);
    }
}
