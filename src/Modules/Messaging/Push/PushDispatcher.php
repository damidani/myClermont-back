<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Vide la file push_outbox (cron : php bin/console push:send, chaque minute).
 *
 * Destinataires : les appareils du compte (user_id) ou un appareil sans compte (device_id), avec un push_token.
 * Règles (spec v1) : le profil « notificationsEnabled = false » coupe tout ; un thème n'est envoyé qu'aux appareils
 * qui le suivent ; send_after reporte l'envoi (heures calmes, regroupement par 15 min).
 */
final class PushDispatcher
{
    public function dispatch(int $batch = 200): array
    {
        /** @var PushSender $sender */
        $class = (string) Config::get('messaging.push_sender', LogPushSender::class);
        $sender = new $class();
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $due = Database::fetchAll(
            "SELECT * FROM push_outbox WHERE status = 'pending' AND (send_after IS NULL OR send_after <= :now) ORDER BY id LIMIT :b",
            ['now' => gmdate('Y-m-d H:i:s'), 'b' => $batch],
        );
        foreach ($due as $push) {
            $devices = $push['device_id'] !== null
                ? Database::fetchAll(
                    "SELECT d.platform, d.push_token, d.topics FROM devices d LEFT JOIN users u ON u.id = d.user_id
                     WHERE d.id = :d AND d.status = 'active' AND d.push_token IS NOT NULL AND (u.id IS NULL OR u.notifications_enabled = 1)",
                    ['d' => $push['device_id']])
                : Database::fetchAll(
                    "SELECT d.platform, d.push_token, d.topics FROM devices d JOIN users u ON u.id = d.user_id
                     WHERE d.user_id = :u AND d.status = 'active' AND d.push_token IS NOT NULL AND u.notifications_enabled = 1 AND u.status = 'active'",
                    ['u' => $push['user_id']]);
            if ($push['topic'] !== null) {
                // Thème non suivi par l'appareil : pas de notification (topics NULL = l'app n'a rien choisi → tous les thèmes)
                $devices = array_values(array_filter($devices, static fn ($d) => $d['topics'] === null || in_array($push['topic'], explode(',', $d['topics']), true)));
            }
            if ($devices === []) {
                Database::execute("UPDATE push_outbox SET status = 'skipped' WHERE id = :id", ['id' => $push['id']]);
                $result['skipped']++;
                continue;
            }

            $data = json_decode((string) $push['data'], true) ?: [];
            if ($push['thread_id'] !== null) {
                $data['thread_id'] = $push['thread_id'];
            }
            try {
                $ok = $sender->send($devices, $push['title'], $push['body'], $data, $push['collapse_key']);
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
