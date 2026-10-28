<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Repositories\DeviceRepository;
use App\Security\Crypto;
use App\Security\NonceGuard;
use App\Security\Signature;

/**
 * Bouclier principal de l'API : toute requête doit être signée par un appareil enregistré.
 *
 * En-têtes requis : X-Device-Id, X-Device-Token, X-Timestamp, X-Nonce, X-Signature
 *
 * Ordre des contrôles (du moins coûteux au plus coûteux) :
 *   1. format des en-têtes             (aucune E/S)
 *   2. fenêtre de temps                (aucune E/S)
 *   3. appareil + jeton                (cache mémoire, sinon 1 SELECT)
 *   4. signature HMAC                  (aucune E/S)
 *   5. nonce anti-rejeu                (cache mémoire, sinon 1 INSERT)
 */
final class DeviceSignatureMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $deviceId = (string) $request->header('x-device-id', '');
        $token = (string) $request->header('x-device-token', '');
        $timestamp = $request->header('x-timestamp');
        $nonce = $request->header('x-nonce');
        $signature = (string) $request->header('x-signature', '');

        if (!self::isUuid($deviceId)) {
            throw HttpException::unauthorized('invalid_device_id', 'En-tête X-Device-Id manquant ou invalide.');
        }
        if ($token === '' || strlen($token) > 128) {
            throw HttpException::unauthorized('invalid_device_token', 'En-tête X-Device-Token manquant ou invalide.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            throw HttpException::unauthorized('invalid_signature', 'En-tête X-Signature manquant ou invalide.');
        }
        NonceGuard::assertFreshTimestamp($timestamp);
        NonceGuard::assertValidNonce($nonce);

        $devices = new DeviceRepository();
        $device = $devices->findByUid(strtolower($deviceId));

        // Même réponse que l'appareil soit inconnu ou le jeton faux : on ne révèle rien
        if ($device === null || !hash_equals($device['token_hash'], Crypto::hashToken($token))) {
            Logger::security('Appareil inconnu ou jeton invalide', ['device_id' => $deviceId]);
            throw HttpException::unauthorized('invalid_device', 'Appareil non reconnu. Veuillez réenregistrer l\'appareil.');
        }
        if ($device['status'] !== 'active') {
            throw HttpException::forbidden('device_blocked', 'Cet appareil a été bloqué.');
        }

        $toSign = Signature::stringToSign(
            $request->method, $request->path, $request->queryString,
            $timestamp, $nonce, $deviceId, $request->body,
        );
        if (!Signature::verify(Crypto::decrypt($device['secret_encrypted']), $toSign, $signature)) {
            Logger::security('Signature appareil invalide', ['device_id' => $deviceId]);
            throw HttpException::unauthorized('invalid_signature', 'Signature invalide.');
        }

        NonceGuard::consume('dev:' . strtolower($deviceId), $nonce);
        $devices->touch($device, $request->ip);

        $request->attributes['device'] = $device;

        return $next($request);
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
