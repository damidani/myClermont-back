<?php

declare(strict_types=1);

namespace App\Modules\Places;

final class Geo
{
    /** Distance en mètres (formule de haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** « 120 m », « 1,4 km », « 12 km » */
    public static function label(int $meters): string
    {
        return match (true) {
            $meters < 1000  => (int) (round($meters / 10) * 10) . "\u{00A0}m",
            $meters < 10000 => number_format($meters / 1000, 1, ',', '') . "\u{00A0}km",
            default         => (int) round($meters / 1000) . "\u{00A0}km",
        };
    }

    /** "45.7773,3.0816" → [lat, lng] ou null */
    public static function parse(mixed $value): ?array
    {
        if (!is_string($value) || !preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', $value, $m)) {
            return null;
        }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }
}
