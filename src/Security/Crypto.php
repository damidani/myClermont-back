<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

/**
 * Chiffrement symétrique authentifié (libsodium XSalsa20-Poly1305) avec APP_KEY, résultat en base64.
 * Utilisé pour stocker les secrets appareils en base : une fuite de la base seule
 * ne permet pas de forger des signatures.
 */
final class Crypto
{
    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): string
    {
        $payload = (string) base64_decode($encoded, true);
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Déchiffrement impossible (APP_KEY modifiée ou donnée corrompue).');
        }

        return $plain;
    }

    /** Jeton aléatoire encodé base64url (sans padding). */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Empreinte SHA-256 (hex) pour stocker un jeton sans le conserver en clair. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function key(): string
    {
        static $key = null;
        if ($key === null) {
            $key = base64_decode((string) Config::get('app.key'), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('APP_KEY absente ou invalide (lancer : php bin/console key:generate).');
            }
        }

        return $key;
    }
}
