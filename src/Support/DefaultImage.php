<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Visuel par défaut d'une fiche sans photo (brief iOS §2.8 : URL HTTPS absolue, JPEG ≤ 1 600 px, jamais d'image tierce).
 * Fichiers servis par Nginx depuis public/static/defaults/ (dégradés de la palette de l'app).
 */
final class DefaultImage
{
    public static function url(string $name): string
    {
        $file = preg_replace('/[^a-z0-9-]/', '', $name);
        if (!is_file(dirname(__DIR__, 2) . "/public/static/defaults/{$file}.jpg")) {
            $file = 'housing';
        }

        return rtrim((string) Config::get('app.url'), '/') . "/static/defaults/{$file}.jpg";
    }

    /** Garde les URL HTTPS absolues (pas de picsum.photos) ; à défaut, le visuel par défaut. */
    public static function urls(array $urls, string $fallback): array
    {
        $ok = array_values(array_filter($urls, static fn ($u) => is_string($u) && str_starts_with($u, 'https://') && !str_contains($u, 'picsum.photos')));

        return $ok !== [] ? $ok : [self::url($fallback)];
    }

    /** Clé de visuel d'une catégorie (« Bien-être » → « bien-etre ») */
    public static function slug(string $label): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($label)) ?: '';

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }
}
