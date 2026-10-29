<?php

declare(strict_types=1);

namespace App\Modules\Moderation;

use App\Core\Config;

/**
 * Filtre automatique à la publication (spec v1 › Contenu publié par les utilisateurs) :
 * liste de mots (insultes, haine, menaces) et liens suspects (raccourcisseurs, adresses IP, trop de liens).
 */
final class ContentFilter
{
    /** @return string|null motif du blocage, ou null si le texte peut être publié */
    public static function check(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $normalized = ' ' . self::normalize($text) . ' ';
        foreach ((array) Config::get('moderation.blocked_words', []) as $word) {
            // Mot entier, accords compris (« connard », « connards », « connasses »)
            if (preg_match('/(?<![a-z0-9])' . preg_quote(self::normalize($word), '/') . '(?:e?s|x)?(?![a-z0-9])/u', $normalized)) {
                return 'words';
            }
        }

        // Raccourcisseurs reconnus même sans « https:// » (« bit.ly/xyz »)
        foreach ((array) Config::get('moderation.suspicious_domains', []) as $domain) {
            if (preg_match('#(?<![a-z0-9.-])' . preg_quote($domain, '#') . '/#i', $text)) {
                return 'links';
            }
        }

        preg_match_all('#\b(?:https?://|www\.)([^\s/?\#]+)#iu', $text, $m);
        if (count($m[1]) > (int) Config::get('moderation.max_links', 2)) {
            return 'links';
        }
        foreach ($m[1] as $host) {
            $host = strtolower(preg_replace('/^www\./', '', $host));
            if (self::endsWithAny($host, (array) Config::get('moderation.allowed_domains', []))) {
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP) || str_contains($host, 'xn--') || self::endsWithAny($host, (array) Config::get('moderation.suspicious_domains', []))) {
                return 'links';
            }
        }

        return null;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '@' => 'a', '$' => 's']);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;

        return (string) preg_replace('/\s+/', ' ', $ascii);
    }

    private static function endsWithAny(string $host, array $domains): bool
    {
        foreach ($domains as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }

        return false;
    }
}
