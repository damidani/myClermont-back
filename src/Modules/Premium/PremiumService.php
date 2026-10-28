<?php

declare(strict_types=1);

namespace App\Modules\Premium;

use App\Core\Database;
use App\Core\Events;

/**
 * Statut premium = users.premium_until dans le futur.
 * Une seule colonne de date : renouveler un abonnement = repousser la date.
 */
final class PremiumService
{
    public static function isActive(array $user): bool
    {
        return $user['premium_until'] !== null && strtotime($user['premium_until'] . ' UTC') > time();
    }

    public static function isActiveById(int $userId): bool
    {
        $row = Database::fetch('SELECT premium_until FROM users WHERE id = :u', ['u' => $userId]);

        return $row !== null && self::isActive($row);
    }

    /**
     * Ajoute $days jours de premium (à partir de maintenant, ou de la fin du premium en cours).
     *
     * @param string $source origine : 'admin', 'apple', 'google', 'points', 'promo'...
     * @return string nouvelle date de fin (UTC, Y-m-d H:i:s)
     */
    public function grant(int $userId, int $days, string $source): string
    {
        Database::execute(
            'UPDATE users
             SET premium_until = DATE_ADD(GREATEST(COALESCE(premium_until, :now1), :now2), INTERVAL :days DAY),
                 premium_source = :source
             WHERE id = :u',
            ['now1' => gmdate('Y-m-d H:i:s'), 'now2' => gmdate('Y-m-d H:i:s'), 'days' => $days, 'source' => $source, 'u' => $userId],
        );

        $until = (string) Database::fetch('SELECT premium_until FROM users WHERE id = :u', ['u' => $userId])['premium_until'];
        Events::dispatch('premium.granted', ['user_id' => $userId, 'days' => $days, 'source' => $source, 'until' => $until]);

        return $until;
    }

    public function revoke(int $userId): void
    {
        Database::execute('UPDATE users SET premium_until = NULL, premium_source = NULL WHERE id = :u', ['u' => $userId]);
    }
}
