<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lecteur minimaliste de fichier .env (aucune dépendance).
 * En production, `php bin/console config:cache` évite complètement sa lecture.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && ($end = strpos($value, $quote, 1)) !== false) {
                $value = substr($value, 1, $end - 1);
            } elseif (str_starts_with($value, '#')) {
                $value = ''; // « CLE=   # commentaire » : valeur vide
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash)); // commentaire en fin de ligne
            }

            self::$vars[trim($key)] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Les vraies variables d'environnement (Docker, FPM env[...]) priment sur le fichier .env
        $value = getenv($key);
        if ($value === false) {
            $value = self::$vars[$key] ?? false;
        }
        if ($value === false || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }
}
