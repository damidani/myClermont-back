<?php

declare(strict_types=1);

namespace App\Content\Import;

final class ImportTargets
{
    /** @var array<string, ImportTarget> */
    private static array $targets = [];

    public static function register(string $name, ImportTarget $target): void
    {
        self::$targets[$name] = $target;
    }

    public static function get(string $name): ?ImportTarget
    {
        return self::$targets[$name] ?? null;
    }

    public static function all(): array
    {
        return array_keys(self::$targets);
    }
}
