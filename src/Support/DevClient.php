<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Security\Crypto;
use App\Security\Signature;

/**
 * Client de développement : appelle l'API comme l'app (appareil enregistré avec la clé application,
 * requêtes signées). Utilisé par `api:call` et `schema:check`. État dans storage/dev-device.json.
 */
final class DevClient
{
    private array $state;

    public function __construct(private readonly string $base, private readonly string $prefix = '/api/v1')
    {
        $file = dirname(__DIR__, 2) . '/storage/dev-device.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($state) || ($state['base'] ?? null) !== $base) {
            $keys = (array) Config::get('security.app_keys');
            $keyId = (string) array_key_first($keys);
            if ($keyId === '') {
                throw new \RuntimeException('Aucune clé application (APP_SIGNING_KEYS).');
            }
            $uid = Crypto::uuid4();
            $reg = (string) json_encode(['platform' => $keys[$keyId]['platform'], 'model' => 'console', 'app_version' => 'dev']);
            [$code, $res] = $this->send('POST', $this->prefix . '/devices/register', $reg, $keys[$keyId]['secret'], $uid, ["X-Key-Id: {$keyId}"]);
            $d = json_decode($res, true);
            if ($code >= 300 || empty($d['deviceSecret'])) {
                throw new \RuntimeException("Enregistrement de l'appareil refusé ({$code}) : {$res}");
            }
            $state = ['base' => $base, 'uid' => $uid, 'token' => $d['deviceToken'], 'secret' => $d['deviceSecret']];
            file_put_contents($file, json_encode($state));
            chmod($file, 0600);
        }
        $this->state = $state;
    }

    public function deviceId(): string
    {
        return $this->state['uid'];
    }

    /** @return array{0: int, 1: string} code HTTP, corps brut */
    public function call(string $method, string $path, string $body = '', ?string $token = null): array
    {
        $headers = ["X-Device-Token: {$this->state['token']}"];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $this->send(strtoupper($method), $path, $body, $this->state['secret'], $this->state['uid'], $headers);
    }

    private function send(string $method, string $path, string $body, string $secret, string $uid, array $extra): array
    {
        [$p, $query] = array_pad(explode('?', $path, 2), 2, '');
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = Signature::sign($secret, Signature::stringToSign($method, $p, $query, $ts, $nonce, $uid, $body));
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POSTFIELDS     => $body === '' ? null : $body,
            CURLOPT_HTTPHEADER     => [...$extra, 'Content-Type: application/json', 'X-Platform: ios', 'X-App-Version: 0.9.1 (17)',
                                       "X-Device-Id: {$uid}", "X-Timestamp: {$ts}", "X-Nonce: {$nonce}", "X-Signature: {$sig}"],
        ]);
        $res = (string) curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $res];
    }
}
