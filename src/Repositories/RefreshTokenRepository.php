<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RefreshTokenRepository
{
    public function create(int $userId, int $deviceId, string $tokenHash, int $expiresAt): int
    {
        return Database::insert(
            'INSERT INTO refresh_tokens (user_id, device_id, token_hash, expires_at, created_at)
             VALUES (:user, :device, :hash, :expires, :created)',
            [
                'user' => $userId, 'device' => $deviceId, 'hash' => $tokenHash,
                'expires' => gmdate('Y-m-d H:i:s', $expiresAt), 'created' => gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    public function findByHash(string $tokenHash): ?array
    {
        return Database::fetch(
            'SELECT id, user_id, device_id, expires_at, revoked_at FROM refresh_tokens WHERE token_hash = :h',
            ['h' => $tokenHash],
        );
    }

    /** Révocation atomique : retourne false si le jeton était déjà révoqué (utilisation concurrente). */
    public function revoke(int $id, ?int $replacedBy = null): bool
    {
        return Database::execute(
            'UPDATE refresh_tokens SET revoked_at = :now, replaced_by = :rep WHERE id = :id AND revoked_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'rep' => $replacedBy, 'id' => $id],
        ) > 0;
    }

    public function revokeForDevice(int $userId, int $deviceId): int
    {
        return Database::execute(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :u AND device_id = :d AND revoked_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'u' => $userId, 'd' => $deviceId],
        );
    }

    public function revokeAllForDevice(int $deviceId): int
    {
        return Database::execute(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE device_id = :d AND revoked_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'd' => $deviceId],
        );
    }

    public function revokeAllForUser(int $userId): int
    {
        return Database::execute(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :u AND revoked_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'u' => $userId],
        );
    }

    public static function prune(): int
    {
        // Conserve 7 jours d'historique des jetons révoqués/expirés (détection de réutilisation)
        return Database::execute(
            'DELETE FROM refresh_tokens WHERE expires_at < :limit',
            ['limit' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)],
        );
    }
}
