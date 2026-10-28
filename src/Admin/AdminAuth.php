<?php

declare(strict_types=1);

namespace App\Admin;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Security\Crypto;

/**
 * Connexion au back-office.
 *
 *  - mot de passe Argon2id, double authentification (TOTP) optionnelle ou imposée (ADMIN_REQUIRE_2FA)
 *  - 5 essais / 15 min par IP et par email
 *  - session en base : le cookie (HttpOnly, Secure, SameSite=Strict) ne contient qu'un jeton aléatoire,
 *    stocké haché ; expiration absolue + après inactivité ; jeton CSRF propre à la session
 */
final class AdminAuth
{
    public const COOKIE = 'mc_admin';
    private const LOCK_WINDOW = 900;

    /** Hash d'un mot de passe bidon : même temps de réponse que l'email existe ou non. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$M1NOVGNrNTBJaEo3eGZiTg$ljqgHMl+ymjy7VUimPYwaNSUrNHv2AlS6iLq0E7AeJI';

    /**
     * @return array{status: 'ok'|'2fa'|'invalid'|'locked'|'disabled', admin?: array}
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if (self::locked($email, $ip)) {
            return ['status' => 'locked'];
        }
        $admin = Database::fetch('SELECT * FROM admin_users WHERE email = :e', ['e' => $email]);
        $valid = password_verify($password, $admin['password_hash'] ?? self::DUMMY_HASH);
        if (!$valid || $admin === null) {
            self::fail($email, $ip);
            Audit::log($admin['id'] ?? null, 'login_failed', $ip, null, null, ['email' => $email]);
            return ['status' => 'invalid'];
        }
        if ($admin['status'] !== 'active') {
            return ['status' => 'disabled'];
        }
        if (password_needs_rehash($admin['password_hash'], PASSWORD_ARGON2ID)) {
            Database::execute('UPDATE admin_users SET password_hash = :h WHERE id = :id', ['h' => password_hash($password, PASSWORD_ARGON2ID), 'id' => $admin['id']]);
        }

        return ['status' => $admin['totp_secret'] !== null ? '2fa' : 'ok', 'admin' => $admin];
    }

    /** Étape 2 : code de l'application d'authentification. */
    public static function verifyTotp(array $admin, string $code, string $ip): bool
    {
        if (self::locked($admin['email'], $ip)) {
            return false;
        }
        $lastKey = 'admin:totp-step:' . $admin['id'];
        $step = Totp::verify(Crypto::decrypt($admin['totp_secret']), $code, is_int($last = Cache::store()->get($lastKey)) ? $last : null);
        if ($step === null) {
            self::fail($admin['email'], $ip);
            Audit::log((int) $admin['id'], 'login_2fa_failed', $ip);
            return false;
        }
        Cache::store()->set($lastKey, $step, 120); // un code ne sert qu'une fois

        return true;
    }

    /** Ouvre la session et retourne le jeton à mettre dans le cookie. */
    public static function login(array $admin, string $ip, string $userAgent): string
    {
        $token = Crypto::randomToken(32);
        $now = time();
        Database::execute(
            'INSERT INTO admin_sessions (id_hash, admin_id, csrf_token, data, ip, user_agent, created_at, last_seen_at, expires_at)
             VALUES (:h, :a, :c, NULL, :ip, :ua, :n, :n, :x)',
            ['h' => Crypto::hashToken($token), 'a' => $admin['id'], 'c' => bin2hex(random_bytes(32)), 'ip' => $ip, 'ua' => $userAgent,
             'n' => gmdate('Y-m-d H:i:s', $now), 'x' => gmdate('Y-m-d H:i:s', $now + (int) Config::get('admin.session_ttl', 28800))],
        );
        Database::execute('UPDATE admin_users SET last_login_at = :d, last_login_ip = :ip WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s', $now), 'ip' => $ip, 'id' => $admin['id']]);
        Cache::store()->delete('admin:login:ip:' . $ip);
        Cache::store()->delete('admin:login:email:' . sha1(mb_strtolower($admin['email'])));
        Audit::log((int) $admin['id'], 'login', $ip);
        // Ménage des sessions expirées
        Database::execute('DELETE FROM admin_sessions WHERE expires_at < :d', ['d' => gmdate('Y-m-d H:i:s', $now)]);

        return $token;
    }

    /** @return array{0: array, 1: array}|null [session, admin] */
    public static function session(?string $token): ?array
    {
        if ($token === null || !preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token)) {
            return null;
        }
        $now = time();
        $row = Database::fetch(
            "SELECT s.id_hash, s.csrf_token, s.data, s.last_seen_at, a.id, a.email, a.name, a.role, a.status, a.totp_secret IS NOT NULL AS has_2fa
             FROM admin_sessions s JOIN admin_users a ON a.id = s.admin_id
             WHERE s.id_hash = :h AND s.expires_at > :now AND s.last_seen_at > :idle AND a.status = 'active'",
            ['h' => Crypto::hashToken($token), 'now' => gmdate('Y-m-d H:i:s', $now),
             'idle' => gmdate('Y-m-d H:i:s', $now - (int) Config::get('admin.idle_timeout', 3600))],
        );
        if ($row === null) {
            return null;
        }
        if (strtotime($row['last_seen_at'] . ' UTC') < $now - 60) {
            Database::execute('UPDATE admin_sessions SET last_seen_at = :d WHERE id_hash = :h', ['d' => gmdate('Y-m-d H:i:s', $now), 'h' => $row['id_hash']]);
        }
        $session = ['id_hash' => $row['id_hash'], 'csrf' => $row['csrf_token'], 'data' => json_decode((string) $row['data'], true) ?: []];
        $admin = ['id' => (int) $row['id'], 'email' => $row['email'], 'name' => $row['name'], 'role' => $row['role'], 'has_2fa' => (bool) $row['has_2fa']];

        return [$session, $admin];
    }

    public static function saveData(array $session): void
    {
        Database::execute('UPDATE admin_sessions SET data = :d WHERE id_hash = :h', [
            'd' => $session['data'] === [] ? null : json_encode($session['data'], JSON_UNESCAPED_UNICODE), 'h' => $session['id_hash'],
        ]);
    }

    public static function logout(array $session): void
    {
        Database::execute('DELETE FROM admin_sessions WHERE id_hash = :h', ['h' => $session['id_hash']]);
    }

    public static function cookie(string $value, int $ttl): void
    {
        headers_sent() || setcookie(self::COOKIE, $value, [
            'expires'  => $ttl > 0 ? time() + $ttl : time() - 3600,
            'path'     => '/admin',
            'secure'   => Config::get('app.env') !== 'local',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function locked(string $email, string $ip): bool
    {
        return Cache::store()->get('admin:lock:ip:' . $ip) !== null || Cache::store()->get('admin:lock:email:' . sha1($email)) !== null;
    }

    /** Les compteurs ne sont lisibles qu'au moment de l'incrément (APCu / MySQL) : on pose un verrou au seuil. */
    private static function fail(string $email, string $ip): void
    {
        $max = (int) Config::get('admin.login_attempts', 5);
        if (Cache::store()->increment('admin:login:ip:' . $ip, self::LOCK_WINDOW) >= $max * 4) {
            Cache::store()->set('admin:lock:ip:' . $ip, 1, self::LOCK_WINDOW);
        }
        if (Cache::store()->increment('admin:login:email:' . sha1($email), self::LOCK_WINDOW) >= $max) {
            Cache::store()->set('admin:lock:email:' . sha1($email), 1, self::LOCK_WINDOW);
            Logger::security('Back-office : compte admin verrouillé 15 min après trop d\'échecs', ['email' => $email, 'ip' => $ip]);
        }
    }
}
