<?php

declare(strict_types=1);

namespace App\Modules\Places;

/**
 * « Ouvert maintenant » à partir des horaires structurés :
 *   {"always_open": true}
 *   {"mon": [["09:00","12:00"],["14:00","19:00"]], "tue": [...], ..., "sun": []}
 * Les plages qui passent minuit sont acceptées (["19:00","02:00"]).
 */
final class OpeningHours
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @return array{state: 'open'|'closed'|'unknown', label: ?string}
     */
    public static function status(?string $json, \DateTimeImmutable $now): array
    {
        $hours = $json === null ? null : json_decode($json, true);
        if (!is_array($hours)) {
            return ['state' => 'unknown', 'label' => null];
        }
        if (!empty($hours['always_open'])) {
            return ['state' => 'open', 'label' => 'Ouvert 24 h/24'];
        }

        $today = self::DAYS[(int) $now->format('w')];
        $yesterday = self::DAYS[((int) $now->format('w') + 6) % 7];
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');

        // Plage d'hier qui déborde après minuit
        foreach ((array) ($hours[$yesterday] ?? []) as [$from, $to]) {
            if (self::toMin($to) < self::toMin($from) && $minutes < self::toMin($to)) {
                return ['state' => 'open', 'label' => 'Ouvert · ferme à ' . self::pretty($to)];
            }
        }
        $next = null;
        foreach ((array) ($hours[$today] ?? []) as [$from, $to]) {
            [$f, $t] = [self::toMin($from), self::toMin($to)];
            $overnight = $t < $f;
            if ($minutes >= $f && ($overnight || $minutes < $t)) {
                return ['state' => 'open', 'label' => 'Ouvert · ferme à ' . self::pretty($to)];
            }
            if ($minutes < $f && ($next === null || $f < self::toMin($next))) {
                $next = $from;
            }
        }

        return ['state' => 'closed', 'label' => $next !== null ? 'Fermé · ouvre à ' . self::pretty($next) : 'Fermé'];
    }

    private static function toMin(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    /** "09:00" → « 9 h » ; "18:30" → « 18 h 30 » */
    private static function pretty(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h . "\u{00A0}h" . ($m > 0 ? sprintf("\u{00A0}%02d", $m) : '');
    }
}
