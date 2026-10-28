<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Données collectées pendant la requête et ajoutées automatiquement à "meta" de la réponse.
 * Exemple : les points / badges gagnés, pour que l'app affiche une animation
 * quelle que soit la route qui les a déclenchés.
 */
final class Context
{
    private static array $meta = [];

    public static function pushMeta(string $key, mixed $value): void
    {
        self::$meta[$key][] = $value;
    }

    public static function meta(): array
    {
        return self::$meta;
    }

    public static function reset(): void
    {
        self::$meta = [];
    }
}
