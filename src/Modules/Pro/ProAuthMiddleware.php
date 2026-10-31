<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Http\Middleware\Middleware;
use App\Security\Jwt;

/**
 * Session de l'espace commerçant : Authorization: Bearer <accessToken> (jeton « pro », 15 min).
 * 401 details.reason = token_expired (le front rafraîchit puis rejoue) ou invalid_session (retour à la connexion).
 * La session est relue en base à chaque appel : une déconnexion ou un changement de mot de passe agit tout de suite.
 */
final class ProAuthMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken() ?? throw self::invalid();
        try {
            $claims = Jwt::decode($token);
        } catch (HttpException $e) {
            throw $e->errorCode === 'token_expired' ? $e : self::invalid();
        }
        if (($claims['typ'] ?? null) !== 'pro') {
            throw self::invalid();
        }
        $row = Database::fetch(
            "SELECT s.id AS session_id, s.uid AS session_uid, s.last_seen_at, m.*, b.id AS b_id
             FROM pro_sessions s JOIN pro_members m ON m.id = s.member_id JOIN pro_businesses b ON b.id = m.business_id
             WHERE s.uid = :s AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP() AND m.status = 'actif' AND b.deleted_at IS NULL",
            ['s' => (string) ($claims['sid'] ?? '')],
        ) ?? throw self::invalid();
        if ((int) $row['id'] !== (int) ($claims['sub'] ?? 0)) {
            throw self::invalid();
        }
        if (strtotime($row['last_seen_at'] . ' UTC') < time() - 300) {
            Database::execute('UPDATE pro_sessions SET last_seen_at = :d WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $row['session_id']]);
        }
        $request->attributes['pro_session'] = ['id' => (int) $row['session_id'], 'uid' => $row['session_uid']];
        $request->attributes['pro_member'] = $row;
        $request->attributes['pro_business'] = Database::fetch('SELECT * FROM pro_businesses WHERE id = :id', ['id' => $row['b_id']]);

        return $next($request);
    }

    public static function invalid(): HttpException
    {
        return new HttpException(401, 'unauthenticated', 'Ta session a expiré, reconnecte-toi.', ['reason' => 'invalid_session']);
    }
}
