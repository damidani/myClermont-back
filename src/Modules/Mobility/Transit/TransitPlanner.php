<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Transit;

/**
 * Itinéraires T2C (5b642919) : Connection Scan Algorithm sur les horaires théoriques du GTFS (GtfsFeed).
 * Marche jusqu'aux arrêts dans un rayon de 1 km au départ et à l'arrivée, correspondances à pied jusqu'à 300 m (1 min de battement).
 */
final class TransitPlanner
{
    private const ACCESS_RADIUS = 1000;
    private const TRANSFER_SLACK = 60;
    private const MAX_RESULTS = 3;

    private array $feed;
    private \DateTimeZone $tz;

    public function __construct()
    {
        $this->feed = GtfsFeed::feed();
        $this->tz = new \DateTimeZone('Europe/Paris');
    }

    /** @return list<array> itinéraires au format de l'app, triés par heure d'arrivée */
    public function journeys(float $fromLat, float $fromLon, float $toLat, float $toLon, \DateTimeImmutable $at, bool $arriveBy): array
    {
        $local = $at->setTimezone($this->tz);
        $midnight = $local->setTime(0, 0);
        $ymd = $midnight->format('Ymd');
        $day = GtfsFeed::day($ymd);
        $t = $local->getTimestamp() - $midnight->getTimestamp();
        $access = $this->near($fromLat, $fromLon);
        $egress = $this->near($toLat, $toLon);
        if ($access === [] || $egress === []) {
            return [];
        }
        $found = [];
        $start = $arriveBy ? max(0, $t - 2 * 3600) : $t;
        for ($i = 0; $i < ($arriveBy ? 25 : 6); $i++) {
            $j = $this->scan($day, $access, $egress, $start);
            if ($j === null) {
                break;
            }
            if ($arriveBy && $j['arrival'] > $t) {
                break;
            }
            $found[$j['key']] = $j;
            $start = $j['first_departure'] + 60;
            if (!$arriveBy && count($found) >= self::MAX_RESULTS) {
                break;
            }
        }
        $list = array_values($found);
        if ($arriveBy) {
            $list = array_slice($list, -self::MAX_RESULTS);
        }
        usort($list, static fn ($a, $b) => $a['arrival'] <=> $b['arrival']);

        return array_map(fn ($j) => $this->present($j, $midnight, $fromLat, $fromLon, $toLat, $toLon), $list);
    }

    /** Arrêts à moins de 1 km : arrêt => secondes de marche */
    private function near(float $lat, float $lon): array
    {
        $out = [];
        foreach ($this->feed['stops'] as $i => [, $sLat, $sLon]) {
            if (abs($sLat - $lat) < 0.01 && abs($sLon - $lon) < 0.014 && ($d = GtfsFeed::distance($lat, $lon, $sLat, $sLon)) <= self::ACCESS_RADIUS) {
                $out[$i] = GtfsFeed::walkSeconds($d);
            }
        }

        return $out;
    }

    /** Arrivée au plus tôt en partant à $t (secondes depuis minuit) ; null si aucun trajet ce jour-là */
    private function scan(array $day, array $access, array $egress, int $t): ?array
    {
        $S = [];        // arrêt => arrivée au plus tôt
        $J = [];        // arrêt => ['o', marche] | ['t', connexion de montée, connexion de descente] | ['w', arrêt précédent, marche]
        $T = [];        // course => connexion de montée
        foreach ($access as $stop => $walk) {
            $S[$stop] = $t + $walk;
            $J[$stop] = ['o', $walk];
        }
        $best = PHP_INT_MAX;
        $bestStop = null;
        // Première connexion qui part à $t ou après (dichotomie)
        [$lo, $hi] = [0, count($day['dep'])];
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            $day['dep'][$mid] < $t ? $lo = $mid + 1 : $hi = $mid;
        }
        $transfers = $this->feed['transfers'];
        for ($c = $lo, $n = count($day['dep']); $c < $n; $c++) {
            $dep = $day['dep'][$c];
            if ($dep >= $best) {
                break;
            }
            $trip = $day['trip'][$c];
            if (!isset($T[$trip])) {
                $from = $day['from'][$c];
                if (!isset($S[$from]) || $S[$from] > $dep) {
                    continue;
                }
                $T[$trip] = $c;
            }
            $to = $day['to'][$c];
            $arr = $day['arr'][$c];
            if ($arr < ($S[$to] ?? PHP_INT_MAX)) {
                $S[$to] = $arr;
                $J[$to] = ['t', $T[$trip], $c];
                if (isset($egress[$to]) && $arr + $egress[$to] < $best) {
                    [$best, $bestStop] = [$arr + $egress[$to], $to];
                }
                foreach ($transfers[$to] ?? [] as [$next, $walk]) {
                    $at = $arr + $walk + self::TRANSFER_SLACK;
                    if ($at < ($S[$next] ?? PHP_INT_MAX)) {
                        $S[$next] = $at;
                        $J[$next] = ['w', $to, $walk];
                        if (isset($egress[$next]) && $at + $egress[$next] < $best) {
                            [$best, $bestStop] = [$at + $egress[$next], $next];
                        }
                    }
                }
            }
        }
        if ($bestStop === null) {
            return null;
        }
        // Remontée du trajet
        $legs = [['walk', $bestStop, $egress[$bestStop]]];
        $stop = $bestStop;
        for ($guard = 0; $guard < 50 && $J[$stop][0] !== 'o'; $guard++) {
            $j = $J[$stop];
            if ($j[0] === 't') {
                $legs[] = ['ride', $j[1], $j[2]];
                $stop = $day['from'][$j[1]];
            } else {
                $legs[] = ['transfer', $j[1], $stop, $j[2]];
                $stop = $j[1];
            }
        }
        $legs[] = ['access', $stop, $J[$stop][1]];
        $legs = array_reverse($legs);
        $rides = array_values(array_filter($legs, static fn ($l) => $l[0] === 'ride'));
        if ($rides === []) {
            return null;   // trajet à pied seulement : l'app propose déjà la marche
        }
        $firstDep = $day['dep'][$rides[0][1]];

        return ['legs' => $legs, 'day' => $day, 'arrival' => $best, 'first_departure' => $firstDep, 'departure' => $firstDep - $legs[0][2],
                'key' => implode('-', array_map(static fn ($r) => $day['trip'][$r[1]], $rides))];
    }

    private function present(array $j, \DateTimeImmutable $midnight, float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        $day = $j['day'];
        $iso = static fn (int $s) => gmdate('Y-m-d\TH:i:s\Z', $midnight->getTimestamp() + $s);
        $stopName = fn (int $s) => $this->feed['stops'][$s][0];
        $sections = [];
        $walking = 0;
        $clock = $j['departure'];
        $n = 0;
        $legs = $j['legs'];
        foreach ($legs as $k => $leg) {
            switch ($leg[0]) {
                case 'access':
                    $walking += $leg[2];
                    $sections[] = ['id' => 's' . ++$n, 'type' => 'walk', 'mode' => 'walk', 'duration' => $leg[2], 'from' => 'Départ', 'to' => $stopName($leg[1])];
                    $clock += $leg[2];
                    break;
                case 'ride':
                    $dep = $day['dep'][$leg[1]];
                    if ($dep > $clock + 30) {
                        $sections[] = ['id' => 's' . ++$n, 'type' => 'wait', 'mode' => 'walk', 'duration' => $dep - $clock, 'from' => $stopName($day['from'][$leg[1]]), 'to' => $stopName($day['from'][$leg[1]])];
                    }
                    $trip = $this->feed['trips'][$day['trip'][$leg[1]]];
                    $route = $this->feed['routes'][$trip['route']];
                    $arr = $day['arr'][$leg[2]];
                    $sections[] = [
                        'id' => 's' . ++$n, 'type' => 'transit', 'mode' => $route['mode'], 'line' => $route['name'], 'line_color' => $route['color'],
                        'line_text_color' => $route['text'], 'direction' => $trip['headsign'], 'from' => $stopName($day['from'][$leg[1]]), 'to' => $stopName($day['to'][$leg[2]]),
                        'departure' => $iso($dep), 'arrival' => $iso($arr), 'duration' => $arr - $dep, 'stops' => $this->countStops($day, $leg[1], $leg[2]),
                    ];
                    $clock = $arr;
                    break;
                case 'transfer':
                    $walking += $leg[3];
                    $sections[] = ['id' => 's' . ++$n, 'type' => 'walk', 'mode' => 'walk', 'duration' => $leg[3], 'from' => $stopName($leg[1]), 'to' => $stopName($leg[2])];
                    $clock += $leg[3];
                    break;
                case 'walk':
                    $walking += $leg[2];
                    if ($leg[2] > 0) {
                        $sections[] = ['id' => 's' . ++$n, 'type' => 'walk', 'mode' => 'walk', 'duration' => $leg[2], 'from' => $stopName($leg[1]), 'to' => 'Arrivée'];
                    }
                    break;
            }
        }
        $rides = count(array_filter($legs, static fn ($l) => $l[0] === 'ride'));

        return [
            'id' => substr(sha1($j['key'] . '|' . $j['departure']), 0, 16), 'departure' => $iso($j['departure']), 'arrival' => $iso($j['arrival']),
            'duration' => $j['arrival'] - $j['departure'], 'walking_duration' => $walking, 'transfers' => max(0, $rides - 1), 'sections' => $sections,
        ];
    }

    /** Nombre d'arrêts parcourus entre la montée et la descente (même course) */
    private function countStops(array $day, int $board, int $alight): int
    {
        $trip = $day['trip'][$board];
        $list = $this->feed['times'][$trip];
        $from = $day['from'][$board];
        $to = $day['to'][$alight];
        $i = $k = null;
        foreach ($list as $idx => [$stop]) {
            if ($i === null && $stop === $from) {
                $i = $idx;
            } elseif ($i !== null && $stop === $to) {
                $k = $idx;
                break;
            }
        }

        return $i !== null && $k !== null ? $k - $i : 1;
    }
}
