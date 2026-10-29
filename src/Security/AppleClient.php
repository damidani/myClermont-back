<?php

declare(strict_types=1);

namespace App\Security;

use App\Content\Import\HttpClient;
use App\Core\Config;
use App\Core\Logger;

/**
 * Échanges serveur à serveur avec Apple (Sign in with Apple) :
 *  - échange de l'authorizationCode contre un refresh token Apple (gardé chiffré) ;
 *  - révocation de ce jeton à la suppression du compte (exigée par l'App Store).
 *
 * Le client_secret est un JWT ES256 signé avec la clé .p8 (APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_PRIVATE_KEY).
 * Sans clé configurée, les deux appels sont ignorés (la connexion fonctionne quand même).
 */
final class AppleClient
{
    private const BASE = 'https://appleid.apple.com/auth';

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 10, retries: 1))
    {
    }

    public static function configured(): bool
    {
        return Config::get('auth.apple.team_id') && Config::get('auth.apple.key_id') && Config::get('auth.apple.private_key');
    }

    public function exchangeCode(string $code): ?string
    {
        if (!self::configured() || $code === '') {
            return null;
        }
        $r = $this->http->request('POST', self::BASE . '/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => Config::get('auth.apple.bundle_id'), 'client_secret' => $this->clientSecret(),
            'code' => $code, 'grant_type' => 'authorization_code',
        ]));
        $data = json_decode($r['body'], true);
        if ($r['status'] !== 200 || empty($data['refresh_token'])) {
            Logger::warning('Apple : échange du code refusé', ['status' => $r['status'], 'error' => $data['error'] ?? null]);
            return null;
        }

        return (string) $data['refresh_token'];
    }

    public function revoke(string $refreshToken): bool
    {
        if (!self::configured()) {
            return false;
        }
        $r = $this->http->request('POST', self::BASE . '/revoke', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => Config::get('auth.apple.bundle_id'), 'client_secret' => $this->clientSecret(),
            'token' => $refreshToken, 'token_type_hint' => 'refresh_token',
        ]));

        return $r['status'] === 200;
    }

    /** JWT ES256 valable 10 minutes. */
    public function clientSecret(): string
    {
        $header = ['alg' => 'ES256', 'kid' => Config::get('auth.apple.key_id')];
        $claims = ['iss' => Config::get('auth.apple.team_id'), 'iat' => time(), 'exp' => time() + 600,
                   'aud' => 'https://appleid.apple.com', 'sub' => Config::get('auth.apple.bundle_id')];
        $input = self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $input . '.' . self::b64(self::es256((string) Config::get('auth.apple.private_key'), $input));
    }

    /** Signature ECDSA P-256 au format JWS (r || s, 64 octets) ; openssl la produit en DER. */
    public static function es256(string $pem, string $input): string
    {
        $key = openssl_pkey_get_private(str_replace('\n', "\n", $pem));
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Clé privée Apple (APPLE_PRIVATE_KEY) illisible.');
        }
        // SEQUENCE { INTEGER r, INTEGER s }
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
