<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Boîte de notifications de l'app (demande iOS 4890352c) :
 *   GET /me/notifications?cursor=&limit=          → { items: AppNotice[], nextCursor, unreadCount }, 90 jours
 *   POST /me/notifications/read { ids }           → 204
 *   POST /me/notifications/read-all               → 204
 *   DELETE /me/notifications/{id}                 → 204
 *   GET | PUT /me/notification-preferences        → { enabled, categories, quietHours, timeZone }
 */
final class NoticeController
{
    public function index(Request $request): Response
    {
        $userId = (int) $request->attributes['user_id'];
        $q = $request->query();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 30)));
        $params = ['u' => $userId, 'd' => gmdate('Y-m-d H:i:s', time() - 90 * 86400)];
        $where = 'user_id = :u AND created_at > :d';
        if (isset($q['cursor']) && ctype_digit((string) $q['cursor'])) {
            $where .= ' AND id < :c';
            $params['c'] = (int) $q['cursor'];
        }
        // Une notification groupée remonte en tête quand elle change : tri par date puis id
        $rows = Database::fetchAll("SELECT * FROM user_notices WHERE {$where} ORDER BY id DESC LIMIT " . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        usort($rows, static fn ($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return Response::success([
            'items' => array_map([Notices::class, 'present'], $rows),
            'next_cursor' => $more ? (string) min(array_map(static fn ($r) => (int) $r['id'], $rows)) : null,
            'unread_count' => Notices::unread($userId),
        ]);
    }

    public function read(Request $request): Response
    {
        $ids = $request->json()['ids'] ?? null;
        if (!is_array($ids) || count($ids) > 200) {
            throw new ValidationException(['ids' => 'Liste d\'identifiants attendue (200 au plus).']);
        }
        $uids = array_values(array_filter(array_map(static fn ($i) => is_string($i) ? preg_replace('/^ntf_/', '', $i) : null, $ids),
            static fn ($u) => is_string($u) && preg_match('/^[0-9a-f-]{36}$/', $u)));
        if ($uids !== []) {
            $in = implode(',', array_map(static fn ($i) => ':i' . $i, array_keys($uids)));
            Database::execute("UPDATE user_notices SET read_at = :d WHERE user_id = :u AND read_at IS NULL AND uid IN ({$in})",
                ['d' => gmdate('Y-m-d H:i:s'), 'u' => $request->attributes['user_id']] + array_combine(array_map(static fn ($i) => 'i' . $i, array_keys($uids)), $uids));
        }

        return Response::noContent();
    }

    public function readAll(Request $request): Response
    {
        Database::execute('UPDATE user_notices SET read_at = :d WHERE user_id = :u AND read_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'u' => $request->attributes['user_id']]);

        return Response::noContent();
    }

    public function destroy(Request $request): Response
    {
        $uid = preg_replace('/^ntf_/', '', (string) $request->param('id'));
        if (Database::execute('DELETE FROM user_notices WHERE uid = :id AND user_id = :u', ['id' => $uid, 'u' => $request->attributes['user_id']]) === 0) {
            throw new HttpException(404, 'notification_not_found', 'Notification introuvable.');
        }

        return Response::noContent();
    }

    public function preferences(Request $request): Response
    {
        $u = Database::fetch('SELECT notifications_enabled, notification_prefs FROM users WHERE id = :u', ['u' => $request->attributes['user_id']]);

        return Response::success(Notices::prefs($u['notification_prefs'] ?? null, (bool) ($u['notifications_enabled'] ?? 1)));
    }

    public function updatePreferences(Request $request): Response
    {
        $json = $request->json();
        $current = Notices::prefs(Database::fetch('SELECT notification_prefs FROM users WHERE id = :u', ['u' => $request->attributes['user_id']])['notification_prefs'] ?? null);
        $errors = [];
        if (array_key_exists('enabled', $json)) {
            is_bool($json['enabled']) ? $current['enabled'] = $json['enabled'] : $errors['enabled'] = 'Booléen attendu.';
        }
        foreach ((array) ($json['categories'] ?? []) as $k => $v) {
            if (isset(Notices::DEFAULT_PREFS['categories'][$k]) && is_bool($v)) {
                $current['categories'][$k] = $v; // famille inconnue : ignorée (version plus récente de l'app)
            }
        }
        if (isset($json['quiet_hours']) && is_array($json['quiet_hours'])) {
            $qh = $json['quiet_hours'];
            foreach (['start', 'end'] as $k) {
                if (isset($qh[$k])) {
                    preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $qh[$k]) ? $current['quiet_hours'][$k] = $qh[$k] : $errors["quiet_hours.{$k}"] = 'Heure HH:MM attendue.';
                }
            }
            if (isset($qh['enabled'])) {
                $current['quiet_hours']['enabled'] = (bool) $qh['enabled'];
            }
        }
        if (isset($json['time_zone'])) {
            in_array($json['time_zone'], \DateTimeZone::listIdentifiers(), true) ? $current['time_zone'] = $json['time_zone'] : $errors['time_zone'] = 'Fuseau horaire inconnu.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::execute('UPDATE users SET notification_prefs = :p, notifications_enabled = :e, updated_at = :d WHERE id = :u', [
            'p' => json_encode($current), 'e' => $current['enabled'] ? 1 : 0, 'd' => gmdate('Y-m-d H:i:s'), 'u' => $request->attributes['user_id'],
        ]);

        return Response::success($current);
    }
}
