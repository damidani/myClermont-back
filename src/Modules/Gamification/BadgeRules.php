<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

/**
 * Logique pure (sans base de données) : facile à tester et à faire évoluer.
 */
final class BadgeRules
{
    /**
     * Badges à débloquer compte tenu des compteurs actuels.
     *
     * @param array<string, array> $catalog  config gamification.badges
     * @param array<string, int>   $stats    compteurs de l'utilisateur
     * @param list<string>         $earned   codes déjà obtenus
     * @return list<string>
     */
    public static function unlockable(array $catalog, array $stats, array $earned): array
    {
        $codes = [];
        foreach ($catalog as $code => $badge) {
            if (in_array($code, $earned, true)) {
                continue;
            }
            if (($stats[$badge['rule']['stat']] ?? 0) >= $badge['rule']['min']) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Stats surveillées par au moins un badge (pour ne relire que celles-ci). */
    public static function watchedStats(array $catalog): array
    {
        return array_values(array_unique(array_map(static fn (array $b) => $b['rule']['stat'], $catalog)));
    }

    public static function applyMultiplier(int $points, bool $premium, float $multiplier): int
    {
        return $premium && $points > 0 ? (int) round($points * $multiplier) : $points;
    }
}
