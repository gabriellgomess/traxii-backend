<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Converte CEP/endereço em latitude/longitude para uso em mapas.
 *
 * Estratégia: BrasilAPI v2 (CEP → coordenadas, quando disponível) e, em
 * seguida, Nominatim/OpenStreetMap com o endereço completo. O resultado é
 * cacheado e a falha é sempre silenciosa — geolocalização é um dado
 * complementar e nunca pode impedir um cadastro.
 */
class GeocodingService
{
    private const TIMEOUT = 4;

    private const CACHE_DAYS = 30;

    /**
     * @param  array{zip_code?:string|null,street?:string|null,number?:string|null,city?:string|null,state?:string|null}  $address
     * @return array{latitude: float, longitude: float}|null
     */
    public function locate(array $address): ?array
    {
        $zip = preg_replace('/\D/', '', (string) ($address['zip_code'] ?? ''));

        if (strlen($zip) === 8) {
            $coords = Cache::remember(
                "geo:cep:{$zip}",
                now()->addDays(self::CACHE_DAYS),
                fn () => $this->fromBrasilApi($zip) ?? $this->fromNominatim($address) ?? [],
            );

            return $coords ?: null;
        }

        return $this->fromNominatim($address);
    }

    /** BrasilAPI v2 devolve `location.coordinates` para boa parte dos CEPs. */
    private function fromBrasilApi(string $zip): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get("https://brasilapi.com.br/api/cep/v2/{$zip}");

            if (! $response->successful()) {
                return null;
            }

            $coords = $response->json('location.coordinates');
            $lat = $coords['latitude'] ?? null;
            $lng = $coords['longitude'] ?? null;

            if ($lat === null || $lng === null || $lat === '' || $lng === '') {
                return null;
            }

            return ['latitude' => (float) $lat, 'longitude' => (float) $lng];
        } catch (Throwable $e) {
            Log::info('Geocoding via BrasilAPI falhou.', ['zip' => $zip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Fallback: endereço completo no Nominatim (uso leve, com User-Agent). */
    private function fromNominatim(array $address): ?array
    {
        $query = collect([
            trim(($address['street'] ?? '').' '.($address['number'] ?? '')),
            $address['city'] ?? null,
            $address['state'] ?? null,
            'Brasil',
        ])->filter()->implode(', ');

        if ($query === 'Brasil') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Traxiinvest/1.0 (geocoding)'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'br',
                ]);

            $first = $response->successful() ? ($response->json()[0] ?? null) : null;

            if (! $first || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'latitude' => (float) $first['lat'],
                'longitude' => (float) $first['lon'],
            ];
        } catch (Throwable $e) {
            Log::info('Geocoding via Nominatim falhou.', ['query' => $query, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
