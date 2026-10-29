<?php

declare(strict_types=1);

namespace App\Modules\Fuel;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Exceptions\HttpException;

/**
 * Prix des carburants : flux officiel « prix des carburants en France – flux instantané » (data.economie.gouv.fr),
 * mis en cache 10 minutes par zone (spec v1 › Carburants). L'app n'interroge plus le flux en direct.
 */
final class FuelService
{
    public const FUELS = ['gazole', 'e10', 'sp95', 'sp98', 'e85', 'gplc'];
    private const URL = 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/prix-des-carburants-en-france-flux-instantane-v2/records';

    /** @return array{items: list<array>, updated_at: string} stations du plus proche au plus loin */
    public function stations(float $lat, float $lon, float $radiusKm, ?string $fuel = null): array
    {
        // Zone arrondie (~1 km) : les téléphones proches partagent le même cache
        $key = sprintf('fuel:%.2f:%.2f:%d', $lat, $lon, (int) ceil($radiusKm));
        $data = Cache::store()->get($key);
        if (!is_array($data)) {
            try {
                $r = (new HttpClient(timeout: 8, retries: 0))->getJson(self::URL . '?' . http_build_query([
                    'where' => sprintf("within_distance(geom, geom'POINT(%F %F)', %dkm)", round($lon, 2), round($lat, 2), (int) ceil($radiusKm)),
                    'limit' => 100,
                ]));
            } catch (\Throwable) {
                throw new HttpException(503, 'upstream_unavailable', 'Les prix des carburants sont momentanément indisponibles.');
            }
            $data = ['fetched' => gmdate('Y-m-d\TH:i:s\Z'), 'rows' => array_values(array_filter(array_map([self::class, 'map'], (array) ($r['results'] ?? []))))];
            Cache::store()->set($key, $data, 600);
        }

        $items = [];
        foreach ($data['rows'] as $s) {
            if ($fuel !== null && !isset($s['prices'][$fuel])) {
                continue;
            }
            $s['distance'] = (int) round(self::meters($lat, $lon, $s['latitude'], $s['longitude']));
            if ($s['distance'] <= $radiusKm * 1000) {
                $items[] = $s;
            }
        }
        usort($items, $fuel !== null
            ? static fn ($a, $b) => $a['prices'][$fuel]['price'] <=> $b['prices'][$fuel]['price'] ?: $a['distance'] <=> $b['distance']
            : static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return ['items' => $items, 'updated_at' => $data['fetched']];
    }

    public static function map(array $r): ?array
    {
        $lat = $r['geom']['lat'] ?? (isset($r['latitude']) ? (float) $r['latitude'] / 100000 : null);
        $lon = $r['geom']['lon'] ?? (isset($r['longitude']) ? (float) $r['longitude'] / 100000 : null);
        if ($lat === null || $lon === null || empty($r['id'])) {
            return null;
        }
        $prices = [];
        foreach (self::FUELS as $f) {
            if (isset($r[$f . '_prix']) && is_numeric($r[$f . '_prix'])) {
                $prices[$f] = ['price' => round((float) $r[$f . '_prix'], 3), 'updated_at' => isset($r[$f . '_maj']) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $r[$f . '_maj'])) : null];
            }
        }

        return [
            'id'        => (string) $r['id'],
            'address'   => trim(ucwords(mb_strtolower((string) ($r['adresse'] ?? '')))),
            'city'      => trim(ucwords(mb_strtolower((string) ($r['ville'] ?? '')), " -'")),
            'latitude'  => round((float) $lat, 6),
            'longitude' => round((float) $lon, 6),
            'prices'    => $prices,
        ];
    }

    private static function meters(float $a, float $b, float $c, float $d): float
    {
        $p = deg2rad($c - $a);
        $q = deg2rad($d - $b);
        $h = sin($p / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($q / 2) ** 2;

        return 12742000 * asin(min(1, sqrt($h)));
    }
}
