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
            // Mot entier, accords compris (« connard », « connards », « connasses ») ; « encul* » = toute la famille de mots
            $prefix = str_ends_with($word, '*');
            $w = preg_quote(self::normalize(rtrim($word, '*')), '/');
            if (preg_match('/(?<![a-z0-9])' . $w . ($prefix ? '[a-z]*' : '(?:e?s|x)?') . '(?![a-z0-9])/u', $normalized)) {
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

    /** Lien, nom de domaine ou raccourcisseur, même sans « https:// » (« bit.ly/xyz », « monsite.com »). */
    public static function hasLink(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return preg_match('#(?:https?://|www\.)\S+|(?<![\w@.-])[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|fr|net|org|io|co|me|ly|gl|gd|be|eu|info|biz|app|xyz|site|online|shop|store|link|club|top|ru|cn|tk|ml|ga|cf|gq|to|cc|tv|us|uk|de|ch|ca|es|it|pt|nl)(?:/\S*)?(?![\w.-])#iu', $text, $m) === 1
            && !preg_match('/^\d+([.,]\d+)*$/', $m[0]);
    }

    /**
     * Minuscules, sans accents, chiffres et symboles lus comme des lettres (« c0nn@rd »), lettres répétées réduites
     * (« connnnard » → « conard »), séparateurs entre lettres retirés (« c.o.n.n.a.r.d »).
     */
    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '@' => 'a', '$' => 's', '€' => 'e']);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $ascii = (string) preg_replace_callback('/\b(?:[a-z][.\-_*]){2,}[a-z]\b/', static fn ($m) => preg_replace('/[.\-_*]/', '', $m[0]), $ascii);
        $ascii = (string) preg_replace('/([a-z])\1+/', '$1', $ascii);

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
