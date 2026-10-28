<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Stockage clé/valeur à durée de vie limitée, utilisé pour :
 *  - les nonces anti-rejeu (add)
 *  - les compteurs de rate-limit (increment)
 *  - le cache des appareils (get/set)
 */
interface CacheStore
{
    /** Ajoute la clé seulement si elle n'existe pas (atomique). Retourne true si ajoutée. */
    public function add(string $key, int $ttl): bool;

    /** Incrémente un compteur (créé à 1 avec le TTL donné s'il n'existe pas). Retourne la nouvelle valeur. */
    public function increment(string $key, int $ttl): int;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    public function delete(string $key): void;
}
