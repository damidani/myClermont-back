<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Transit;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 *   GET /transit/journeys?fromLat=&fromLon=&toLat=&toLon=&datetime=&arriveBy=   → { journeys: [...] } (5b642919)
 *   GET /transit/lines                                                         → { lines: [{ id, name, mode, color, textColor, shape, stops }] } (4220897f §4)
 */
final class TransitController
{
    public function journeys(Request $request): Response
    {
        $q = $request->query();
        $num = static function (string $k, float $min, float $max) use ($q): float {
            $v = $q[$k] ?? null;
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                throw new ValidationException([$k => 'Coordonnée attendue.']);
            }

            return (float) $v;
        };
        [$fLat, $fLon, $tLat, $tLon] = [$num('from_lat', -90, 90), $num('from_lon', -180, 180), $num('to_lat', -90, 90), $num('to_lon', -180, 180)];
        try {
            $at = isset($q['datetime']) && $q['datetime'] !== '' ? new \DateTimeImmutable((string) $q['datetime']) : new \DateTimeImmutable('now');
        } catch (\Exception) {
            throw new ValidationException(['datetime' => 'Date ISO 8601 attendue, ex. 2026-11-04T08:30:00Z.']);
        }
        if (!GtfsFeed::available()) {
            return Response::success(['journeys' => []]);
        }

        return Response::success(['journeys' => (new TransitPlanner())->journeys($fLat, $fLon, $tLat, $tLon, $at, in_array(strtolower((string) ($q['arrive_by'] ?? 'false')), ['true', '1'], true))]);
    }

    public function lines(Request $request): Response
    {
        $json = GtfsFeed::linesJson() ?? throw new HttpException(503, 'transit_unavailable', 'Lignes T2C pas encore importées.');

        return Response::success(['lines' => json_decode($json, true)])->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
