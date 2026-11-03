<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Modules\Messaging\Push\Push;
use App\Security\Crypto;

/**
 * Notifications de l'app (demande iOS 4890352c) : chaque événement entre dans la boîte /me/notifications,
 * puis part en push si les préférences le permettent (famille activée, hors nuit, 8 par jour au plus hors photos et réponses).
 * pushed_at est renseigné quand le push est parti : sans lui, l'app affiche sa propre bannière.
 */
final class Notices
{
    /** kind => famille des préférences de l'app */
    public const FAMILIES = [
        'photo_approved' => 'photos', 'photo_rejected' => 'photos',
        'post_liked' => 'community', 'post_commented' => 'community', 'comment_replied' => 'community', 'comment_liked' => 'community',
        'mentioned' => 'community', 'post_published' => 'community', 'post_removed' => 'community',
        'review_published' => 'replies', 'review_removed' => 'replies', 'review_replied' => 'replies', 'classified_message' => 'replies',
        'request_replied' => 'replies', 'application_update' => 'replies',
        'favorite_story' => 'favorites', 'favorite_deal' => 'favorites', 'favorite_event' => 'favorites',
        'leaderboard_passed' => 'games', 'system' => null,
    ];

    public const DEFAULT_PREFS = [
        'enabled' => true,
        'categories' => ['photos' => true, 'community' => true, 'replies' => true, 'reminders' => true, 'favorites' => true, 'games' => true],
        'quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '08:00'],
        'time_zone' => 'Europe/Paris',
    ];

    private const DAILY_PUSHES = 8;
    private const GROUP_PUSH_GAP = 1800;

    /**
     * @param array{actor_id?: int, actor?: string, image_url?: string, group?: string, group_title?: \Closure, once_per?: array{0: string, 1: int}} $o
     *        group     : regroupe avec la notification non lue de même clé (j'aime) ; group_title(count) donne le titre groupé
     *        once_per  : [clé, secondes] — au plus une notification de cette clé par période (comment_replied : 1 par heure)
     */
    public static function send(int $userId, string $kind, string $title, string $body = '', ?array $target = null, array $o = []): ?int
    {
        if (isset($o['actor_id']) && (int) $o['actor_id'] === $userId) {
            return null; // jamais pour sa propre action
        }
        $user = Database::fetch("SELECT id, status, notifications_enabled, notification_prefs FROM users WHERE id = :u", ['u' => $userId]);
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        if (isset($o['once_per']) && Database::fetch('SELECT 1 FROM user_notices WHERE user_id = :u AND group_key = :g AND created_at > :d',
            ['u' => $userId, 'g' => substr($o['once_per'][0], 0, 80), 'd' => gmdate('Y-m-d H:i:s', time() - $o['once_per'][1])])) {
            return null;
        }
        $family = self::FAMILIES[$kind] ?? null;
        $title = mb_substr($title, 0, 160);
        $body = mb_substr($body, 0, 300);
        $groupKey = isset($o['group']) ? substr($o['group'], 0, 80) : (isset($o['once_per']) ? substr($o['once_per'][0], 0, 80) : null);

        $existing = isset($o['group']) ? Database::fetch(
            'SELECT id, uid, group_count, pushed_at FROM user_notices WHERE user_id = :u AND group_key = :g AND read_at IS NULL AND created_at > :d ORDER BY id DESC LIMIT 1',
            ['u' => $userId, 'g' => $groupKey, 'd' => gmdate('Y-m-d H:i:s', time() - 86400)],
        ) : null;
        if ($existing !== null) {
            $count = (int) $existing['group_count'] + 1;
            $title = isset($o['group_title']) ? mb_substr(($o['group_title'])($count), 0, 160) : $title;
            Database::execute('UPDATE user_notices SET title = :t, body = :b, actor = :a, group_count = :c, created_at = :d WHERE id = :id',
                ['t' => $title, 'b' => $body, 'a' => $o['actor'] ?? null, 'c' => $count, 'd' => $now, 'id' => $existing['id']]);
            $id = (int) $existing['id'];
            $uid = $existing['uid'];
            // 30 min au moins entre deux push d'un même groupe
            $push = $existing['pushed_at'] === null || strtotime($existing['pushed_at'] . ' UTC') < time() - self::GROUP_PUSH_GAP;
        } else {
            $uid = Crypto::uuid4();
            $id = Database::insert(
                'INSERT INTO user_notices (uid, user_id, kind, family, title, body, image_url, actor, target, group_key, created_at)
                 VALUES (:uid, :u, :k, :f, :t, :b, :i, :a, :tg, :g, :d)',
                ['uid' => $uid, 'u' => $userId, 'k' => $kind, 'f' => $family, 't' => $title, 'b' => $body, 'i' => $o['image_url'] ?? null,
                 'a' => $o['actor'] ?? null, 'tg' => $target === null ? null : json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'g' => $groupKey, 'd' => $now],
            );
            $push = true;
        }

        if ($push && self::mayPush($user, $family)) {
            Push::notice($userId, $id, $title, $body, $family ?? 'system', [
                'noticeId' => 'ntf_' . $uid, 'kind' => $kind, 'target' => $target,
            ], 'ntf_' . $uid);
        }

        return $id;
    }

    /** Préférences de l'app (valeurs par défaut complétées) */
    public static function prefs(?string $json, bool $enabled = true): array
    {
        $p = json_decode((string) $json, true);
        $p = is_array($p) ? $p : [];
        $out = array_replace_recursive(self::DEFAULT_PREFS, array_intersect_key($p, self::DEFAULT_PREFS));
        $out['categories'] = array_intersect_key($out['categories'], self::DEFAULT_PREFS['categories']);
        $out['enabled'] = (bool) $out['enabled'] && $enabled;

        return $out;
    }

    private static function mayPush(array $user, ?string $family): bool
    {
        $p = self::prefs($user['notification_prefs'], (bool) $user['notifications_enabled']);
        if (!$p['enabled'] || ($family !== null && empty($p['categories'][$family]))) {
            return false;
        }
        if ($p['quiet_hours']['enabled'] && self::isQuiet($p['quiet_hours']['start'], $p['quiet_hours']['end'], $p['time_zone'])) {
            return false; // la nuit : dans la boîte seulement
        }
        if (!in_array($family, ['photos', 'replies'], true)) {
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $sent = (int) (Database::fetch("SELECT COUNT(*) AS n FROM user_notices WHERE user_id = :u AND pushed_at >= :d AND (family IS NULL OR family NOT IN ('photos','replies'))",
                ['u' => $user['id'], 'd' => $today])['n'] ?? 0);
            if ($sent >= self::DAILY_PUSHES) {
                return false;
            }
        }

        return true;
    }

    public static function isQuiet(string $start, string $end, string $tz, ?\DateTimeImmutable $now = null): bool
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Europe/Paris');
        }
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($zone);
        $m = (int) $now->format('G') * 60 + (int) $now->format('i');
        $toMin = static fn (string $hm) => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        [$a, $b] = [$toMin($start), $toMin($end)];

        return $a === $b ? false : ($a < $b ? $m >= $a && $m < $b : $m >= $a || $m < $b);
    }

    /** AppNotice de l'app */
    public static function present(array $n): array
    {
        $iso = static fn (?string $d) => $d === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($d . ' UTC'));

        return [
            'id' => 'ntf_' . $n['uid'], 'kind' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'created_at' => $iso($n['created_at']),
            'read_at' => $iso($n['read_at']), 'pushed_at' => $iso($n['pushed_at']), 'image_url' => $n['image_url'], 'actor' => $n['actor'],
            'target' => $n['target'] === null ? null : json_decode($n['target'], true),
        ];
    }

    public static function unread(int $userId): int
    {
        return (int) (Database::fetch('SELECT COUNT(*) AS n FROM user_notices WHERE user_id = :u AND read_at IS NULL', ['u' => $userId])['n'] ?? 0);
    }

    /** Pseudo d'un compte pour les textes (jamais l'e-mail) */
    public static function handle(int $userId): string
    {
        return \App\Modules\Account\Handles::display(Database::fetch('SELECT handle FROM users WHERE id = :u', ['u' => $userId])['handle'] ?? null);
    }

    public static function excerpt(string $text, int $max = 80): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . '…' : $text;
    }
}
