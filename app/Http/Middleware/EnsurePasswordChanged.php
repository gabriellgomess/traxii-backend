<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia o uso da API enquanto a senha provisória não for trocada.
 * As rotas de sessão (me/logout/change-password) ficam liberadas.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            abort(423, 'Defina uma nova senha para continuar.');
        }

        return $next($request);
    }
}
