<?php

declare(strict_types=1);

/*
 * Helpers des tests HTTP : un appareil injecté dans le cache mémoire et des requêtes signées.
 */

use App\Cache\Cache;
use App\Core\App;
use App\Core\Request;
use App\Security\Crypto;
use App\Security\Signature;

const DEVICE_UID = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
const DEVICE_TOKEN = 'device-token-123';
const DEVICE_SECRET = 'device-secret-456';

function seed_device(string $status = 'active'): void
{
    Cache::store()->set('device:' . DEVICE_UID, [
        'id' => 1, 'uid' => DEVICE_UID, 'platform' => 'ios', 'status' => $status,
        'token_hash' => Crypto::hashToken(DEVICE_TOKEN), 'secret_encrypted' => Crypto::encrypt(DEVICE_SECRET),
        'user_id' => null, 'model' => 'iPhone15,2', 'os_version' => '18.0', 'app_version' => '1.0.0',
        'push_token' => null, 'last_seen_at' => null, 'created_at' => '2026-01-01 00:00:00',
    ], 60);
    Cache::store()->set('device-touch:' . DEVICE_UID, 1, 60); // évite l'UPDATE last_seen_at
}

function signed_request(string $method, string $path, string $body = '', array $override = []): Request
{
    $ts = (string) ($override['timestamp'] ?? time());
    $nonce = $override['nonce'] ?? bin2hex(random_bytes(16));
    $sig = Signature::sign($override['secret'] ?? DEVICE_SECRET, Signature::stringToSign($method, $path, '', $ts, $nonce, DEVICE_UID, $body));

    $headers = [
        'x-device-id' => DEVICE_UID, 'x-device-token' => $override['token'] ?? DEVICE_TOKEN,
        'x-timestamp' => $ts, 'x-nonce' => $nonce, 'x-signature' => $sig,
    ] + ($override['headers'] ?? []);

    return new Request($method, $path, '', $headers, $override['sent_body'] ?? $body, '127.0.0.1');
}

function app(): App
{
    return App::boot(dirname(__DIR__));
}


/** Requête signée avec une query string et des en-têtes supplémentaires. */
function signed_query(string $method, string $path, string $query, array $headers = []): Request
{
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = Signature::sign(DEVICE_SECRET, Signature::stringToSign($method, $path, $query, $ts, $nonce, DEVICE_UID, ''));

    return new Request($method, $path, $query, [
        'x-device-id' => DEVICE_UID, 'x-device-token' => DEVICE_TOKEN, 'x-timestamp' => $ts, 'x-nonce' => $nonce, 'x-signature' => $sig,
    ] + $headers, '', '127.0.0.1');
}
