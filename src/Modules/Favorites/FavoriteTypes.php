<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

/**
 * Registre des types de favoris disponibles (rempli par les modules au démarrage).
 */
final class FavoriteTypes
{
    /** @var array<string, FavoriteProvider> */
    private static array $providers = [];

    public static function register(string $type, FavoriteProvider $provider): void
    {
        if (!preg_match('/^[a-z_]{2,30}$/', $type)) {
            throw new \LogicException("Type de favori invalide : {$type}");
        }
        self::$providers[$type] = $provider;
    }

    public static function get(string $type): ?FavoriteProvider
    {
        return self::$providers[$type] ?? null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::$providers);
    }

    public static function reset(): void
    {
        self::$providers = [];
    }
}
