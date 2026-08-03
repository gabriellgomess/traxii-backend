<?php

namespace App\Http\Controllers;

use App\Models\AccountOpening;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pontos para o mapa (clientes e gerentes), respeitando o mesmo escopo das
 * demais consultas: Percapital vê tudo, admin do whitelabel vê a empresa e
 * gerente vê apenas os clientes que indicou.
 */
class MapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /* ---------------- Clientes ---------------- */

        $clientsQuery = AccountOpening::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNot('status', AccountOpening::STATUS_DRAFT)
            ->with('manager:id,name');

        if ($user->isManager()) {
            $clientsQuery->where('manager_id', $user->id);
        } elseif (! $user->seesAllCompanies()) {
            $clientsQuery->where('company_id', $user->company_id ?? -1);
        } elseif ($companyId = $request->query('company_id')) {
            $clientsQuery->where('company_id', $companyId);
        }

        $clients = $clientsQuery
            ->get(['uuid', 'full_name', 'city', 'state', 'status', 'latitude', 'longitude', 'manager_id'])
            ->map(fn (AccountOpening $o) => [
                'id' => $o->uuid,
                'name' => $o->full_name,
                'city' => trim(($o->city ?? '').($o->state ? '/'.$o->state : ''), '/'),
                'status' => $o->status,
                'manager' => $o->manager?->name,
                'lat' => (float) $o->latitude,
                'lng' => (float) $o->longitude,
            ]);

        /* ---------------- Gerentes ---------------- */

        $managersQuery = User::query()
            ->where('role', User::ROLE_COMPANY_MANAGER)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($user->isManager()) {
            $managersQuery->where('id', $user->id);
        } elseif (! $user->seesAllCompanies()) {
            $managersQuery->where('company_id', $user->company_id ?? -1);
        } elseif ($companyId = $request->query('company_id')) {
            $managersQuery->where('company_id', $companyId);
        }

        $managers = $managersQuery
            ->withCount('referredOpenings')
            ->get(['id', 'name', 'city', 'state', 'is_active', 'latitude', 'longitude'])
            ->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'city' => trim(($m->city ?? '').($m->state ? '/'.$m->state : ''), '/'),
                'clients' => $m->referred_openings_count,
                'is_active' => (bool) $m->is_active,
                'lat' => (float) $m->latitude,
                'lng' => (float) $m->longitude,
            ]);

        return response()->json([
            'data' => [
                'clients' => $clients,
                'managers' => $managers,
            ],
        ]);
    }
}
