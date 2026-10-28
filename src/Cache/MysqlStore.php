<?php

declare(strict_types=1);

namespace App\Cache;

use App\Core\Database;

/**
 * Repli MySQL quand APCu n'est pas disponible (ou pour partager l'état entre plusieurs serveurs).
 * Chaque opération est une seule requête atomique. Les lignes expirées sont purgées par
 * `php bin/console cache:prune` (cron).
 */
final class MysqlStore implements CacheStore
{
    public function add(string $key, int $ttl): bool
    {
        $now = time();
        // 1 = insérée, 2 = ligne expirée remplacée, 0 = déjà présente et valide
        $affected = Database::execute(
            'INSERT INTO cache_entries (k, value, counter, expires_at) VALUES (:k, NULL, 0, :exp)
             ON DUPLICATE KEY UPDATE value = IF(expires_at < :now1, NULL, value),
                                     expires_at = IF(expires_at < :now2, :exp2, expires_at)',
            ['k' => $key, 'exp' => $now + $ttl, 'now1' => $now, 'now2' => $now, 'exp2' => $now + $ttl],
        );

        return $affected > 0;
    }

    public function increment(string $key, int $ttl): int
    {
        $now = time();
        // LAST_INSERT_ID(expr) permet de récupérer la nouvelle valeur sans second SELECT
        $affected = Database::execute(
            'INSERT INTO cache_entries (k, value, counter, expires_at) VALUES (:k, NULL, 1, :exp)
             ON DUPLICATE KEY UPDATE counter = LAST_INSERT_ID(IF(expires_at < :now1, 1, counter + 1)),
                                     expires_at = IF(expires_at < :now2, :exp2, expires_at)',
            ['k' => $key, 'exp' => $now + $ttl, 'now1' => $now, 'now2' => $now, 'exp2' => $now + $ttl],
        );

        return $affected === 1 ? 1 : (int) Database::connection()->lastInsertId();
    }

    public function get(string $key): mixed
    {
        $row = Database::fetch(
            'SELECT value FROM cache_entries WHERE k = :k AND expires_at >= :now',
            ['k' => $key, 'now' => time()],
        );

        return $row === null || $row['value'] === null ? null : unserialize($row['value'], ['allowed_classes' => false]);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        Database::execute(
            'INSERT INTO cache_entries (k, value, counter, expires_at) VALUES (:k, :v, 0, :exp)
             ON DUPLICATE KEY UPDATE value = :v2, expires_at = :exp2',
            ['k' => $key, 'v' => serialize($value), 'exp' => time() + $ttl, 'v2' => serialize($value), 'exp2' => time() + $ttl],
        );
    }

    public function delete(string $key): void
    {
        Database::execute('DELETE FROM cache_entries WHERE k = :k', ['k' => $key]);
    }

    public static function prune(): int
    {
        return Database::execute('DELETE FROM cache_entries WHERE expires_at < :now', ['now' => time()]);
    }
}
