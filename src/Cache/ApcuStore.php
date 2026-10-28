<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Mémoire partagée du serveur (APCu) : ~1 µs par opération, aucun aller-retour réseau.
 * Limite : la mémoire n'est pas partagée entre plusieurs serveurs. Pour un cluster,
 * implémenter un RedisStore avec la même interface.
 */
final class ApcuStore implements CacheStore
{
    private const PREFIX = 'mc:';

    public function add(string $key, int $ttl): bool
    {
        return apcu_add(self::PREFIX . $key, 1, $ttl);
    }

    public function increment(string $key, int $ttl): int
    {
        $key = self::PREFIX . $key;
        apcu_add($key, 0, $ttl); // n'a d'effet que si la clé n'existe pas : le TTL n'est pas prolongé
        $value = apcu_inc($key);

        return $value === false ? 1 : $value;
    }

    public function get(string $key): mixed
    {
        $value = apcu_fetch(self::PREFIX . $key, $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        apcu_store(self::PREFIX . $key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        apcu_delete(self::PREFIX . $key);
    }
}
