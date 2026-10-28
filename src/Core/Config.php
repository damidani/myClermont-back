<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Accès à la configuration via notation pointée : Config::get('security.jwt.secret').
 * Si storage/cache/config.php existe (généré par `bin/console config:cache`),
 * il est chargé directement : un seul fichier, mis en cache par OPcache.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function load(string $root): void
    {
        $cache = $root . '/storage/cache/config.php';
        if (is_file($cache)) {
            self::$items = require $cache;
            return;
        }

        Env::load($root . '/.env');
        self::$items = [];
        foreach (glob($root . '/config/*.php') ?: [] as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
