<?php

namespace App\Http\Controllers;

use App\Models\AccountOpening;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pontos para o mapa (whitelabels, gerentes e clientes), respeitando o mesmo
 * escopo das demais consultas: Percapital vê tudo, admin do whitelabel vê a
 * própria empresa e gerente vê apenas os clientes que indicou.
 *
 * Cada ponto carrega a cor da empresa, para segmentação visual no mapa.
 */
class MapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyFilter = $user->seesAllCompanies() ? $request->query('company_id') : null;

        /* ---------------- Clientes ---------------- */

        $clientsQuery = AccountOpening::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNot('status', AccountOpening::STATUS_DRAFT)
            ->with(['manager:id,name', 'company:id,name,primary_color']);

        $this->applyScope($clientsQuery, $user, $companyFilter, managerColumn: 'manager_id');

        $clients = $clientsQuery
            ->get(['uuid', 'full_name', 'city', 'state', 'status', 'latitude', 'longitude', 'manager_id', 'company_id'])
            ->map(fn (AccountOpening $o) => [
                'id' => $o->uuid,
                'name' => $o->full_name,
                'city' => trim(($o->city ?? '').($o->state ? '/'.$o->state : ''), '/'),
                'status' => $o->status,
                'manager' => $o->manager?->name,
                'company_id' => $o->company_id,
                'company' => $o->company?->name,
                'company_color' => $o->company?->primary_color,
                'lat' => (float) $o->latitude,
                'lng' => (float) $o->longitude,
            ]);

        /* ---------------- Gerentes ---------------- */

        $managersQuery = User::query()
            ->where('role', User::ROLE_COMPANY_MANAGER)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with('company:id,name,primary_color');

        if ($user->isManager()) {
            // Gerente-topo também vê seus subgerentes no mapa
            $managersQuery->whereIn('id', $user->visibleManagerIds());
        } else {
            $this->applyScope($managersQuery, $user, $companyFilter);
        }

        $managers = $managersQuery
            ->withCount('referredOpenings')
            ->get(['id', 'name', 'city', 'state', 'is_active', 'latitude', 'longitude', 'company_id', 'parent_manager_id'])
            ->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'city' => trim(($m->city ?? '').($m->state ? '/'.$m->state : ''), '/'),
                'clients' => $m->referred_openings_count,
                'is_active' => (bool) $m->is_active,
                'is_submanager' => $m->parent_manager_id !== null,
                'company_id' => $m->company_id,
                'company' => $m->company?->name,
                'company_color' => $m->company?->primary_color,
                'lat' => (float) $m->latitude,
                'lng' => (float) $m->longitude,
            ]);

        /* ---------------- Whitelabels (sedes) ---------------- */

        $companiesQuery = Company::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withCount(['users as managers_count' => fn ($q) => $q->where('role', User::ROLE_COMPANY_MANAGER)])
            ->withCount('accountOpenings');

        if (! $user->seesAllCompanies()) {
            $companiesQuery->where('id', $user->company_id ?? -1);
        } elseif ($companyFilter) {
            $companiesQuery->where('id', $companyFilter);
        }

        $companies = $companiesQuery
            ->get(['id', 'name', 'city', 'state', 'primary_color', 'is_active', 'latitude', 'longitude'])
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => trim(($c->city ?? '').($c->state ? '/'.$c->state : ''), '/'),
                'managers' => $c->managers_count,
                'clients' => $c->account_openings_count,
                'is_active' => (bool) $c->is_active,
                'color' => $c->primary_color,
                'lat' => (float) $c->latitude,
                'lng' => (float) $c->longitude,
            ]);

        /* -------- Quantos ficaram de fora por falta de coordenadas -------- */

        $missingQuery = AccountOpening::query()
            ->whereNull('latitude')
            ->whereNot('status', AccountOpening::STATUS_DRAFT);

        $this->applyScope($missingQuery, $user, $companyFilter, managerColumn: 'manager_id');

        return response()->json([
            'data' => [
                'clients' => $clients,
                'managers' => $managers,
                'companies' => $companies,
                'clients_without_location' => $missingQuery->count(),
            ],
        ]);
    }

    /** Escopo por papel, comum às três consultas. */
    private function applyScope(
        $query,
        User $user,
        ?string $companyFilter,
        ?string $managerColumn = null,
    ): void {
        if ($user->isManager() && $managerColumn) {
            $query->whereIn($managerColumn, $user->visibleManagerIds());

            return;
        }

        if (! $user->seesAllCompanies()) {
            $query->where('company_id', $user->company_id ?? -1);

            return;
        }

        if ($companyFilter) {
            $query->where('company_id', $companyFilter);
        }
    }
}
