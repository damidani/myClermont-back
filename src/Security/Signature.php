<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Signature HMAC-SHA256 des requêtes.
 *
 * Chaîne signée (lignes séparées par "\n", sans "\n" final) :
 *
 *   METHOD                 GET, POST... en majuscules
 *   PATH                   /v1/users/me (tel qu'envoyé, sans query string)
 *   CANONICAL_QUERY        paramètres triés, encodés RFC 3986 (vide si aucun)
 *   TIMESTAMP              X-Timestamp (secondes Unix)
 *   NONCE                  X-Nonce
 *   DEVICE_ID              X-Device-Id
 *   BODY_SHA256            SHA-256 hexadécimal du corps brut (SHA-256 de "" si vide)
 *
 * X-Signature = hex(HMAC_SHA256(clé, chaîne))
 *
 * Voir docs/SECURITE.md pour le protocole complet et docs/INTEGRATION_MOBILE.md
 * pour les implémentations Swift et Kotlin.
 */
final class Signature
{
    public static function canonicalQuery(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $queryString) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurlencode(urldecode($key)), rawurlencode(urldecode($value))];
        }

        // strcmp = ordre octet par octet (comme Swift/Kotlin) ; "<=>" comparerait "10" et "9" numériquement
        usort($pairs, static fn (array $a, array $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(static fn (array $p) => $p[0] . '=' . $p[1], $pairs));
    }

    public static function stringToSign(
        string $method,
        string $path,
        string $queryString,
        string $timestamp,
        string $nonce,
        string $deviceId,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            self::canonicalQuery($queryString),
            $timestamp,
            $nonce,
            $deviceId,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $key, string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $key);
    }

    /** Comparaison en temps constant (pas de fuite par mesure du temps de réponse). */
    public static function verify(string $key, string $stringToSign, string $signature): bool
    {
        return hash_equals(self::sign($key, $stringToSign), strtolower($signature));
    }
}
