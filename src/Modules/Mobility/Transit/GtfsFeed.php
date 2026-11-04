<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Transit;

use App\Content\Import\HttpClient;

/**
 * Horaires théoriques du réseau T2C (GTFS SMTC-AC, open data Clermont Auvergne Métropole, Licence Ouverte).
 * transit:import télécharge le GTFS et prépare dans storage/transit :
 *   feed.ser    arrêts, lignes, courses, horaires, calendriers, correspondances à pied (pour le calcul d'itinéraires) ;
 *   lines.json  tracé simplifié et arrêts de chaque ligne (GET /transit/lines).
 * Les connexions d'une journée (day-AAAAMMJJ.ser) sont calculées à la première demande puis gardées.
 */
final class GtfsFeed
{
    public const URL = 'https://opendata.clermontmetropole.eu/api/v2/catalog/datasets/gtfs-smtc/alternative_exports/gtfs';
    private const TRANSFER_RADIUS = 300;   // m : correspondance à pied entre deux arrêts

    private static ?array $feed = null;

    public static function dir(): string
    {
        $dir = dirname(__DIR__, 4) . '/storage/transit';
        is_dir($dir) || mkdir($dir, 0775, true);

        return $dir;
    }

    public static function available(): bool
    {
        return is_file(self::dir() . '/feed.ser');
    }

    /** Télécharge et prépare le GTFS ; renvoie un résumé */
    public static function import(?string $url = null): string
    {
        $zipPath = self::dir() . '/gtfs.zip';
        $body = (new HttpClient(timeout: 120, retries: 2, delayMs: 1000))->get($url ?? self::URL)['body'];
        if (strlen($body) < 10000 || !str_starts_with($body, "PK")) {
            throw new \RuntimeException('GTFS T2C : archive invalide.');
        }
        file_put_contents($zipPath . '.tmp', $body);
        rename($zipPath . '.tmp', $zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('GTFS T2C : impossible d\'ouvrir l\'archive.');
        }
        $csv = static function (string $name) use ($zip): \Generator {
            $stream = $zip->getStream($name) ?: throw new \RuntimeException("GTFS T2C : {$name} manquant.");
            $head = null;
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($head === null) {
                    $head = array_map(static fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $row);
                    continue;
                }
                if (count($row) === count($head)) {
                    yield array_combine($head, $row);
                }
            }
            fclose($stream);
        };

        $stops = $stopIndex = [];
        foreach ($csv('stops.txt') as $s) {
            if (($s['location_type'] ?? '0') !== '0' && ($s['location_type'] ?? '') !== '') {
                continue;   // stations (parents) : seuls les quais servent au calcul
            }
            $stopIndex[$s['stop_id']] = count($stops);
            $stops[] = [$s['stop_name'], (float) $s['stop_lat'], (float) $s['stop_lon']];
        }
        $routes = [];
        foreach ($csv('routes.txt') as $r) {
            $routes[$r['route_id']] = ['name' => $r['route_short_name'] ?: $r['route_long_name'], 'mode' => $r['route_type'] === '0' ? 'tram' : 'bus',
                                       'color' => strtoupper($r['route_color'] ?: '666666'), 'text' => strtoupper($r['route_text_color'] ?: 'FFFFFF'), 'sort' => (int) ($r['route_sort_order'] ?: 999)];
        }
        $trips = $tripIndex = [];
        foreach ($csv('trips.txt') as $t) {
            if (!isset($routes[$t['route_id']])) {
                continue;
            }
            $tripIndex[$t['trip_id']] = count($trips);
            $trips[] = ['route' => $t['route_id'], 'service' => $t['service_id'], 'headsign' => $t['trip_headsign'], 'dir' => (int) $t['direction_id'], 'shape' => $t['shape_id']];
        }
        $times = [];   // course => [[arrêt, arrivée, départ, séquence], ...]
        $sec = static function (string $t): int {
            [$h, $m, $s] = array_map('intval', explode(':', $t) + [0, 0, 0]);

            return $h * 3600 + $m * 60 + $s;
        };
        foreach ($csv('stop_times.txt') as $st) {
            if (!isset($tripIndex[$st['trip_id']], $stopIndex[$st['stop_id']]) || $st['arrival_time'] === '') {
                continue;
            }
            $times[$tripIndex[$st['trip_id']]][] = [$stopIndex[$st['stop_id']], $sec($st['arrival_time']), $sec($st['departure_time'] ?: $st['arrival_time']), (int) $st['stop_sequence']];
        }
        foreach ($times as &$list) {
            usort($list, static fn ($a, $b) => $a[3] <=> $b[3]);
            $list = array_map(static fn ($x) => [$x[0], $x[1], $x[2]], $list);
        }
        unset($list);
        $calendar = $dates = [];
        foreach ($csv('calendar.txt') as $c) {
            $calendar[$c['service_id']] = ['days' => [(int) $c['monday'], (int) $c['tuesday'], (int) $c['wednesday'], (int) $c['thursday'], (int) $c['friday'], (int) $c['saturday'], (int) $c['sunday']],
                                           'start' => $c['start_date'], 'end' => $c['end_date']];
        }
        foreach ($csv('calendar_dates.txt') as $d) {
            $dates[$d['date']][$d['service_id']] = $d['exception_type'] === '1';
        }
        $feed = ['stops' => $stops, 'routes' => $routes, 'trips' => $trips, 'times' => $times, 'calendar' => $calendar, 'dates' => $dates,
                 'transfers' => self::transfers($stops), 'built_at' => gmdate('c')];
        self::write('feed.ser', serialize($feed));
        self::write('lines.json', json_encode(self::lines($feed, $csv('shapes.txt')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
        foreach (glob(self::dir() . '/day-*.ser') ?: [] as $old) {
            unlink($old);
        }
        self::$feed = null;

        return sprintf('%d lignes, %d arrêts, %d courses', count($routes), count($stops), count($times));
    }

    public static function feed(): array
    {
        return self::$feed ??= unserialize((string) file_get_contents(self::dir() . '/feed.ser'), ['allowed_classes' => false]);
    }

    public static function linesJson(): ?string
    {
        $f = self::dir() . '/lines.json';

        return is_file($f) ? (string) file_get_contents($f) : null;
    }

    /**
     * Connexions d'une journée (date de Paris AAAAMMJJ), triées par départ, en secondes depuis minuit ce jour-là.
     * Les courses de la veille qui passent minuit (horaires ≥ 24:00) sont incluses.
     * @return array{dep: int[], arr: int[], from: int[], to: int[], trip: int[]}
     */
    public static function day(string $ymd): array
    {
        $file = self::dir() . "/day-{$ymd}.ser";
        if (is_file($file) && filemtime($file) >= filemtime(self::dir() . '/feed.ser')) {
            return unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        }
        $feed = self::feed();
        $prev = (new \DateTimeImmutable($ymd))->modify('-1 day')->format('Ymd');
        $active = [$ymd => self::services($feed, $ymd), $prev => self::services($feed, $prev)];
        $list = [];
        foreach ($feed['times'] as $tripIdx => $stopTimes) {
            $service = $feed['trips'][$tripIdx]['service'];
            foreach ([$ymd => 0, $prev => -86400] as $d => $shift) {
                if (!isset($active[$d][$service])) {
                    continue;
                }
                for ($i = 0, $n = count($stopTimes) - 1; $i < $n; $i++) {
                    $dep = $stopTimes[$i][2] + $shift;
                    if ($dep >= 0) {
                        $list[] = [$dep, $stopTimes[$i + 1][1] + $shift, $stopTimes[$i][0], $stopTimes[$i + 1][0], $tripIdx];
                    }
                }
            }
        }
        usort($list, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $day = ['dep' => array_column($list, 0), 'arr' => array_column($list, 1), 'from' => array_column($list, 2), 'to' => array_column($list, 3), 'trip' => array_column($list, 4)];
        self::write("day-{$ymd}.ser", serialize($day));
        foreach (glob(self::dir() . '/day-*.ser') ?: [] as $old) {
            if (filemtime($old) < time() - 3 * 86400) {
                unlink($old);
            }
        }

        return $day;
    }

    /** Services actifs ce jour-là (calendar + exceptions calendar_dates) */
    private static function services(array $feed, string $ymd): array
    {
        $dow = (int) (new \DateTimeImmutable($ymd))->format('N') - 1;
        $out = [];
        foreach ($feed['calendar'] as $id => $c) {
            if ($c['days'][$dow] === 1 && $ymd >= $c['start'] && $ymd <= $c['end']) {
                $out[$id] = true;
            }
        }
        foreach ($feed['dates'][$ymd] ?? [] as $id => $added) {
            if ($added) {
                $out[$id] = true;
            } else {
                unset($out[$id]);
            }
        }

        return $out;
    }

    /** Correspondances à pied entre arrêts proches (grille de ~300 m) : arrêt => [[arrêt voisin, secondes], ...] */
    private static function transfers(array $stops): array
    {
        $grid = [];
        foreach ($stops as $i => [, $lat, $lon]) {
            $grid[(int) floor($lat / 0.003)][(int) floor($lon / 0.004)][] = $i;
        }
        $out = [];
        foreach ($stops as $i => [, $lat, $lon]) {
            [$gy, $gx] = [(int) floor($lat / 0.003), (int) floor($lon / 0.004)];
            for ($y = $gy - 1; $y <= $gy + 1; $y++) {
                for ($x = $gx - 1; $x <= $gx + 1; $x++) {
                    foreach ($grid[$y][$x] ?? [] as $j) {
                        if ($j !== $i && ($d = self::distance($lat, $lon, $stops[$j][1], $stops[$j][2])) <= self::TRANSFER_RADIUS) {
                            $out[$i][] = [$j, self::walkSeconds($d)];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /** Une entrée par ligne : tracé de la course la plus longue (aller), simplifié, et ses arrêts dans l'ordre */
    private static function lines(array $feed, \Generator $shapesCsv): array
    {
        $best = [];   // ligne => course de référence (le plus d'arrêts, sens aller)
        foreach ($feed['times'] as $tripIdx => $list) {
            $t = $feed['trips'][$tripIdx];
            $key = $t['route'];
            $score = count($list) * 2 + ($t['dir'] === 0 ? 1 : 0);
            if (!isset($best[$key]) || $score > $best[$key][1]) {
                $best[$key] = [$tripIdx, $score];
            }
        }
        $wanted = [];
        foreach ($best as [$tripIdx]) {
            $wanted[$feed['trips'][$tripIdx]['shape']] = [];
        }
        foreach ($shapesCsv as $p) {
            if (isset($wanted[$p['shape_id']])) {
                $wanted[$p['shape_id']][(int) $p['shape_pt_sequence']] = [round((float) $p['shape_pt_lat'], 6), round((float) $p['shape_pt_lon'], 6)];
            }
        }
        $lines = [];
        foreach ($best as $routeId => [$tripIdx]) {
            $r = $feed['routes'][$routeId];
            $shape = $wanted[$feed['trips'][$tripIdx]['shape']] ?? [];
            ksort($shape);
            $lines[] = [
                'id' => (string) $routeId, 'name' => $r['name'], 'mode' => $r['mode'], 'color' => $r['color'], 'text_color' => $r['text'], 'sort' => $r['sort'],
                'shape' => self::simplify(array_values($shape), 0.00004),
                'stops' => array_map(static fn ($st) => ['name' => $feed['stops'][$st[0]][0], 'latitude' => $feed['stops'][$st[0]][1], 'longitude' => $feed['stops'][$st[0]][2]],
                    $feed['times'][$tripIdx]),
            ];
        }
        usort($lines, static fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return array_map(static fn ($l) => array_diff_key($l, ['sort' => 1]), $lines);
    }

    /** Douglas-Peucker (tolérance en degrés) */
    private static function simplify(array $pts, float $eps): array
    {
        if (count($pts) < 3) {
            return $pts;
        }
        [$a, $b] = [$pts[0], $pts[count($pts) - 1]];
        $max = 0.0;
        $index = 0;
        for ($i = 1, $n = count($pts) - 1; $i < $n; $i++) {
            $dx = $b[1] - $a[1];
            $dy = $b[0] - $a[0];
            $len = sqrt($dx * $dx + $dy * $dy) ?: 1e-12;
            $d = abs($dy * $pts[$i][1] - $dx * $pts[$i][0] + $b[1] * $a[0] - $b[0] * $a[1]) / $len;
            if ($d > $max) {
                [$max, $index] = [$d, $i];
            }
        }
        if ($max <= $eps) {
            return [$a, $b];
        }

        return array_merge(array_slice(self::simplify(array_slice($pts, 0, $index + 1), $eps), 0, -1), self::simplify(array_slice($pts, $index), $eps));
    }

    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Marche : 1,2 m/s, détour de 25 % par rapport au vol d'oiseau */
    public static function walkSeconds(float $meters): int
    {
        return (int) ceil($meters * 1.25 / 1.2);
    }

    private static function write(string $name, string $data): void
    {
        $tmp = self::dir() . "/{$name}." . getmypid();
        file_put_contents($tmp, $data);
        rename($tmp, self::dir() . "/{$name}");
    }
}
