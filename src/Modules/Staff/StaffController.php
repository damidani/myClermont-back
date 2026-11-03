<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Admin\AdminAuth;
use App\Admin\Audit;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Jwt;

/**
 * Connexion de l'équipe à l'espace /admin du dashboard (ca817cb4) : mêmes comptes et même 2FA que le back-office.
 *   POST /admin/auth/login { email, password, totp? } → { accessToken, expiresIn, admin } ; 401 totp_required si la 2FA est active
 *   POST /admin/auth/logout → 204          GET /admin/me → { id, name, email, role }
 */
final class StaffController
{
    private const TTL = 8 * 3600;

    public function login(Request $request): Response
    {
        $j = $request->json();
        if (!is_string($j['email'] ?? null) || !is_string($j['password'] ?? null)) {
            throw new ValidationException(['email' => 'E-mail et mot de passe requis.']);
        }
        $r = AdminAuth::attempt($j['email'], $j['password'], $request->ip);
        match ($r['status']) {
            'locked' => throw new HttpException(429, 'rate_limited', 'Trop de tentatives : réessaie dans quelques minutes.'),
            'disabled' => throw new HttpException(403, 'forbidden', 'Ce compte est désactivé.'),
            'invalid' => throw new HttpException(401, 'unauthenticated', 'E-mail ou mot de passe incorrect.', ['reason' => 'invalid_credentials']),
            default => null,
        };
        $admin = $r['admin'];
        if ($r['status'] === '2fa') {
            $code = is_string($j['totp'] ?? null) ? preg_replace('/\s+/', '', $j['totp']) : '';
            if ($code === '') {
                throw new HttpException(401, 'unauthenticated', 'Saisis le code de ton application d\'authentification.', ['reason' => 'totp_required']);
            }
            if (!AdminAuth::verifyTotp($admin, $code, $request->ip)) {
                throw new HttpException(401, 'unauthenticated', 'Code incorrect.', ['reason' => 'totp_invalid']);
            }
        }
        Audit::log((int) $admin['id'], 'login_dashboard', $request->ip);
        $token = Jwt::encode(['iss' => \App\Core\Config::get('security.jwt.issuer'), 'typ' => 'staff', 'sub' => (int) $admin['id'], 'iat' => time(), 'exp' => time() + self::TTL]);

        return Response::success(['access_token' => $token, 'expires_in' => self::TTL, 'admin' => self::present($admin)]);
    }

    public function logout(Request $request): Response
    {
        return Response::noContent(); // jeton court sans état : le dashboard l'oublie
    }

    public function me(Request $request): Response
    {
        return Response::success(self::present($request->attributes['staff']));
    }

    private static function present(array $a): array
    {
        return ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'role' => $a['role']];
    }

    /** Prénom affiché par défaut comme auteur (« Léa, de la team MyClermont ») */
    public static function firstName(array $staff): string
    {
        return explode(' ', trim((string) $staff['name']))[0] ?: 'La team';
    }
}
