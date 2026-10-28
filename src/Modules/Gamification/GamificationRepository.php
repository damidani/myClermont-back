<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

use App\Core\Config;
use App\Core\Database;

final class GamificationRepository
{
    /** Marque un fait comme comptabilisé. false s'il l'était déjà (idempotence). */
    public function claimEvent(int $userId, string $eventKey): bool
    {
        return Database::execute(
            'INSERT IGNORE INTO user_events (user_id, event_key, created_at) VALUES (:u, :k, :d)',
            ['u' => $userId, 'k' => $eventKey, 'd' => gmdate('Y-m-d H:i:s')],
        ) > 0;
    }

    /** +1 sur chaque compteur, en une seule requête. */
    public function incrementStats(int $userId, array $stats): void
    {
        if ($stats === []) {
            return;
        }

        $values = [];
        $params = [];
        foreach (array_values(array_unique($stats)) as $i => $stat) {
            $values[] = "(:u{$i}, :s{$i}, 1)";
            $params["u{$i}"] = $userId;
            $params["s{$i}"] = $stat;
        }

        Database::execute(
            'INSERT INTO user_stats (user_id, stat, value) VALUES ' . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE value = value + 1',
            $params,
        );
    }

    /** @return array<string, int> */
    public function stats(int $userId, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $placeholders = [];
        $params = ['u' => $userId];
        foreach (array_values($names) as $i => $name) {
            $placeholders[] = ":s{$i}";
            $params["s{$i}"] = $name;
        }

        $rows = Database::fetchAll(
            'SELECT stat, value FROM user_stats WHERE user_id = :u AND stat IN (' . implode(', ', $placeholders) . ')',
            $params,
        );

        return array_map('intval', array_column($rows, 'value', 'stat'));
    }

    /** @return list<string> */
    public function earnedCodes(int $userId): array
    {
        return array_column(Database::fetchAll('SELECT badge_code FROM user_badges WHERE user_id = :u', ['u' => $userId]), 'badge_code');
    }

    public function awardBadge(int $userId, string $code): bool
    {
        return Database::execute(
            'INSERT IGNORE INTO user_badges (user_id, badge_code, earned_at) VALUES (:u, :c, :d)',
            ['u' => $userId, 'c' => $code, 'd' => gmdate('Y-m-d H:i:s')],
        ) > 0;
    }

    public function addPoints(int $userId, int $amount, string $reason): void
    {
        if ($amount === 0) {
            return;
        }

        Database::execute(
            'INSERT INTO point_transactions (user_id, amount, reason, created_at) VALUES (:u, :a, :r, :d)',
            ['u' => $userId, 'a' => $amount, 'r' => $reason, 'd' => gmdate('Y-m-d H:i:s')],
        );
        Database::execute(
            'UPDATE users SET points = GREATEST(0, CAST(points AS SIGNED) + :a) WHERE id = :u',
            ['a' => $amount, 'u' => $userId],
        );
    }

    /** Badges obtenus, prêts pour l'API (ordre du catalogue). */
    public function badgesOf(int $userId): array
    {
        $earned = array_column(
            Database::fetchAll('SELECT badge_code, earned_at FROM user_badges WHERE user_id = :u', ['u' => $userId]),
            'earned_at',
            'badge_code',
        );

        $badges = [];
        foreach ((array) Config::get('gamification.badges', []) as $code => $badge) {
            if (isset($earned[$code])) {
                $badges[] = self::presentBadge($code, $badge, $earned[$code]);
            }
        }

        return $badges;
    }

    public function pointsHistory(int $userId, int $limit, ?int $beforeId): array
    {
        return Database::fetchAll(
            'SELECT id, amount, reason, created_at FROM point_transactions
             WHERE user_id = :u AND id < :before ORDER BY id DESC LIMIT :limit',
            ['u' => $userId, 'before' => $beforeId ?? PHP_INT_MAX, 'limit' => $limit],
        );
    }

    public static function presentBadge(string $code, array $badge, ?string $earnedAt): array
    {
        return [
            'code'        => $code,
            'name'        => $badge['name'],
            'description' => $badge['description'],
            'earned_at'   => $earnedAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($earnedAt . ' UTC')),
        ];
    }
}
