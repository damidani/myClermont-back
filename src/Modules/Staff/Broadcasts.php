<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Modules\Notifications\Notices;
use App\Support\Mailer;

/**
 * Envois groupés de l'équipe (c06e670d) : push (boîte de l'app + APNs/FCM, kind « system ») et / ou e-mail,
 * à une audience filtrée. L'e-mail ne part qu'aux comptes qui ont accepté les communications (marketing_opt_in).
 *
 * Audience : { platforms?: [ios, android], cities?: [..], postalCodes?: [..], neighborhoods?: [ids], activeWithinDays?, createdWithinDays?,
 *              plus?: bool, staffOnly?: bool, genders?: [..], favoriteCategories?: [MapCategory], favoritePlaceKeys?: [..], minPoints? }
 */
final class Broadcasts
{
    public const FILTERS = ['platforms', 'cities', 'postal_codes', 'neighborhoods', 'active_within_days', 'created_within_days', 'plus', 'staff_only', 'genders',
                            'favorite_categories', 'favorite_place_keys', 'min_points'];

    /** @return array{0: string, 1: array} clause WHERE (alias u) et paramètres */
    public static function where(array $a): array
    {
        $w = ["u.status = 'active'"];
        $p = [];
        $in = static function (string $col, array $values, string $prefix) use (&$w, &$p): void {
            $values = array_values(array_filter($values, static fn ($v) => is_scalar($v) && $v !== ''));
            if ($values === []) {
                return;
            }
            $keys = [];
            foreach ($values as $i => $v) {
                $keys[] = ":{$prefix}{$i}";
                $p["{$prefix}{$i}"] = $v;
            }
            $w[] = "{$col} IN (" . implode(',', $keys) . ')';
        };
        if (!empty($a['platforms'])) {
            $keys = [];
            foreach (array_values(array_intersect((array) $a['platforms'], ['ios', 'android'])) as $i => $pf) {
                $keys[] = ":pf{$i}";
                $p["pf{$i}"] = $pf;
            }
            if ($keys !== []) {
                $w[] = "EXISTS (SELECT 1 FROM devices d WHERE d.user_id = u.id AND d.status = 'active' AND d.platform IN (" . implode(',', $keys) . '))';
            }
        }
        $in('u.city', (array) ($a['cities'] ?? []), 'ci');
        $in('u.postal_code', (array) ($a['postal_codes'] ?? []), 'pc');
        $in('u.neighborhood_id', array_map('intval', (array) ($a['neighborhoods'] ?? [])), 'nb');
        $in('u.gender', (array) ($a['genders'] ?? []), 'ge');
        if (!empty($a['active_within_days'])) {
            $w[] = "EXISTS (SELECT 1 FROM devices d2 WHERE d2.user_id = u.id AND d2.last_seen_at > UTC_TIMESTAMP() - INTERVAL :act DAY)";
            $p['act'] = max(1, (int) $a['active_within_days']);
        }
        if (!empty($a['created_within_days'])) {
            $w[] = 'u.created_at > UTC_TIMESTAMP() - INTERVAL :cr DAY';
            $p['cr'] = max(1, (int) $a['created_within_days']);
        }
        if (isset($a['plus']) && is_bool($a['plus'])) {
            $w[] = $a['plus'] ? 'u.premium_until > UTC_TIMESTAMP()' : '(u.premium_until IS NULL OR u.premium_until <= UTC_TIMESTAMP())';
        }
        if (!empty($a['staff_only'])) {
            $w[] = 'u.is_staff = 1';
        }
        if (!empty($a['min_points'])) {
            $w[] = 'u.points >= :pts';
            $p['pts'] = (int) $a['min_points'];
        }
        $favs = array_values(array_filter((array) ($a['favorite_place_keys'] ?? []), 'is_string'));
        if ($favs !== []) {
            $keys = [];
            foreach ($favs as $i => $k) {
                $keys[] = ":fk{$i}";
                $p["fk{$i}"] = $k;
            }
            $w[] = 'EXISTS (SELECT 1 FROM app_favorites f WHERE f.user_id = u.id AND f.fav_key IN (' . implode(',', $keys) . '))';
        }
        $cats = array_values(array_filter((array) ($a['favorite_categories'] ?? []), 'is_string'));
        if ($cats !== []) {
            $keys = [];
            foreach ($cats as $i => $c) {
                $keys[] = ":fc{$i}";
                $p["fc{$i}"] = $c;
            }
            $w[] = "EXISTS (SELECT 1 FROM favorites f2 JOIN places pl ON pl.uid = f2.target_id AND f2.type = 'place'
                            WHERE f2.user_id = u.id AND pl.app_category IN (" . implode(',', $keys) . '))';
        }

        return [implode(' AND ', $w), $p];
    }

    /** { total, push (appareil avec jeton et notifications activées), email (opt-in) } */
    public static function preview(array $audience): array
    {
        [$where, $p] = self::where($audience);
        try {
            $r = Database::fetch("SELECT COUNT(*) AS total, SUM(u.notifications_enabled = 1 AND EXISTS (SELECT 1 FROM devices d WHERE d.user_id = u.id AND d.push_token IS NOT NULL AND d.status = 'active')) AS push,
                                         SUM(u.marketing_opt_in = 1 AND u.email IS NOT NULL) AS email FROM users u WHERE {$where}", $p);
        } catch (\PDOException) {
            throw new ValidationException(['audience' => 'Filtre d\'audience invalide.']);
        }

        return ['total' => (int) $r['total'], 'push' => (int) $r['push'], 'email' => (int) $r['email']];
    }

    /** Audience validée (clés snake_case reçues du contrat) */
    public static function audience(mixed $a): array
    {
        if (!is_array($a)) {
            return [];
        }

        return array_intersect_key($a, array_flip(self::FILTERS));
    }

    /** Envoi effectif (cron broadcasts:send) : renvoie [destinataires, push, e-mails] */
    public static function send(array $b): array
    {
        Database::execute("UPDATE broadcasts SET status = 'envoi' WHERE id = :id", ['id' => $b['id']]);
        [$where, $p] = self::where(json_decode($b['audience'], true) ?: []);
        $channels = explode(',', $b['channels']);
        $target = $b['target'] ? json_decode($b['target'], true) : null;
        $settings = Settings::get('mail');
        $n = $pushed = $mailed = 0;
        $lastId = 0;
        do {
            $rows = Database::fetchAll("SELECT u.id, u.email, u.first_name, u.marketing_opt_in FROM users u WHERE {$where} AND u.id > :last ORDER BY u.id LIMIT 500", $p + ['last' => $lastId]);
            foreach ($rows as $u) {
                $lastId = (int) $u['id'];
                $n++;
                if (in_array('push', $channels, true) && Notices::send((int) $u['id'], 'system', $b['title'], $b['body'], $target) !== null) {
                    $pushed++;
                }
                if (in_array('email', $channels, true) && (int) $u['marketing_opt_in'] === 1 && $u['email']) {
                    $body = str_replace('{prenom}', (string) ($u['first_name'] ?: ''), (string) ($b['email_body'] ?: $b['body']))
                          . "\n\n" . ($settings['footer'] ?? '') . "\nPour ne plus recevoir ces e-mails : Profil › Confidentialité dans l'app My Clermont.";
                    try {
                        (new Mailer())->send($u['email'], (string) ($b['email_subject'] ?: $b['title']), $body, $settings['reply_to'] ?? null) && $mailed++;
                    } catch (\Throwable) {
                    }
                }
            }
        } while (count($rows) === 500);
        Database::execute("UPDATE broadcasts SET status = 'envoye', recipients = :n, pushed = :p, emailed = :m, sent_at = :d WHERE id = :id",
            ['n' => $n, 'p' => $pushed, 'm' => $mailed, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $b['id']]);

        return [$n, $pushed, $mailed];
    }
}
