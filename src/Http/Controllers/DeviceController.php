<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Repositories\DeviceRepository;
use App\Repositories\RefreshTokenRepository;
use App\Security\Crypto;
use App\Support\Validator;

final class DeviceController
{
    private DeviceRepository $devices;

    public function __construct()
    {
        $this->devices = new DeviceRepository();
    }

    /**
     * POST /v1/devices/register  (signé avec la clé application)
     *
     * Retourne le device_token et le device_secret propres à l'appareil.
     * Le secret n'est transmis QU'UNE SEULE FOIS : l'app doit le stocker dans
     * le Keychain (iOS) / EncryptedSharedPreferences + Keystore (Android).
     */
    public function register(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'platform'    => 'required|in:ios,android',
            'model'       => 'nullable|string|max:100',
            'os_version'  => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:30',
            'push_token'  => 'nullable|string|max:255',
        ]);

        if ($data['platform'] !== $request->attributes['app_platform']) {
            throw HttpException::forbidden('platform_mismatch', 'La plateforme ne correspond pas à la clé application.');
        }

        $uid = $request->attributes['device_uid'];
        $existing = $this->devices->findByUid($uid, true);
        if ($existing !== null && $existing['status'] !== 'active') {
            throw HttpException::forbidden('device_blocked', 'Cet appareil a été bloqué.');
        }

        $token = Crypto::randomToken(32);
        $secret = Crypto::randomToken(32);

        $device = $this->devices->register($uid, $data['platform'], $data, Crypto::hashToken($token), Crypto::encrypt($secret));

        if ($existing !== null) {
            // Nouveaux identifiants => les sessions ouvertes avec les anciens sont fermées
            (new RefreshTokenRepository())->revokeAllForDevice((int) $device['id']);
            Logger::security('Appareil réenregistré, identifiants renouvelés', ['device_id' => $uid]);
        }

        return Response::success([
            'device'           => DeviceRepository::present($device),
            'device_token'     => $token,
            'device_secret'    => $secret,
            'server_time'      => time(),
            'signature_window' => (int) Config::get('security.signature_window', 300),
        ], $existing === null ? 201 : 200);
    }

    /** GET /v1/devices/me */
    public function show(Request $request): Response
    {
        return Response::success(DeviceRepository::present($request->attributes['device']));
    }

    /** PATCH /v1/devices/me — mise à jour du push token, de la version de l'app... */
    public function update(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'model'       => 'nullable|string|max:100',
            'os_version'  => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:30',
            'push_token'  => 'nullable|string|max:255',
        ]);

        $device = $this->devices->update($request->attributes['device'], $data);

        return Response::success(DeviceRepository::present($device));
    }
}
