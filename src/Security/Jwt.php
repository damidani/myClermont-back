<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Exceptions\HttpException;

/**
 * JWT HS256 minimal pour les jetons d'accès utilisateur.
 * L'algorithme est figé côté serveur : l'en-tête "alg" envoyé n'est jamais utilisé
 * (protection contre les attaques "alg: none" / confusion d'algorithme).
 */
final class Jwt
{
    public static function encode(array $claims): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, self::secret(), true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /** @return array<string, mixed> claims validés */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw HttpException::unauthorized('invalid_token', 'Jeton d\'accès invalide.');
        }
        [$header, $payload, $signature] = $parts;

        $expected = self::b64(hash_hmac('sha256', $header . '.' . $payload, self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            throw HttpException::unauthorized('invalid_token', 'Jeton d\'accès invalide.');
        }

        $claims = json_decode(self::unb64($payload), true);
        if (!is_array($claims)) {
            throw HttpException::unauthorized('invalid_token', 'Jeton d\'accès invalide.');
        }
        if (($claims['exp'] ?? 0) < time()) {
            throw HttpException::unauthorized('token_expired', 'Jeton d\'accès expiré.');
        }
        if (($claims['iss'] ?? null) !== Config::get('security.jwt.issuer')) {
            throw HttpException::unauthorized('invalid_token', 'Jeton d\'accès invalide.');
        }

        return $claims;
    }

    private static function secret(): string
    {
        $secret = (string) Config::get('security.jwt.secret');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET absent ou trop court (lancer : php bin/console key:generate).');
        }

        return $secret;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
