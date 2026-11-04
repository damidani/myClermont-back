<?php

declare(strict_types=1);

namespace App\Modules\Premium;

use App\Core\Database;
use App\Modules\Notifications\Notices;

/**
 * My Clermont+ offert par l'équipe (back-office /admin/plus) : durée en mois (0 = sans limite).
 * users.premium_until = le plus lointain entre l'abonnement App Store actif et le cadeau, recalculé à chaque changement.
 */
final class PlusGifts
{
    public const DURATIONS = [1 => '1 mois', 3 => '3 mois', 6 => '6 mois', 12 => '1 an', 0 => 'Sans limite'];
    private const FOREVER = '9999-12-31 00:00:00';

    public static function offer(array $user, int $months, string $by, string $note = ''): string
    {
        if (!array_key_exists($months, self::DURATIONS)) {
            throw new \DomainException('Durée invalide.');
        }
        $current = $user['plus_gift_until'] ?? null;
        $from = $current !== null && strtotime($current . ' UTC') > time() ? strtotime($current . ' UTC') : time();
        $until = $months === 0 || $current === self::FOREVER ? self::FOREVER : gmdate('Y-m-d H:i:s', strtotime("+{$months} months", $from));
        Database::execute('UPDATE users SET plus_gift_until = :u, plus_gift_by = :by, plus_gift_note = :n WHERE id = :id',
            ['u' => $until, 'by' => mb_substr($by, 0, 120), 'n' => mb_substr(trim($note), 0, 300) ?: null, 'id' => $user['id']]);
        self::sync((int) $user['id']);
        Notices::send((int) $user['id'], 'system', 'My Clermont+ t\'est offert 🎁', $until === self::FOREVER
            ? 'L\'équipe My Clermont t\'offre My Clermont+ : Clermont IA illimitée et badge Membre.'
            : 'L\'équipe My Clermont t\'offre My Clermont+ jusqu\'au ' . self::date($until) . ' : Clermont IA illimitée et badge Membre.');

        return $until;
    }

    public static function revoke(array $user): void
    {
        Database::execute('UPDATE users SET plus_gift_until = NULL, plus_gift_by = NULL, plus_gift_note = NULL WHERE id = :id', ['id' => $user['id']]);
        self::sync((int) $user['id']);
    }

    /** Recalcule premium_until : abonnement App Store actif ou en grâce, et cadeau */
    public static function sync(int $userId): void
    {
        $u = Database::fetch('SELECT plus_gift_until, premium_source FROM users WHERE id = :id', ['id' => $userId]);
        $apple = Database::fetch("SELECT MAX(expires_at) AS e FROM subscriptions WHERE user_id = :u AND store = 'apple' AND status IN ('active','grace')", ['u' => $userId])['e'] ?? null;
        $gift = $u['plus_gift_until'] ?? null;
        $until = max((string) $apple, (string) $gift) ?: null;
        $source = $until === null ? null : ($gift !== null && $until === $gift ? 'gift' : 'apple');
        Database::execute('UPDATE users SET premium_until = :p, premium_source = :s, updated_at = UTC_TIMESTAMP() WHERE id = :id', ['p' => $until, 's' => $source, 'id' => $userId]);
    }

    public static function forever(?string $until): bool
    {
        return $until === self::FOREVER;
    }

    private static function date(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }
}
