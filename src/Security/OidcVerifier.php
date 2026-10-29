<?php

declare(strict_types=1);

namespace App\Security;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Exceptions\HttpException;

/**
 * Vérifie un jeton d'identité OpenID Connect (Sign in with Apple, Google) :
 * signature RS256 avec les clés publiques du fournisseur (JWKS, en cache 24 h), émetteur, audience, expiration.
 * Aucune bibliothèque : la clé JWK (n, e) est convertie en clé PEM pour openssl_verify.
 */
final class OidcVerifier
{
    public const APPLE = ['jwks' => 'https://appleid.apple.com/auth/keys', 'issuers' => ['https://appleid.apple.com']];
    public const GOOGLE = ['jwks' => 'https://www.googleapis.com/oauth2/v3/certs', 'issuers' => ['https://accounts.google.com', 'accounts.google.com']];

    /** @var \Closure(string): array|null clés de test */
    public static ?\Closure $jwksLoader = null;

    /**
     * @param array{jwks: string, issuers: list<string>} $provider
     * @param list<string> $audiences
     * @return array<string, mixed> claims
     */
    public static function verify(string $token, array $provider, array $audiences): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw self::invalid();
        }
        $header = json_decode(self::unb64($parts[0]), true);
        $claims = json_decode(self::unb64($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || !isset($header['kid'])) {
            throw self::invalid();
        }

        $key = self::key($provider['jwks'], (string) $header['kid']);
        if ($key === null || openssl_verify($parts[0] . '.' . $parts[1], self::unb64($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw self::invalid();
        }

        $now = time();
        $aud = (array) ($claims['aud'] ?? []);
        if (!in_array($claims['iss'] ?? null, $provider['issuers'], true)
            || array_intersect($aud, $audiences) === []
            || (int) ($claims['exp'] ?? 0) < $now - 60
            || (int) ($claims['iat'] ?? 0) > $now + 300
            || empty($claims['sub'])) {
            throw self::invalid();
        }

        return $claims;
    }

    /** Clé publique PEM de la JWKS ; si le kid est inconnu (rotation des clés), la JWKS est rechargée une fois. */
    private static function key(string $jwksUrl, string $kid): ?string
    {
        foreach ([false, true] as $refresh) {
            $jwks = self::jwks($jwksUrl, $refresh);
            foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
                if (($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'RSA') {
                    return self::pem((string) $jwk['n'], (string) $jwk['e']);
                }
            }
        }

        return null;
    }

    private static function jwks(string $url, bool $refresh): array
    {
        if (self::$jwksLoader !== null) {
            return (self::$jwksLoader)($url);
        }
        $cacheKey = 'oidc:jwks:' . sha1($url);
        if (!$refresh && is_array($cached = Cache::store()->get($cacheKey))) {
            return $cached;
        }
        // Rechargement forcé limité à une fois par minute (un kid inventé ne doit pas déclencher d'appels en boucle)
        if ($refresh && !Cache::store()->add($cacheKey . ':refresh', 60)) {
            return is_array($cached = Cache::store()->get($cacheKey)) ? $cached : [];
        }
        try {
            $jwks = (new HttpClient(timeout: 10, retries: 1))->getJson($url);
        } catch (\Throwable) {
            throw new HttpException(503, 'upstream_unavailable', 'Connexion momentanément indisponible, réessaie dans un instant.');
        }
        Cache::store()->set($cacheKey, $jwks, 86400);

        return $jwks;
    }

    /** JWK RSA (modulus n, exposant e en base64url) → clé publique PEM (SubjectPublicKeyInfo). */
    public static function pem(string $n, string $e): string
    {
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if (ord($bytes[0]) > 0x7F) {
                $bytes = "\0" . $bytes; // entier positif
            }
            return "\x02" . self::len(strlen($bytes)) . $bytes;
        };
        $rsa = $int(self::unb64($n)) . $int(self::unb64($e));
        $rsa = "\x30" . self::len(strlen($rsa)) . $rsa;
        $bitString = "\x03" . self::len(strlen($rsa) + 1) . "\0" . $rsa;
        $algo = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00"; // rsaEncryption, NULL
        $spki = "\x30" . self::len(strlen($algo . $bitString)) . $algo . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private static function invalid(): HttpException
    {
        return HttpException::unauthorized('invalid_identity_token', 'Connexion refusée par le fournisseur, réessaie.');
    }
}
