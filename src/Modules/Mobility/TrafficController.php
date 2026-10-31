<?php

declare(strict_types=1);

namespace App\Modules\Mobility;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Incidents de circulation (spec v1 › Trafic) : un seul appel TomTom Orbis Maps Traffic API v2 (Incident Details)
 * pour toute la métropole,
 * gardé en cache TRAFFIC_CACHE_SECONDS ; anciennes données servies 30 min si TomTom ne répond plus.
 * Le filtre par distance se fait ensuite sur le serveur.
 *
 *   GET /traffic/incidents?lat=&lon=&r=   (r : 300 à 6 000 m, 2 500 par défaut)
 *   GET /api/trafic                       même contrat, route historique de l'app build 16 (non signée)
 */
final class TrafficController
{
    private const BBOX = '2.94,45.68,3.24,45.86';
    private const URL = 'https://api.tomtom.com/maps/orbis/traffic/incidents/details';

    /** iconCategory TomTom (Orbis v2) → kind attendu par l'app (brief §10.2) */
    private const KINDS = ['accident' => 'accident', 'jam' => 'jam', 'roadWorks' => 'roadworks', 'roadClosed' => 'closure',
                           'laneClosed' => 'laneClosed', 'dangerousConditions' => 'hazard', 'brokenDownVehicle' => 'breakdown',
                           'fog' => 'weather', 'rain' => 'weather', 'ice' => 'weather', 'wind' => 'weather', 'flooding' => 'weather'];
    private const TITLES = ['accident' => 'Accident', 'jam' => 'Bouchon', 'roadworks' => 'Travaux', 'closure' => 'Route fermée',
                            'laneClosed' => 'Voie fermée', 'hazard' => 'Danger', 'weather' => 'Conditions météo',
                            'breakdown' => 'Véhicule en panne', 'other' => 'Incident'];
    /** magnitudeOfDelay → 0 (inconnu) à 4 (route fermée) */
    private const MAGNITUDES = ['unknown' => 0, 'minor' => 1, 'moderate' => 2, 'major' => 3, 'undefined' => 4];

    public function incidents(Request $request): Response
    {
        return Response::success($this->build($request->query()))->withHeader('Cache-Control', 'private, max-age=60');
    }

    public function legacy(Request $request): Response
    {
        return Response::json(\App\Core\Contract::camel($this->build($request->query())))->withHeader('Cache-Control', 'public, max-age=60');
    }

    private function build(array $q): array
    {
        $lat = isset($q['lat']) && is_numeric($q['lat']) ? (float) $q['lat'] : 45.7772;
        $lon = isset($q['lon']) && is_numeric($q['lon']) ? (float) $q['lon'] : 3.0870;
        $radius = isset($q['r']) && is_numeric($q['r']) ? (int) $q['r'] : 2500;
        if ($radius < 300 || $radius > 6000) {
            throw new ValidationException(['r' => 'Le rayon doit être compris entre 300 et 6 000 m.']);
        }

        $data = $this->metropolis();
        $incidents = [];
        foreach ($data['incidents'] as $incident) {
            $distance = PHP_INT_MAX;
            foreach ($incident['path'] as [$plat, $plon]) {
                $distance = min($distance, self::meters($lat, $lon, $plat, $plon));
            }
            if ($distance <= $radius) {
                $incidents[] = $incident + ['distance' => (int) round($distance)];
            }
        }
        usort($incidents, static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return ['source' => 'tomtom', 'updated_at' => $data['updated_at'], 'radius' => $radius, 'incidents' => $incidents];
    }

    /** Incidents de toute la métropole, en cache ; données anciennes (30 min max) si TomTom est en panne. */
    private function metropolis(): array
    {
        $key = (string) Config::get('mobility.tomtom_key', '');
        if ($key === '') {
            throw new HttpException(503, 'not_configured', 'L\'info trafic n\'est pas encore disponible.');
        }
        $ttl = (int) Config::get('mobility.traffic_cache_seconds', 300);
        $fresh = Cache::store()->get('traffic:tomtom');
        if (is_array($fresh) && $fresh['fetched'] > time() - $ttl) {
            return $fresh;
        }
        // Un seul appel à la fois vers TomTom, même si 1 000 téléphones demandent en même temps
        if (is_array($fresh) && !Cache::store()->add('traffic:tomtom:lock', 30)) {
            return $fresh;
        }

        try {
            $r = (new HttpClient(timeout: 8, retries: 0))->getJson(self::URL . '?' . http_build_query([
                'apiVersion' => 2, 'bbox' => self::BBOX, 'timeValidity' => 'present',
            ]), [
                'TomTom-Api-Key: ' . $key,
                'TomTom-Api-Version: 2',
                'Accept-Language: fr-FR',
                'Attributes: incidents(type,geometry(type,coordinates),properties(id,iconCategory,magnitudeOfDelay,events(description,code),startTime,endTime,from,to,lengthInMeters,delayInSeconds,roadNumbers))',
            ]);
            $data = ['fetched' => time(), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'incidents' => array_values(array_filter(array_map([self::class, 'map'], (array) ($r['incidents'] ?? []))))];
            Cache::store()->set('traffic:tomtom', $data, 1800);
            Cache::store()->delete('traffic:tomtom:lock');

            return $data;
        } catch (\Throwable $e) {
            Logger::warning('TomTom indisponible', ['error' => $e->getMessage()]);
            if (is_array($fresh) && $fresh['fetched'] > time() - 1800) {
                return $fresh;
            }
            throw new HttpException(503, 'upstream_unavailable', 'L\'info trafic est momentanément indisponible.');
        }
    }

    public static function map(array $i): ?array
    {
        $p = $i['properties'] ?? [];
        $coords = $i['geometry']['coordinates'] ?? [];
        if (($i['geometry']['type'] ?? '') === 'Point') {
            $coords = [$coords];
        }
        $path = array_values(array_map(static fn ($c) => [round((float) $c[1], 5), round((float) $c[0], 5)], array_filter((array) $coords, 'is_array')));
        if ($path === [] || empty($p['id'])) {
            return null;
        }
        $kind = self::KINDS[(string) ($p['iconCategory'] ?? '')] ?? 'other';
        $iso = static fn (?string $d) => $d ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($d)) : null;

        return [
            'id'        => (string) $p['id'],
            'kind'      => $kind,
            'title'     => self::TITLES[$kind],
            'detail'    => implode(' · ', array_filter(array_column((array) ($p['events'] ?? []), 'description'))) ?: null,
            'from'      => $p['from'] ?? null,
            'to'        => $p['to'] ?? null,
            'roads'     => array_values((array) ($p['roadNumbers'] ?? [])),
            'delay'     => isset($p['delayInSeconds']) ? (int) $p['delayInSeconds'] : null,
            'length'    => isset($p['lengthInMeters']) ? (int) round((float) $p['lengthInMeters']) : null,
            'magnitude' => self::MAGNITUDES[(string) ($p['magnitudeOfDelay'] ?? '')] ?? 0,
            'start'     => $iso($p['startTime'] ?? null),
            'end'       => $iso($p['endTime'] ?? null),
            'path'      => $path,
        ];
    }

    private static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
