<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Jwt;

/**
 * Authentification utilisateur (Authorization: Bearer <access_token>).
 * Doit être placé APRÈS "device" : le jeton est lié à l'appareil qui l'a obtenu,
 * un jeton volé est donc inutilisable depuis un autre appareil.
 *
 * Volontairement sans accès base (jeton autoporté) : la révocation se fait
 * via la courte durée de vie du jeton d'accès + révocation des refresh tokens.
 */
final class AuthMiddleware implements Middleware
{
    /**
     * @param string $mode "required" (défaut) ou "optional" : en optionnel, une requête sans jeton
     *                     passe (user_id absent) ; un jeton présent mais invalide est toujours refusé
     *                     pour que l'app sache qu'elle doit rafraîchir.  Usage : "auth:optional"
     */
    public function __construct(private readonly string $mode = 'required')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            if ($this->mode === 'optional') {
                return $next($request);
            }
            throw HttpException::unauthorized('unauthenticated', 'Authentification requise.');
        }

        $claims = Jwt::decode($token);
        if (($claims['typ'] ?? null) !== 'access') {
            throw HttpException::unauthorized('invalid_token', 'Jeton d\'accès invalide.');
        }

        $device = $request->attributes['device'] ?? null;
        if ($device === null || ($claims['did'] ?? null) !== $device['uid']) {
            throw HttpException::unauthorized('token_device_mismatch', 'Ce jeton n\'appartient pas à cet appareil.');
        }

        $request->attributes['user_id'] = (int) $claims['sub'];
        $request->attributes['role'] = (string) ($claims['role'] ?? 'user');

        return $next($request);
    }
}
