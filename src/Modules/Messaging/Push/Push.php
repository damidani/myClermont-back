<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Core\Config;
use App\Core\Database;

/**
 * Mise en file des notifications (envoyées par push:send).
 *
 * Contenu (spec v1) : titre, texte, thread-id par thème, et deeplink (myclermont://event/<id>, myclermont://place/<id>,
 * myclermont://community/post/<id>…). Les thèmes « annonce » ne partent jamais entre 22 h et 8 h (heure de Paris).
 */
final class Push
{
    /**
     * @param array{deeplink?: string, thread_id?: string, collapse_key?: string, once_key?: string, delay?: int} $options
     *        once_key : la notification n'est mise en file qu'une seule fois (ex. rappel d'un événement)
     *        delay    : regroupement (secondes) — une notification identique déjà en attente n'est pas dupliquée
     */
    public static function toUser(int $userId, string $topic, string $title, string $body, array $options = []): bool
    {
        return self::queue(['user_id' => $userId, 'device_id' => null], $topic, $title, $body, $options);
    }

    public static function toDevice(int $deviceId, string $topic, string $title, string $body, array $options = []): bool
    {
        return self::queue(['user_id' => null, 'device_id' => $deviceId], $topic, $title, $body, $options);
    }

    private static function queue(array $to, string $topic, string $title, string $body, array $o): bool
    {
        if (isset($o['once_key']) && Database::execute('INSERT IGNORE INTO push_sent_keys (k, created_at) VALUES (:k, :d)', ['k' => substr($o['once_key'], 0, 120), 'd' => gmdate('Y-m-d H:i:s')]) === 0) {
            return false;
        }
        $thread = $o['thread_id'] ?? $topic;
        if (isset($o['delay']) && Database::fetch(
            "SELECT 1 FROM push_outbox WHERE status = 'pending' AND topic = :t AND thread_id = :th AND (user_id = :u OR device_id = :d)",
            ['t' => $topic, 'th' => $thread, 'u' => $to['user_id'] ?? 0, 'd' => $to['device_id'] ?? 0],
        )) {
            return false; // regroupé avec la notification déjà en attente
        }
        $sendAfter = isset($o['delay']) ? time() + (int) $o['delay'] : time();
        if (in_array($topic, (array) Config::get('push.quiet_topics', []), true)) {
            $sendAfter = self::afterQuietHours($sendAfter);
        }

        Database::execute(
            'INSERT INTO push_outbox (user_id, device_id, topic, title, body, data, collapse_key, thread_id, send_after, created_at)
             VALUES (:u, :dev, :topic, :t, :b, :data, :k, :th, :after, :c)',
            [
                'u' => $to['user_id'], 'dev' => $to['device_id'], 'topic' => $topic, 't' => mb_substr($title, 0, 120), 'b' => mb_substr($body, 0, 250),
                'data' => json_encode(array_filter(['deeplink' => $o['deeplink'] ?? null])), 'k' => $o['collapse_key'] ?? null, 'th' => $thread,
                'after' => gmdate('Y-m-d H:i:s', $sendAfter), 'c' => gmdate('Y-m-d H:i:s'),
            ],
        );

        return true;
    }

    /** Pas d'annonce entre 22 h et 8 h (Paris) : reportée à 8 h. */
    public static function afterQuietHours(int $ts): int
    {
        [$from, $to] = (array) Config::get('push.quiet_hours', [22, 8]);
        $local = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $hour = (int) $local->format('G');
        if ($hour >= $from) {
            return $local->modify('+1 day')->setTime($to, 0)->getTimestamp();
        }
        if ($hour < $to) {
            return $local->setTime($to, 0)->getTimestamp();
        }

        return $ts;
    }
}
