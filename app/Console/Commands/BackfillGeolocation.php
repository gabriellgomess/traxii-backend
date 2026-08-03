<?php

namespace App\Console\Commands;

use App\Models\AccountOpening;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Console\Command;

/**
 * Preenche latitude/longitude de registros antigos (ou de casos em que o
 * geocoding falhou no cadastro). Idempotente: só toca em quem tem CEP e
 * ainda está sem coordenadas.
 */
class BackfillGeolocation extends Command
{
    protected $signature = 'geo:backfill {--limit=500 : Máximo de registros por execução}';

    protected $description = 'Resolve latitude/longitude de clientes e gerentes sem coordenadas';

    public function handle(GeocodingService $geocoding): int
    {
        $limit = (int) $this->option('limit');

        $openings = AccountOpening::query()
            ->whereNull('latitude')
            ->whereNotNull('zip_code')
            ->limit($limit)
            ->get();

        $this->info("Clientes sem coordenadas: {$openings->count()}");
        $ok = 0;

        foreach ($openings as $opening) {
            $coords = $geocoding->locate([
                'zip_code' => $opening->zip_code,
                'street' => $opening->street,
                'number' => $opening->number,
                'city' => $opening->city,
                'state' => $opening->state,
            ]);

            if ($coords) {
                $opening->forceFill($coords)->save();
                $ok++;
            }

            usleep(300_000); // respeita o rate limit do Nominatim
        }

        $this->info("Clientes atualizados: {$ok}");

        $managers = User::query()
            ->where('role', User::ROLE_COMPANY_MANAGER)
            ->whereNull('latitude')
            ->whereNotNull('zip_code')
            ->limit($limit)
            ->get();

        $this->info("Gerentes sem coordenadas: {$managers->count()}");
        $okManagers = 0;

        foreach ($managers as $manager) {
            $coords = $geocoding->locate([
                'zip_code' => $manager->zip_code,
                'street' => $manager->street,
                'number' => $manager->number,
                'city' => $manager->city,
                'state' => $manager->state,
            ]);

            if ($coords) {
                $manager->forceFill($coords)->save();
                $okManagers++;
            }

            usleep(300_000);
        }

        $this->info("Gerentes atualizados: {$okManagers}");

        return self::SUCCESS;
    }
}
