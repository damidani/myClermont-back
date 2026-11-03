<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;
use App\Http\Middleware\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Jwt;

/**
 * Session de l'équipe MyClermont dans l'espace /admin du dashboard : jeton (typ « staff ») délivré par POST /admin/auth/login
 * à partir des comptes du back-office (admin_users, 2FA). Un jeton commerçant ou de l'app reçoit 403.
 */
final class StaffAuthMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken() ?? throw self::invalid();
        try {
            $claims = Jwt::decode($token);
        } catch (HttpException $e) {
            throw $e->errorCode === 'token_expired' ? $e : self::invalid();
        }
        if (($claims['typ'] ?? null) !== 'staff') {
            throw new HttpException(403, 'forbidden', 'Cet espace est réservé à l\'équipe MyClermont.');
        }
        $admin = Database::fetch("SELECT id, email, name, role FROM admin_users WHERE id = :id AND status = 'active'", ['id' => (int) ($claims['sub'] ?? 0)])
            ?? throw self::invalid();
        $request->attributes['staff'] = $admin;

        return $next($request);
    }

    public static function invalid(): HttpException
    {
        return new HttpException(401, 'unauthenticated', 'Ta session a expiré, reconnecte-toi.', ['reason' => 'invalid_session']);
    }
}
