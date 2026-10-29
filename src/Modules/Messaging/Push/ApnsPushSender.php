<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Security\AppleClient;

/**
 * Notifications iOS via APNs en HTTP/2 avec la clé .p8 (spec v1 › Notifications push) :
 * APNS_KEY_ID, APNS_TEAM_ID, APNS_KEY (contenu du .p8), sujet com.myclermont.app.
 * Un jeton refusé par Apple (410 Unregistered, 400 BadDeviceToken) est supprimé.
 */
final class ApnsPushSender
{
    public function send(string $token, string $title, string $body, array $data, ?string $collapseKey): bool
    {
        $payload = ['aps' => array_filter([
            'alert'     => ['title' => $title, 'body' => $body],
            'sound'     => 'default',
            'thread-id' => $data['thread_id'] ?? null,
        ])] + array_diff_key($data, ['thread_id' => 1]);

        $host = Config::get('push.apns.sandbox') ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $ch = curl_init($host . '/3/device/' . rawurlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => array_values(array_filter([
                'authorization: bearer ' . $this->jwt(),
                'apns-topic: ' . Config::get('push.apns.topic', 'com.myclermont.app'),
                'apns-push-type: alert',
                'apns-priority: 10',
                $collapseKey !== null ? 'apns-collapse-id: ' . substr($collapseKey, 0, 64) : null,
            ])),
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 200) {
            return true;
        }
        $reason = json_decode($response, true)['reason'] ?? '';
        if ($status === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            Database::execute('UPDATE devices SET push_token = NULL WHERE push_token = :t', ['t' => $token]);
        } else {
            Logger::warning('APNs : envoi refusé', ['status' => $status, 'reason' => $reason]);
        }

        return false;
    }

    /** Jeton du fournisseur (ES256), réutilisé 50 minutes comme le recommande Apple. */
    private function jwt(): string
    {
        $cached = Cache::store()->get('push:apns:jwt');
        if (is_string($cached)) {
            return $cached;
        }
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode(['alg' => 'ES256', 'kid' => Config::get('push.apns.key_id')])) . '.'
               . $b64(json_encode(['iss' => Config::get('push.apns.team_id'), 'iat' => time()]));
        $jwt = $input . '.' . $b64(AppleClient::es256((string) Config::get('push.apns.key'), $input));
        Cache::store()->set('push:apns:jwt', $jwt, 3000);

        return $jwt;
    }
}
