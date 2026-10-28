<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\HttpException;
use App\Repositories\RefreshTokenRepository;
use App\Security\Crypto;
use App\Security\Jwt;

/**
 * Émission et rotation des jetons utilisateur.
 *
 *  - access_token  : JWT HS256 court (15 min), lié à l'appareil (claim "did")
 *  - refresh_token : jeton opaque long (30 j), stocké hashé, à usage unique (rotation)
 *
 * Réutilisation d'un refresh token déjà consommé => vol probable :
 * tous les jetons de l'utilisateur sur cet appareil sont révoqués.
 */
final class TokenService
{
    public function __construct(private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository())
    {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, refresh_expires_in: int} */
    public function issue(int $userId, array $device): array
    {
        $accessTtl = (int) Config::get('security.jwt.access_ttl', 900);
        $refreshTtl = (int) Config::get('security.jwt.refresh_ttl', 2592000);
        $now = time();

        $accessToken = Jwt::encode([
            'iss' => Config::get('security.jwt.issuer'),
            'sub' => (string) $userId,
            'did' => $device['uid'],
            'typ' => 'access',
            'iat' => $now,
            'exp' => $now + $accessTtl,
            'jti' => Crypto::randomToken(12),
        ]);

        $refreshToken = Crypto::randomToken(48);
        $this->refreshTokens->create($userId, (int) $device['id'], Crypto::hashToken($refreshToken), $now + $refreshTtl);

        return [
            'access_token'       => $accessToken,
            'token_type'         => 'Bearer',
            'expires_in'         => $accessTtl,
            'refresh_token'      => $refreshToken,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /** @return array{user_id: int, tokens: array} */
    public function rotate(string $refreshToken, array $device): array
    {
        $row = $this->refreshTokens->findByHash(Crypto::hashToken($refreshToken));

        if ($row === null || (int) $row['device_id'] !== (int) $device['id']) {
            throw HttpException::unauthorized('invalid_refresh_token', 'Jeton de rafraîchissement invalide.');
        }

        if ($row['revoked_at'] !== null) {
            $this->refreshTokens->revokeForDevice((int) $row['user_id'], (int) $device['id']);
            Logger::security('Réutilisation d\'un refresh token révoqué : session révoquée', [
                'user_id' => $row['user_id'], 'device_id' => $device['uid'],
            ]);
            throw HttpException::unauthorized('invalid_refresh_token', 'Session révoquée, veuillez vous reconnecter.');
        }

        if (strtotime($row['expires_at'] . ' UTC') < time()) {
            throw HttpException::unauthorized('refresh_token_expired', 'Session expirée, veuillez vous reconnecter.');
        }

        // Révocation atomique : si deux requêtes utilisent le même jeton en même temps, une seule passe
        if (!$this->refreshTokens->revoke((int) $row['id'])) {
            throw HttpException::unauthorized('invalid_refresh_token', 'Jeton de rafraîchissement invalide.');
        }

        return ['user_id' => (int) $row['user_id'], 'tokens' => $this->issue((int) $row['user_id'], $device)];
    }
}
