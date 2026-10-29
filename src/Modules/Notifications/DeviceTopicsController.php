<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\DeviceRepository;
use App\Support\Validator;

/**
 * Appareils et thèmes de notifications (spec v1). L'appareil est déjà identifié par la signature des requêtes :
 * POST /devices enregistre son jeton push et ses thèmes ; avec un jeton d'accès, il est rattaché au compte.
 *
 *   POST   /devices          { platform, pushToken, appVersion, locale, topics[] }  → { deviceId }
 *   PATCH  /devices/{id}     { pushToken?, topics? }                               → Device
 *   DELETE /devices/{id}     → 204 (plus aucune notification sur cet appareil)
 */
final class DeviceTopicsController
{
    public function store(Request $request): Response
    {
        $data = $this->validate($request->json(), true);
        $device = $request->attributes['device'];
        if ($data['platform'] !== $device['platform']) {
            throw new ValidationException(['platform' => 'La plateforme ne correspond pas à cet appareil.']);
        }
        $this->save($request, $device, $data);

        return Response::success(['device_id' => $device['uid']], 200);
    }

    public function update(Request $request): Response
    {
        $device = $this->own($request);
        $data = $this->validate($request->json(), false);
        $this->save($request, $device, $data);
        $row = Database::fetch('SELECT uid, platform, push_token, topics, app_version, locale FROM devices WHERE id = :id', ['id' => $device['id']]);

        return Response::success([
            'id' => $row['uid'], 'platform' => $row['platform'], 'push_enabled' => $row['push_token'] !== null,
            'topics' => $row['topics'] === null ? null : explode(',', $row['topics']), 'app_version' => $row['app_version'], 'locale' => $row['locale'],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $device = $this->own($request);
        Database::execute('UPDATE devices SET push_token = NULL, topics = NULL, updated_at = :d WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $device['id']]);
        (new DeviceRepository())->forget($device['uid']);

        return Response::noContent();
    }

    /** Live Activity d'événement (lot 3, facultatif) : jeton ActivityKit gardé pour « ça commence dans 30 min ». */
    public function liveActivity(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'push_token' => 'required|string|max:255', 'kind' => 'required|in:event', 'event_id' => 'required|string|max:120',
        ]);
        $event = Database::fetch("SELECT id FROM events WHERE slug = :s AND status = 'published'", ['s' => $data['event_id']])
            ?? throw new HttpException(404, 'event_not_found', 'Événement introuvable.');
        $id = bin2hex(random_bytes(8));
        \App\Cache\Cache::store()->set('live-activity:' . $id, ['device_id' => $request->attributes['device']['id'], 'event_id' => (int) $event['id'], 'token' => $data['push_token']], 86400 * 2);

        return Response::success(['id' => $id], 201);
    }

    private function validate(array $json, bool $create): array
    {
        $data = Validator::validate($json, [
            'platform'    => ($create ? 'required|' : '') . 'in:ios,android',
            'push_token'  => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:30',
            'locale'      => 'nullable|string|max:10',
            'topics'      => 'nullable|array|max:20',
        ]);
        if (isset($data['topics'])) {
            $unknown = array_diff($data['topics'], (array) Config::get('push.topics'));
            if ($unknown !== []) {
                throw new ValidationException(['topics' => 'Thème inconnu : ' . implode(', ', $unknown) . '.']);
            }
        }

        return $data;
    }

    private function save(Request $request, array $device, array $data): void
    {
        $fields = [];
        foreach (['push_token', 'app_version', 'locale'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[$f] = $data[$f];
            }
        }
        if (array_key_exists('topics', $data)) {
            $fields['topics'] = $data['topics'] === null ? null : implode(',', array_unique($data['topics']));
        }
        if (isset($request->attributes['user_id'])) {
            $fields['user_id'] = (int) $request->attributes['user_id'];
        }
        if ($fields === []) {
            return;
        }
        if (isset($fields['push_token'])) {
            // Un jeton push n'appartient qu'à un appareil (réinstallation de l'app)
            Database::execute('UPDATE devices SET push_token = NULL WHERE push_token = :t AND id <> :id', ['t' => $fields['push_token'], 'id' => $device['id']]);
        }
        $sets = implode(', ', array_map(static fn ($f) => "{$f} = :{$f}", array_keys($fields)));
        Database::execute("UPDATE devices SET {$sets}, updated_at = :now WHERE id = :id", $fields + ['now' => gmdate('Y-m-d H:i:s'), 'id' => $device['id']]);
        (new DeviceRepository())->forget($device['uid']);
    }

    private function own(Request $request): array
    {
        $device = $request->attributes['device'];
        if (strtolower((string) $request->param('id')) !== $device['uid']) {
            throw HttpException::forbidden('forbidden', 'Tu ne peux modifier que cet appareil.');
        }

        return $device;
    }
}
