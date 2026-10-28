<?php

declare(strict_types=1);

namespace App\Content\Import;

/** Nettoyage des textes reçus des sources (HTML, encodages, montants en français). */
final class Text
{
    /** HTML → texte brut lisible (paragraphes et listes conservés en retours à la ligne). */
    public static function fromHtml(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $text = preg_replace(['#<\s*br\s*/?>#i', '#</\s*(p|div|h[1-6]|tr)\s*>#i', '#<\s*li[^>]*>#i'], ["\n", "\n\n", "\n• "], $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["/[ \t\u{00A0}]+/u", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text);

        return trim((string) $text) ?: null;
    }

    /** Résumé de carte : première(s) phrase(s), coupé proprement. */
    public static function summary(?string $text, int $max = 300): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $flat = trim((string) preg_replace('/\s+/u', ' ', str_replace('•', '', $text)));
        if (mb_strlen($flat) <= $max) {
            return $flat;
        }
        $cut = mb_substr($flat, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space ?: $max - 1), " ,;:.-") . '…';
    }

    /** Texte en UTF-8 quel que soit l'encodage du fichier (les exports d'agences sont souvent en ISO-8859-1 / Windows-1252). */
    public static function utf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /** « 1 250,50 » ou « 1250.5 » → 125050 centimes. */
    public static function cents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) round($value * 100) : null;
        }
        $s = preg_replace('/[^\d,.]/', '', (string) $value);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {  // 1.250,50
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s) || (float) $s <= 0) {
            return null;
        }

        return (int) round((float) $s * 100);
    }

    /**
     * Libellé de salaire France Travail / annonce → [min, max, période] en centimes.
     * « Mensuel de 2000.00 Euros à 2500.00 Euros sur 12 mois », « Annuel de 35000 Euros », « Horaire de 11.88 Euros ».
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    public static function salary(?string $label): array
    {
        if ($label === null || !preg_match_all('/(\d[\d\s\x{202F}\x{00A0}]*(?:[.,]\d+)?)\s*(?:k\s*)?(?:€|euros?)/iu', $label, $m)) {
            return [null, null, null];
        }
        $amounts = array_map(static function (string $a) use ($label): ?int {
            $c = self::cents(preg_replace('/[\s\x{202F}\x{00A0}]/u', '', $a));
            return $c !== null && preg_match('/\d\s*k\s*€/iu', $label) && $c < 100000 ? $c * 1000 : $c;
        }, $m[1]);
        $amounts = array_values(array_filter($amounts));
        if ($amounts === []) {
            return [null, null, null];
        }
        $l = mb_strtolower($label);
        $period = match (true) {
            str_contains($l, 'horaire') || str_contains($l, '/h') || str_contains($l, 'heure') => 'hour',
            str_contains($l, 'mensuel') || str_contains($l, 'mois') => 'month',
            str_contains($l, 'annuel') || str_contains($l, ' an') || str_contains($l, 'k€') => 'year',
            default => $amounts[0] < 1000_00 ? 'hour' : ($amounts[0] < 10000_00 ? 'month' : 'year'),
        };

        return [min($amounts), count($amounts) > 1 ? max($amounts) : null, $period];
    }
}
