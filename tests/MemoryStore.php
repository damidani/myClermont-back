<?php

declare(strict_types=1);

namespace Tests;

use App\Cache\CacheStore;

/** Store en mémoire du processus, pour les tests uniquement. */
final class MemoryStore implements CacheStore
{
    /** @var array<string, array{0: mixed, 1: int}> */
    private array $data = [];

    public function add(string $key, int $ttl): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        $this->set($key, 1, $ttl);
        return true;
    }

    public function increment(string $key, int $ttl): int
    {
        $value = ((int) $this->get($key)) + 1;
        $expires = $this->data[$key][1] ?? time() + $ttl;
        $this->data[$key] = [$value, $expires];
        return $value;
    }

    public function get(string $key): mixed
    {
        [$value, $expires] = $this->data[$key] ?? [null, 0];
        return $expires >= time() ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->data[$key] = [$value, time() + $ttl];
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}
