<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\NonceGuard;
use App\Security\Signature;

/**
 * Vérifie les requêtes signées avec une CLÉ APPLICATION (embarquée dans l'app iOS/Android).
 * Utilisé uniquement pour l'enregistrement d'un appareil (POST /v1/devices/register),
 * c'est-à-dire avant que l'appareil possède son propre secret.
 *
 * En-têtes requis : X-Key-Id, X-Device-Id, X-Timestamp, X-Nonce, X-Signature
 */
final class AppSignatureMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $keyId = (string) $request->header('x-key-id', '');
        $deviceId = (string) $request->header('x-device-id', '');
        $timestamp = $request->header('x-timestamp');
        $nonce = $request->header('x-nonce');
        $signature = (string) $request->header('x-signature', '');

        $key = Config::get('security.app_keys.' . $keyId);
        if ($keyId === '' || !is_array($key)) {
            Logger::security('Clé application inconnue', ['key_id' => $keyId]);
            throw HttpException::unauthorized('invalid_app_key', 'Clé application inconnue ou révoquée.');
        }
        if (!DeviceSignatureMiddleware::isUuid($deviceId)) {
            throw HttpException::unauthorized('invalid_device_id', 'En-tête X-Device-Id manquant ou invalide.');
        }
        NonceGuard::assertFreshTimestamp($timestamp);
        NonceGuard::assertValidNonce($nonce);

        $toSign = Signature::stringToSign(
            $request->method, $request->path, $request->queryString,
            $timestamp, $nonce, $deviceId, $request->body,
        );
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature) || !Signature::verify($key['secret'], $toSign, $signature)) {
            Logger::security('Signature application invalide', ['key_id' => $keyId, 'device_id' => $deviceId]);
            throw HttpException::unauthorized('invalid_signature', 'Signature invalide.');
        }

        NonceGuard::consume('app:' . $keyId, $nonce);

        $request->attributes['app_key_id'] = $keyId;
        $request->attributes['app_platform'] = $key['platform'];
        $request->attributes['device_uid'] = strtolower($deviceId);

        return $next($request);
    }
}
