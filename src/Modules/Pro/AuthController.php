<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;
use App\Security\Jwt;

/**
 * Comptes commerçants et session web (demande 1).
 *
 *   POST /pro/auth/signup | login | refresh | logout
 *   POST /pro/auth/password/forgot | password/reset ; POST /pro/auth/password (connecté)
 *   GET  /pro/auth/sessions ; DELETE /pro/auth/sessions/{id}
 *
 * Jeton d'accès JWT « pro » (15 min) + jeton de rafraîchissement dans le cookie mc_pro_rt (HttpOnly, Secure, SameSite=Strict),
 * qui tourne à chaque refresh : un ancien jeton rejoué révoque la session entière.
 */
final class AuthController
{
    private const COOKIE = 'mc_pro_rt';
    private const ACTIVITIES = ['restaurant', 'cafe', 'fastFood', 'boulangerie', 'commerce', 'culture', 'sport', 'services', 'immobilier', 'hebergement'];
    private const GOALS = ['visibilite', 'avis', 'bonsPlans', 'evenements', 'emplois', 'devis', 'logements'];

    public function signup(Request $request): Response
    {
        $j = $request->json();
        $errors = [];
        $s = static function (string $k, int $max, string $label) use ($j, &$errors): string {
            return Pro::str($j, $k, $max, $errors, $label) ?? '';
        };
        $trade = $s('trade_name', 60, 'Le nom commercial');
        $legal = $s('legal_name', 160, 'La raison sociale');
        $siret = preg_replace('/\s+/', '', $s('siret', 20, 'Le SIRET'));
        $naf = $s('naf_code', 10, 'Le code NAF');
        $address = $s('address', 200, 'L\'adresse');
        $postal = $s('postal_code', 5, 'Le code postal');
        $city = $s('city', 100, 'La ville');
        $first = $s('first_name', 80, 'Le prénom');
        $last = $s('last_name', 80, 'Le nom');
        $jobTitle = $s('job_title', 80, 'La fonction');
        $phone = $s('phone', 30, 'Le téléphone');
        $email = mb_strtolower($s('email', 191, 'L\'e-mail'));
        $activity = $j['activity'] ?? null;

        if (mb_strlen($trade) < 2) {
            $errors['trade_name'] = 'Indique le nom de ton établissement.';
        }
        if ($siret !== '' && !preg_match('/^\d{14}$/', $siret)) {
            $errors['siret'] = 'Le SIRET compte 14 chiffres.';
        }
        if (!in_array($activity, self::ACTIVITIES, true)) {
            $errors['activity'] = 'Choisis ton activité.';
        }
        if ($address === '' || $city === '') {
            $errors['address'] = 'Indique l\'adresse de l\'établissement.';
        }
        if ($first === '') {
            $errors['first_name'] = 'Indique ton prénom.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (($j['accepts_terms'] ?? false) !== true) {
            $errors['accepts_terms'] = 'Accepte les conditions d\'utilisation pour continuer.';
        }
        if (($j['certifies'] ?? false) !== true) {
            $errors['certifies'] = 'Certifie être habilité à représenter l\'établissement.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $password = Pro::checkPassword($j['password'] ?? null);
        if (Database::fetch('SELECT 1 FROM pro_members WHERE email = :e', ['e' => $email])) {
            throw new HttpException(409, 'conflict', 'Un compte commerçant existe déjà avec cet e-mail.', ['reason' => 'email_taken']);
        }
        $goals = array_values(array_intersect(self::GOALS, is_array($j['goals'] ?? null) ? $j['goals'] : []));
        $modules = array_values(array_unique([...Config::get('pro.activity_modules.' . $activity, []),
            ...array_intersect($goals, ['bonsPlans', 'evenements', 'emplois', 'devis', 'logements'])]));

        // SIRET revérifié auprès de l'annuaire des entreprises (sans clé) ; sans réponse, l'équipe vérifiera
        $verification = 'a_verifier';
        $nafLabel = null;
        if ($siret !== '') {
            $found = Sirene::lookup($siret);
            $verification = $found === null ? 'en_cours' : ($found['active'] ? 'verifie' : 'en_cours');
            $legal = $legal !== '' ? $legal : (string) ($found['name'] ?? '');
            $naf = $naf !== '' ? $naf : (string) ($found['naf'] ?? '');
            $nafLabel = $found['naf_label'] ?? null;
        }
        $lat = is_numeric($j['latitude'] ?? null) ? (float) $j['latitude'] : null;
        $lon = is_numeric($j['longitude'] ?? null) ? (float) $j['longitude'] : null;

        $now = gmdate('Y-m-d H:i:s');
        $memberId = Database::transaction(function () use ($trade, $legal, $siret, $naf, $nafLabel, $activity, $modules, $goals, $verification, $j, $now,
            $email, $password, $first, $last, $jobTitle, $phone, $address, $postal, $city, $lat, $lon): int {
            $businessId = Database::insert(
                'INSERT INTO pro_businesses (uid, trade_name, legal_name, siret, naf_code, naf_label, activity, modules, goals, verification, newsletter, consent_version, consented_at, created_at, updated_at)
                 VALUES (:u, :t, :l, :s, :n, :nl, :a, :m, :g, :v, :nw, :cv, :ca, :c, :up)',
                ['u' => Crypto::uuid4(), 't' => $trade, 'l' => $legal, 's' => $siret, 'n' => $naf ?: null, 'nl' => $nafLabel, 'a' => $activity,
                 'm' => implode(',', $modules), 'g' => implode(',', $goals), 'v' => $verification, 'nw' => (int) (($j['newsletter'] ?? false) === true),
                 'cv' => is_string($j['consent_version'] ?? null) ? mb_substr($j['consent_version'], 0, 40) : null, 'ca' => $now, 'c' => $now, 'up' => $now],
            );
            $placeId = PlaceController::createDraft($businessId, [
                'name' => $trade, 'activity' => $activity, 'address' => $address, 'postal_code' => $postal, 'city' => $city,
                'latitude' => $lat, 'longitude' => $lon, 'phone' => ($j['phone_on_place'] ?? false) === true ? $phone : '', 'email' => '',
            ]);
            Database::execute('UPDATE pro_businesses SET place_id = :p WHERE id = :id', ['p' => $placeId, 'id' => $businessId]);

            return Database::insert(
                "INSERT INTO pro_members (uid, business_id, email, password_hash, first_name, last_name, job_title, phone, role, status, created_at, updated_at)
                 VALUES (:u, :b, :e, :p, :f, :l, :j, :ph, 'proprietaire', 'actif', :c, :up)",
                ['u' => Crypto::uuid4(), 'b' => $businessId, 'e' => $email, 'p' => password_hash($password, PASSWORD_DEFAULT),
                 'f' => $first, 'l' => $last, 'j' => $jobTitle, 'ph' => $phone, 'c' => $now, 'up' => $now],
            );
        });

        return $this->open($request, $memberId, (bool) ($j['remember'] ?? true), 201);
    }

    public function login(Request $request): Response
    {
        $j = $request->json();
        $email = is_string($j['email'] ?? null) ? mb_strtolower(trim($j['email'])) : '';
        $member = $email !== '' ? Database::fetch("SELECT * FROM pro_members WHERE email = :e AND status = 'actif'", ['e' => $email]) : null;
        $ok = $member !== null && is_string($j['password'] ?? null) && password_verify($j['password'], (string) $member['password_hash']);
        if (!$ok) {
            static $dummy = null;
            password_verify('x', $dummy ??= password_hash(random_bytes(8), PASSWORD_DEFAULT)); // même durée qu'un vrai essai
            throw new HttpException(401, 'unauthenticated', 'E-mail ou mot de passe incorrect.', ['reason' => 'invalid_credentials']);
        }

        return $this->open($request, (int) $member['id'], ($j['remember'] ?? false) === true);
    }

    public function refresh(Request $request): Response
    {
        $this->assertOrigin($request);
        $token = self::cookie($request) ?? throw ProAuthMiddleware::invalid();
        $hash = Crypto::hashToken($token);
        $session = Database::fetch('SELECT * FROM pro_sessions WHERE token_hash = :h', ['h' => $hash]);
        if ($session === null) {
            // Jeton déjà utilisé : quelqu'un d'autre l'a peut-être volé, toute la session est fermée
            $stolen = Database::fetch('SELECT id FROM pro_sessions WHERE previous_hash = :h', ['h' => $hash]);
            if ($stolen) {
                Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE id = :id AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $stolen['id']]);
            }
            throw ProAuthMiddleware::invalid();
        }
        if ($session['revoked_at'] !== null || strtotime($session['expires_at'] . ' UTC') < time()) {
            throw ProAuthMiddleware::invalid();
        }
        $member = Database::fetch("SELECT m.* FROM pro_members m JOIN pro_businesses b ON b.id = m.business_id WHERE m.id = :id AND m.status = 'actif' AND b.deleted_at IS NULL",
            ['id' => $session['member_id']]) ?? throw ProAuthMiddleware::invalid();
        $next = Crypto::randomToken(32);
        $ttl = $session['remember'] ? (int) Config::get('pro.remember_ttl') : (int) Config::get('pro.session_ttl');
        Database::execute('UPDATE pro_sessions SET previous_hash = token_hash, token_hash = :n, last_seen_at = :d, expires_at = :x, ip = :ip WHERE id = :id',
            ['n' => Crypto::hashToken($next), 'd' => gmdate('Y-m-d H:i:s'), 'x' => gmdate('Y-m-d H:i:s', time() + $ttl), 'ip' => $request->ip, 'id' => $session['id']]);

        return $this->session($member, $session['uid'])->withHeader('Set-Cookie', self::setCookie($next, $session['remember'] ? $ttl : null));
    }

    public function logout(Request $request): Response
    {
        if ($token = self::cookie($request)) {
            Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE token_hash = :h AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'h' => Crypto::hashToken($token)]);
        }
        if (isset($request->attributes['pro_session'])) {
            Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE id = :id AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $request->attributes['pro_session']['id']]);
        }

        return Response::noContent()->withHeader('Set-Cookie', self::COOKIE . '=; Max-Age=0; Path=/api/v1/pro/auth; HttpOnly; Secure; SameSite=Strict');
    }

    public function forgot(Request $request): Response
    {
        $email = mb_strtolower(trim((string) ($request->json()['email'] ?? '')));
        $member = $email !== '' ? Database::fetch("SELECT id, email, first_name FROM pro_members WHERE email = :e AND status = 'actif'", ['e' => $email]) : null;
        if ($member) {
            $token = Crypto::randomToken(32);
            Database::insert('INSERT INTO pro_password_resets (token_hash, member_id, expires_at, created_at) VALUES (:h, :m, :x, :c)',
                ['h' => Crypto::hashToken($token), 'm' => $member['id'], 'x' => gmdate('Y-m-d H:i:s', time() + (int) Config::get('pro.reset_ttl')), 'c' => gmdate('Y-m-d H:i:s')]);
            Pro::mail($member['email'], 'Réinitialise ton mot de passe MyClermont Pro',
                "Bonjour {$member['first_name']},\n\nPour choisir un nouveau mot de passe, ouvre ce lien (valable 30 minutes) :\n"
                . Config::get('pro.front_url') . '/mot-de-passe/nouveau?token=' . $token
                . "\n\nSi tu n'es pas à l'origine de cette demande, ignore cet e-mail.");
        }

        return Response::noContent();
    }

    public function reset(Request $request): Response
    {
        $j = $request->json();
        $row = is_string($j['token'] ?? null) ? Database::fetch('SELECT * FROM pro_password_resets WHERE token_hash = :h', ['h' => Crypto::hashToken($j['token'])]) : null;
        if ($row === null || $row['used_at'] !== null || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw new HttpException(400, 'validation_failed', 'Ce lien n\'est plus valable : refais une demande.', ['reason' => 'invalid_token']);
        }
        $password = Pro::checkPassword($j['password'] ?? null);
        $now = gmdate('Y-m-d H:i:s');
        Database::execute('UPDATE pro_password_resets SET used_at = :d WHERE token_hash = :h', ['d' => $now, 'h' => $row['token_hash']]);
        Database::execute('UPDATE pro_members SET password_hash = :p, updated_at = :d WHERE id = :id', ['p' => password_hash($password, PASSWORD_DEFAULT), 'd' => $now, 'id' => $row['member_id']]);
        Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE member_id = :m AND revoked_at IS NULL', ['d' => $now, 'm' => $row['member_id']]);

        return Response::noContent();
    }

    public function changePassword(Request $request): Response
    {
        $j = $request->json();
        $m = Pro::member($request);
        if (!is_string($j['current'] ?? null) || !password_verify($j['current'], (string) $m['password_hash'])) {
            throw new HttpException(403, 'forbidden', 'Mot de passe actuel incorrect.', ['reason' => 'invalid_password']);
        }
        $password = Pro::checkPassword($j['next'] ?? null, 'next');
        $now = gmdate('Y-m-d H:i:s');
        Database::execute('UPDATE pro_members SET password_hash = :p, updated_at = :d WHERE id = :id', ['p' => password_hash($password, PASSWORD_DEFAULT), 'd' => $now, 'id' => $m['id']]);
        // Les autres connexions sont fermées, celle-ci reste ouverte
        Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE member_id = :m AND id <> :s AND revoked_at IS NULL', ['d' => $now, 'm' => $m['id'], 's' => $request->attributes['pro_session']['id']]);

        return Response::noContent();
    }

    public function sessions(Request $request): Response
    {
        $current = $request->attributes['pro_session']['id'];
        $rows = Database::fetchAll('SELECT id, uid, device, last_seen_at FROM pro_sessions WHERE member_id = :m AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY last_seen_at DESC',
            ['m' => Pro::member($request)['id']]);

        return Response::success(array_map(static fn (array $s) => ['id' => $s['uid'], 'device' => $s['device'] ?: 'Navigateur',
            'last_seen_at' => Pro::iso($s['last_seen_at']), 'current' => (int) $s['id'] === $current], $rows));
    }

    public function revokeSession(Request $request): Response
    {
        $n = Database::execute('UPDATE pro_sessions SET revoked_at = :d WHERE uid = :u AND member_id = :m AND revoked_at IS NULL',
            ['d' => gmdate('Y-m-d H:i:s'), 'u' => (string) $request->param('id'), 'm' => Pro::member($request)['id']]);
        if ($n === 0) {
            throw new HttpException(404, 'not_found', 'Session introuvable.');
        }

        return Response::noContent();
    }

    public function twoFactor(Request $request): Response
    {
        throw new HttpException(501, 'not_configured', 'La double authentification arrive bientôt.', ['reason' => 'not_available']);
    }

    /** Ouvre une session : jeton d'accès + cookie de rafraîchissement. */
    private function open(Request $request, int $memberId, bool $remember, int $status = 200): Response
    {
        $token = Crypto::randomToken(32);
        $ttl = $remember ? (int) Config::get('pro.remember_ttl') : (int) Config::get('pro.session_ttl');
        $uid = Crypto::uuid4();
        $now = gmdate('Y-m-d H:i:s');
        Database::insert(
            'INSERT INTO pro_sessions (uid, member_id, token_hash, remember, device, ip, last_seen_at, expires_at, created_at) VALUES (:u, :m, :h, :r, :d, :ip, :l, :x, :c)',
            ['u' => $uid, 'm' => $memberId, 'h' => Crypto::hashToken($token), 'r' => (int) $remember, 'd' => self::device((string) $request->header('user-agent', '')),
             'ip' => $request->ip, 'l' => $now, 'x' => gmdate('Y-m-d H:i:s', time() + $ttl), 'c' => $now],
        );
        $member = Database::fetch('SELECT * FROM pro_members WHERE id = :id', ['id' => $memberId]);

        return $this->session($member, $uid, $status)->withHeader('Set-Cookie', self::setCookie($token, $remember ? $ttl : null));
    }

    private function session(array $member, string $sessionUid, int $status = 200): Response
    {
        $ttl = (int) Config::get('pro.access_ttl', 900);
        $business = Database::fetch('SELECT * FROM pro_businesses WHERE id = :id', ['id' => $member['business_id']]);
        $access = Jwt::encode(['iss' => Config::get('security.jwt.issuer'), 'typ' => 'pro', 'sub' => (int) $member['id'], 'sid' => $sessionUid,
                               'iat' => time(), 'exp' => time() + $ttl]);

        return Response::success(['access_token' => $access, 'expires_in' => $ttl, 'merchant' => ProPresenter::merchant($member),
                                  'business' => ProPresenter::business($business), 'role' => $member['role']], $status);
    }

    private static function setCookie(string $token, ?int $maxAge): string
    {
        return self::COOKIE . '=' . $token . ($maxAge !== null ? '; Max-Age=' . $maxAge : '') . '; Path=/api/v1/pro/auth; HttpOnly; Secure; SameSite=Strict';
    }

    private static function cookie(Request $request): ?string
    {
        foreach (explode(';', (string) $request->header('cookie', '')) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === self::COOKIE && preg_match('/^[A-Za-z0-9_-]{20,128}$/', $v)) {
                return $v;
            }
        }

        return null;
    }

    /** Le refresh n'utilise que le cookie : l'origine doit être le front (protection CSRF en plus de SameSite). */
    private function assertOrigin(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin !== null && !in_array($origin, (array) Config::get('pro.origins', []), true)) {
            throw new HttpException(403, 'forbidden', 'Origine non autorisée.', ['reason' => 'origin']);
        }
    }

    /** « Chrome · macOS » à partir du User-Agent. */
    private static function device(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Navigateur',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Linux') => 'Linux', default => '',
        };

        return $browser . ($os !== '' ? ' · ' . $os : '');
    }
}
