<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Vide la file push_outbox (cron : php bin/console push:send, chaque minute).
 * Les notifications sont envoyées aux appareils de l'utilisateur ayant un push_token
 * (renseigné par l'app via PATCH /v1/devices/me).
 */
final class PushDispatcher
{
    public function dispatch(int $batch = 200): array
    {
        /** @var PushSender $sender */
        $class = (string) Config::get('messaging.push_sender', LogPushSender::class);
        $sender = new $class();
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (Database::fetchAll("SELECT * FROM push_outbox WHERE status = 'pending' ORDER BY id LIMIT :b", ['b' => $batch]) as $push) {
            $devices = Database::fetchAll(
                "SELECT platform, push_token FROM devices WHERE user_id = :u AND status = 'active' AND push_token IS NOT NULL",
                ['u' => $push['user_id']],
            );
            if ($devices === []) {
                Database::execute("UPDATE push_outbox SET status = 'skipped' WHERE id = :id", ['id' => $push['id']]);
                $result['skipped']++;
                continue;
            }

            try {
                $ok = $sender->send($devices, $push['title'], $push['body'], json_decode((string) $push['data'], true) ?: [], $push['collapse_key']);
            } catch (\Throwable $e) {
                Logger::warning('Envoi push en échec', ['id' => $push['id'], 'error' => $e->getMessage()]);
                $ok = false;
            }
            $final = !$ok && (int) $push['attempts'] + 1 >= 3;
            Database::execute(
                'UPDATE push_outbox SET status = :s, attempts = attempts + 1, sent_at = :d WHERE id = :id',
                ['s' => $ok ? 'sent' : ($final ? 'failed' : 'pending'), 'd' => $ok ? gmdate('Y-m-d H:i:s') : null, 'id' => $push['id']],
            );
            $result[$ok ? 'sent' : 'failed']++;
        }

        // Nettoyage : historique conservé 7 jours
        Database::execute("DELETE FROM push_outbox WHERE status <> 'pending' AND created_at < :d", ['d' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);

        return $result;
    }
}
