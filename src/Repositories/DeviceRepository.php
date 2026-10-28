<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;

final class DeviceRepository
{
    private const COLUMNS = 'id, uid, platform, token_hash, secret_encrypted, status, user_id, model, os_version,
                             app_version, push_token, last_seen_at, created_at';

    /** Champs modifiables par l'appareil lui-même. */
    public const UPDATABLE = ['model', 'os_version', 'app_version', 'push_token'];

    /**
     * Appel le plus fréquent de l'API (à chaque requête signée) :
     * mis en cache mémoire quelques secondes quand APCu est disponible.
     */
    public function findByUid(string $uid, bool $fresh = false): ?array
    {
        $cacheKey = 'device:' . $uid;
        if (!$fresh && Cache::isInMemory() && is_array($cached = Cache::store()->get($cacheKey))) {
            return $cached;
        }

        $row = Database::fetch('SELECT ' . self::COLUMNS . ' FROM devices WHERE uid = :uid', ['uid' => $uid]);

        if ($row !== null && Cache::isInMemory()) {
            Cache::store()->set($cacheKey, $row, (int) Config::get('security.device_cache_ttl', 30));
        }

        return $row;
    }

    /**
     * Crée l'appareil ou renouvelle ses identifiants s'il existe déjà
     * (réinstallation de l'app). Le statut (bloqué/actif) n'est jamais modifié ici.
     */
    public function register(string $uid, string $platform, array $info, string $tokenHash, string $secretEncrypted): array
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO devices (uid, platform, token_hash, secret_encrypted, model, os_version, app_version, push_token, status, created_at, updated_at)
             VALUES (:uid, :platform, :token, :secret, :model, :os, :app, :push, \'active\', :created, :updated)
             ON DUPLICATE KEY UPDATE platform = :platform2, token_hash = :token2, secret_encrypted = :secret2,
                                     model = :model2, os_version = :os2, app_version = :app2, push_token = :push2, updated_at = :updated2',
            [
                'uid' => $uid, 'platform' => $platform, 'token' => $tokenHash, 'secret' => $secretEncrypted,
                'model' => $info['model'] ?? null, 'os' => $info['os_version'] ?? null,
                'app' => $info['app_version'] ?? null, 'push' => $info['push_token'] ?? null,
                'created' => $now, 'updated' => $now,
                'platform2' => $platform, 'token2' => $tokenHash, 'secret2' => $secretEncrypted,
                'model2' => $info['model'] ?? null, 'os2' => $info['os_version'] ?? null,
                'app2' => $info['app_version'] ?? null, 'push2' => $info['push_token'] ?? null, 'updated2' => $now,
            ],
        );
        $this->forget($uid);

        return $this->findByUid($uid, true) ?? throw new \RuntimeException('Enregistrement de l\'appareil échoué.');
    }

    /** @param array<string, mixed> $fields */
    public function update(array $device, array $fields): array
    {
        $fields = array_intersect_key($fields, array_flip(self::UPDATABLE));
        if ($fields === []) {
            return $device;
        }

        $sets = implode(', ', array_map(static fn (string $f) => "{$f} = :{$f}", array_keys($fields)));
        Database::execute(
            "UPDATE devices SET {$sets}, updated_at = :updated_at WHERE id = :id",
            $fields + ['updated_at' => gmdate('Y-m-d H:i:s'), 'id' => $device['id']],
        );
        $this->forget($device['uid']);

        return $this->findByUid($device['uid'], true) ?? $device;
    }

    public function attachUser(array $device, ?int $userId): void
    {
        Database::execute('UPDATE devices SET user_id = :user WHERE id = :id', ['user' => $userId, 'id' => $device['id']]);
        $this->forget($device['uid']);
    }

    /**
     * Met à jour last_seen_at au plus une fois toutes les N secondes par appareil,
     * pour ne pas transformer chaque lecture en écriture MySQL.
     */
    public function touch(array $device, string $ip): void
    {
        $interval = (int) Config::get('security.device_touch_interval', 300);

        if (Cache::isInMemory()) {
            if (!Cache::store()->add('device-touch:' . $device['uid'], $interval)) {
                return;
            }
        } elseif ($device['last_seen_at'] !== null && strtotime($device['last_seen_at'] . ' UTC') > time() - $interval) {
            return;
        }

        Database::execute(
            'UPDATE devices SET last_seen_at = :seen, last_ip = :ip WHERE id = :id',
            ['seen' => gmdate('Y-m-d H:i:s'), 'ip' => $ip, 'id' => $device['id']],
        );
    }

    public function setStatus(string $uid, string $status): bool
    {
        $changed = Database::execute('UPDATE devices SET status = :s WHERE uid = :uid', ['s' => $status, 'uid' => $uid]) > 0;
        $this->forget($uid);

        return $changed;
    }

    public function forget(string $uid): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('device:' . $uid);
        }
    }

    /** Représentation publique (jamais de jeton ni de secret). */
    public static function present(array $device): array
    {
        return [
            'id'           => $device['uid'],
            'platform'     => $device['platform'],
            'model'        => $device['model'],
            'os_version'   => $device['os_version'],
            'app_version'  => $device['app_version'],
            'push_enabled' => $device['push_token'] !== null,
            'status'       => $device['status'],
            'created_at'   => self::iso($device['created_at']),
        ];
    }

    private static function iso(?string $datetime): ?string
    {
        return $datetime === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($datetime . ' UTC'));
    }
}
