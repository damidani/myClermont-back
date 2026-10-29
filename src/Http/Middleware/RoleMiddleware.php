<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Réserve une route à certains rôles (spec v1 : user, partner, moderator, admin), à placer après "auth".
 * L'administrateur passe partout.   Usage : "role:partner,admin"
 */
final class RoleMiddleware implements Middleware
{
    /** @var list<string> */
    private array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function handle(Request $request, callable $next): Response
    {
        $role = $request->attributes['role'] ?? null;
        if ($role === null) {
            throw HttpException::unauthorized('unauthenticated', 'Connecte-toi pour continuer.');
        }
        if ($role !== 'admin' && !in_array($role, $this->roles, true)) {
            throw HttpException::forbidden('forbidden', 'Tu n\'as pas les droits pour cette action.');
        }

        return $next($request);
    }
}
