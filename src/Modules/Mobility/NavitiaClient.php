<?php

declare(strict_types=1);

namespace App\Modules\Mobility;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\HttpException;

/**
 * Client de l'API Navitia (transports en commun) avec normalisation des réponses
 * au format myClermont (voir docs/API.md › Mobilité). Les réponses sont mises en cache.
 *
 * ⚠️ La conversion est écrite d'après la documentation publique de Navitia : à valider
 * sur de vraies réponses avec votre clé avant la mise en production.
 */
final class NavitiaClient
{
    private \DateTimeZone $tz;

    public function __construct()
    {
        $this->tz = new \DateTimeZone((string) Config::get('mobility.timezone', 'Europe/Paris'));
    }

    public static function isConfigured(): bool
    {
        return (string) Config::get('mobility.navitia.token', '') !== '';
    }

    /**
     * @param 'departure'|'arrival' $represents
     */
    public function journeys(float $fromLat, float $fromLng, float $toLat, float $toLng, \DateTimeImmutable $at, string $represents): array
    {
        $raw = $this->get('/journeys', [
            'from'                => "{$fromLng};{$fromLat}",
            'to'                  => "{$toLng};{$toLat}",
            'datetime'            => $at->setTimezone($this->tz)->format('Ymd\THis'),
            'datetime_represents' => $represents,
            'min_nb_journeys'     => 3,
            'count'               => 5,
        ], (int) Config::get('mobility.cache_ttl.journeys', 60), allowNoSolution: true);

        $journeys = array_map($this->journey(...), $raw['journeys'] ?? []);
        usort($journeys, static fn ($a, $b) => $a['duration_seconds'] <=> $b['duration_seconds']);
        foreach ($journeys as $i => &$j) {
            $j['is_fastest'] = $i === 0;
        }

        return $journeys;
    }

    public function places(string $query): array
    {
        $raw = $this->get('/places', ['q' => $query, 'count' => 8, 'type[]' => ['address', 'stop_area', 'poi']], (int) Config::get('mobility.cache_ttl.places', 86400));

        $places = [];
        foreach ($raw['places'] ?? [] as $p) {
            $object = $p[$p['embedded_type'] ?? ''] ?? null;
            if (!isset($object['coord']['lat'], $object['coord']['lon'])) {
                continue;
            }
            $places[] = [
                'id'        => (string) ($p['id'] ?? ''),
                'name'      => (string) ($p['name'] ?? ''),
                'type'      => match ($p['embedded_type']) { 'stop_area' => 'stop', 'poi' => 'poi', default => 'address' },
                'latitude'  => (float) $object['coord']['lat'],
                'longitude' => (float) $object['coord']['lon'],
            ];
        }

        return $places;
    }

    /** Perturbations en cours et à venir sur le réseau (« Info trafic T2C »). */
    public function traffic(): array
    {
        $network = (string) Config::get('mobility.navitia.network_id', '');
        $path = $network !== '' ? '/networks/' . rawurlencode($network) . '/disruptions' : '/disruptions';
        $raw = $this->get($path, ['count' => 50], (int) Config::get('mobility.cache_ttl.traffic', 120));

        $items = [];
        foreach ($raw['disruptions'] ?? [] as $d) {
            if (!in_array($d['status'] ?? '', ['active', 'future'], true)) {
                continue;
            }
            $lines = [];
            foreach ($d['impacted_objects'] ?? [] as $obj) {
                $line = $obj['pt_object']['line'] ?? null;
                if ($line !== null) {
                    $lines[$line['id'] ?? $line['code'] ?? ''] = ['code' => $line['code'] ?? null, 'name' => $line['name'] ?? null, 'color' => isset($line['color']) ? '#' . $line['color'] : null];
                }
            }
            $period = $d['application_periods'][0] ?? [];
            $items[] = [
                'id'        => (string) ($d['id'] ?? ''),
                'status'    => $d['status'],
                'severity'  => ['name' => $d['severity']['name'] ?? null, 'effect' => $d['severity']['effect'] ?? null, 'color' => $d['severity']['color'] ?? null],
                'title'     => $d['cause'] ?? null,
                'message'   => self::cleanText($d['messages'][0]['text'] ?? ''),
                'starts_at' => isset($period['begin']) ? $this->date($period['begin']) : null,
                'ends_at'   => isset($period['end']) ? $this->date($period['end']) : null,
                'lines'     => array_values($lines),
            ];
        }

        return $items;
    }

    private function journey(array $j): array
    {
        $sections = [];
        $walking = 0;
        foreach ($j['sections'] ?? [] as $s) {
            $type = match ($s['type'] ?? '') {
                'public_transport', 'on_demand_transport' => 'transit',
                'street_network', 'crow_fly' => match ($s['mode'] ?? 'walking') { 'bike', 'bss' => 'bike', 'car', 'ridesharing', 'taxi' => 'car', default => 'walk' },
                'transfer' => 'transfer',
                'waiting'  => 'wait',
                default    => 'other',
            };
            if ($type === 'walk') {
                $walking += (int) ($s['duration'] ?? 0);
            }
            $info = $s['display_informations'] ?? null;
            $sections[] = [
                'type'             => $type,
                'duration_seconds' => (int) ($s['duration'] ?? 0),
                'departure_at'     => isset($s['departure_date_time']) ? $this->date($s['departure_date_time']) : null,
                'arrival_at'       => isset($s['arrival_date_time']) ? $this->date($s['arrival_date_time']) : null,
                'from'             => $s['from']['name'] ?? null,
                'to'               => $s['to']['name'] ?? null,
                'line'             => $type === 'transit' && $info !== null ? [
                    'code'       => $info['code'] ?? null,
                    'label'      => $info['label'] ?? null,
                    'mode'       => $info['commercial_mode'] ?? null,
                    'direction'  => $info['direction'] ?? null,
                    'network'    => $info['network'] ?? null,
                    'color'      => isset($info['color']) && $info['color'] !== '' ? '#' . $info['color'] : null,
                    'text_color' => isset($info['text_color']) && $info['text_color'] !== '' ? '#' . $info['text_color'] : null,
                ] : null,
                'stops_count'      => $type === 'transit' ? max(0, count($s['stop_date_times'] ?? []) - 1) : null,
                'steps'            => array_map(static fn (array $p) => [
                    'instruction' => $p['instruction'] ?? trim(($p['name'] ?? '')),
                    'distance_m'  => (int) ($p['length'] ?? 0),
                ], $s['path'] ?? []),
                'geometry'         => $s['geojson'] ?? null,  // GeoJSON LineString [lng, lat] pour tracer le trajet
            ];
        }

        return [
            'mode'             => 'transit',
            'duration_seconds' => (int) ($j['duration'] ?? 0),
            'departure_at'     => isset($j['departure_date_time']) ? $this->date($j['departure_date_time']) : null,
            'arrival_at'       => isset($j['arrival_date_time']) ? $this->date($j['arrival_date_time']) : null,
            'transfers'        => (int) ($j['nb_transfers'] ?? 0),
            'walking_seconds'  => $walking,
            'co2_grams'        => isset($j['co2_emission']['value']) ? (int) round($j['co2_emission']['value']) : null,
            'is_fastest'       => false,
            'sections'         => $sections,
        ];
    }

    private function get(string $path, array $query, int $ttl, bool $allowNoSolution = false): array
    {
        if (!self::isConfigured()) {
            throw new HttpException(503, 'mobility_unavailable', 'Les transports en commun ne sont pas encore disponibles.');
        }

        $qs = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $v) {
                $qs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
            }
        }
        $url = rtrim((string) Config::get('mobility.navitia.base_url'), '/') . '/coverage/'
            . rawurlencode((string) Config::get('mobility.navitia.coverage')) . $path . '?' . implode('&', $qs);

        $cacheKey = 'navitia:' . sha1($url);
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get($cacheKey))) {
            return $cached;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) Config::get('mobility.navitia.timeout', 8),
            CURLOPT_HTTPHEADER     => ['Authorization: ' . Config::get('mobility.navitia.token'), 'Accept: application/json'],
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        $errorId = $data['error']['id'] ?? null;

        if ($allowNoSolution && in_array($errorId, ['no_solution', 'no_origin', 'no_destination', 'no_origin_nor_destination'], true)) {
            return ['journeys' => []];
        }
        if ($errorId === 'date_out_of_bounds') {
            throw new HttpException(422, 'date_out_of_bounds', 'Horaires non disponibles pour cette date.');
        }
        if ($code !== 200 || !is_array($data)) {
            Logger::warning('Navitia indisponible', ['http' => $code, 'error' => $errorId, 'path' => $path]);
            throw new HttpException(502, 'mobility_provider_error', 'Le service d\'itinéraires est momentanément indisponible.');
        }

        if (Cache::isInMemory()) {
            Cache::store()->set($cacheKey, $data, $ttl);
        }

        return $data;
    }

    /** "20261028T001200" (heure locale du réseau) → ISO 8601 avec décalage */
    private function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('Ymd\THis', $value, $this->tz);

        return $d === false ? null : $d->format(DATE_ATOM);
    }

    private static function cleanText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5)) ?? '');
    }
}
