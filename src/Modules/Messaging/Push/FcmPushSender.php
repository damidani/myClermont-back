<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Notifications Android via Firebase Cloud Messaging (API HTTP v1).
 * FCM_SERVICE_ACCOUNT : chemin du fichier JSON du compte de service Firebase (jamais dans le dépôt).
 */
final class FcmPushSender
{
    public function send(string $token, string $title, string $body, array $data, ?string $collapseKey): bool
    {
        $account = $this->account();
        $message = [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => array_map('strval', array_filter($data, 'is_scalar')),
            'android'      => array_filter(['priority' => 'HIGH', 'collapse_key' => $collapseKey]),
        ];
        $r = (new HttpClient(timeout: 10, retries: 1))->request(
            'POST',
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/messages:send',
            ['Authorization: Bearer ' . $this->accessToken($account), 'Content-Type: application/json'],
            json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        if ($r['status'] === 200) {
            return true;
        }
        $error = json_decode($r['body'], true)['error'] ?? [];
        if ($r['status'] === 404 || ($error['status'] ?? '') === 'NOT_FOUND' || str_contains($r['body'], 'UNREGISTERED')) {
            Database::execute('UPDATE devices SET push_token = NULL WHERE push_token = :t', ['t' => $token]);
        } else {
            Logger::warning('FCM : envoi refusé', ['status' => $r['status'], 'error' => $error['status'] ?? null]);
        }

        return false;
    }

    private function account(): array
    {
        $path = (string) Config::get('push.fcm.service_account', '');
        $json = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($json) || empty($json['private_key']) || empty($json['client_email'])) {
            throw new \RuntimeException('Compte de service Firebase absent (FCM_SERVICE_ACCOUNT).');
        }

        return $json;
    }

    /** Jeton OAuth2 (JWT RS256 signé avec la clé du compte de service), réutilisé 50 minutes. */
    private function accessToken(array $account): string
    {
        $cached = Cache::store()->get('push:fcm:token');
        if (is_string($cached)) {
            return $cached;
        }
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        openssl_sign($input, $sig, $account['private_key'], OPENSSL_ALGO_SHA256);
        $r = (new HttpClient(timeout: 10, retries: 1))->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . $b64($sig)]));
        $token = json_decode($r['body'], true)['access_token'] ?? throw new \RuntimeException('FCM : authentification refusée.');
        Cache::store()->set('push:fcm:token', $token, 3000);

        return $token;
    }
}
