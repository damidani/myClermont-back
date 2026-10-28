<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Codes à 6 chiffres (RFC 6238, compatible Google Authenticator, 1Password, Authy...).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(32)) as $byte) { // 32 × 5 bits = 160 bits
            $secret .= self::ALPHABET[ord($byte) & 31];
        }

        return $secret;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xF;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code (tolérance ±30 s). Retourne le pas de temps utilisé, à mémoriser pour refuser
     * qu'un même code serve deux fois ; null si invalide.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', (string) $code)) {
            return null;
        }
        $step = intdiv($now ?? time(), 30);
        foreach ([0, -1, 1] as $drift) {
            if (($lastStep === null || $step + $drift > $lastStep) && hash_equals(self::code($secret, $step + $drift), $code)) {
                return $step + $drift;
            }
        }

        return null;
    }

    /** Lien à saisir (ou scanner) dans l'application d'authentification. */
    public static function uri(string $secret, string $email): string
    {
        return 'otpauth://totp/' . rawurlencode('myClermont admin:' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode('myClermont admin');
    }

    private static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Secret base32 invalide.');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
