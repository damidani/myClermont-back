<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Account\Repositories\UserRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\RefreshTokenRepository;
use App\Security\AppleClient;
use App\Security\Crypto;
use App\Security\OidcVerifier;
use App\Services\TokenService;
use App\Support\Mailer;
use App\Support\Validator;

/**
 * Connexion et compte selon la spec v1 (docs/SPEC_V1.txt › Authentification et comptes).
 *
 *   POST /auth/apple          identityToken, authorizationCode, nonce, fullName?   → AuthSession
 *   POST /auth/google         idToken                                               → AuthSession
 *   POST /auth/email/start    email                                                 → 204
 *   POST /auth/email/verify   email, code                                           → AuthSession
 *   POST /auth/refresh        refreshToken                                          → AuthSession
 *   POST /auth/logout         refreshToken                                          → 204
 *   GET | PATCH | DELETE /me
 */
final class IdentityController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function apple(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'identity_token'     => 'required|string|max:4096',
            'authorization_code' => 'nullable|string|max:512',
            'nonce'              => 'required|string|max:256',
            'full_name'          => 'nullable|string|max:80',
        ]);
        $claims = OidcVerifier::verify($data['identity_token'], OidcVerifier::APPLE, [(string) Config::get('auth.apple.bundle_id')]);
        // L'app envoie le nonce brut ; Apple a signé son empreinte SHA-256
        if (!hash_equals(hash('sha256', $data['nonce']), (string) ($claims['nonce'] ?? ''))) {
            throw HttpException::unauthorized('invalid_nonce', 'Connexion refusée, réessaie.');
        }

        $user = $this->users->findBySub('apple_sub', (string) $claims['sub']);
        $created = false;
        if ($user === null) {
            $email = isset($claims['email']) && self::verified($claims) ? (string) $claims['email'] : null;
            $user = $email !== null ? $this->users->findByEmail($email) : null;
            if ($user !== null) {
                $this->users->linkSub((int) $user['id'], 'apple_sub', (string) $claims['sub']);
            } else {
                $user = $this->users->createFromIdentity(['email' => $email, 'apple_sub' => (string) $claims['sub'], 'display_name' => $data['full_name'] ?? null]);
                $created = true;
            }
        }
        // Apple ne donne le prénom qu'à la toute première autorisation : on le garde
        $this->keepFirstName($user, $data['full_name'] ?? null);
        // Jeton Apple gardé chiffré : il sert à révoquer l'accès quand le compte est supprimé (règle de l'App Store)
        if (!empty($data['authorization_code']) && ($appleToken = (new AppleClient())->exchangeCode($data['authorization_code'])) !== null) {
            Database::execute('UPDATE users SET apple_refresh_encrypted = :t WHERE id = :id', ['t' => Crypto::encrypt($appleToken), 'id' => $user['id']]);
        }

        return $this->socialSession($user, $request, $created, isset($claims['email']) ? (string) $claims['email'] : null);
    }

    public function google(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['id_token' => 'required|string|max:4096']);
        $audiences = (array) Config::get('auth.google.client_ids', []);
        if ($audiences === []) {
            throw new HttpException(503, 'not_configured', 'La connexion Google n\'est pas encore disponible.');
        }
        $claims = OidcVerifier::verify($data['id_token'], OidcVerifier::GOOGLE, $audiences);

        $user = $this->users->findBySub('google_sub', (string) $claims['sub']);
        $created = false;
        if ($user === null) {
            $email = isset($claims['email']) && self::verified($claims) ? (string) $claims['email'] : null;
            $user = $email !== null ? $this->users->findByEmail($email) : null;
            if ($user !== null) {
                $this->users->linkSub((int) $user['id'], 'google_sub', (string) $claims['sub']);
            } else {
                $user = $this->users->createFromIdentity(['email' => $email, 'google_sub' => (string) $claims['sub'], 'display_name' => $claims['given_name'] ?? $claims['name'] ?? null]);
                $created = true;
            }
        }
        $this->keepFirstName($user, isset($claims['given_name']) ? (string) $claims['given_name'] : (isset($claims['name']) ? (string) $claims['name'] : null));

        return $this->socialSession($user, $request, $created, isset($claims['email']) ? (string) $claims['email'] : null);
    }

    /** Toujours 204 (même pour une adresse inconnue) : on ne révèle pas qui a un compte. */
    public function emailStart(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email|max:191']);
        $email = mb_strtolower($data['email']);
        [$max, $window] = (array) Config::get('auth.email_code.per_email', [3, 600]);
        if (Cache::store()->increment('auth:email:' . sha1($email), (int) $window) > (int) $max) {
            throw new HttpException(429, 'rate_limited', 'Trop de codes demandés, réessaie dans quelques minutes.', null, ['Retry-After' => (string) $window]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::execute(
            'REPLACE INTO auth_email_codes (email, code_hash, attempts, expires_at, created_at) VALUES (:e, :h, 0, :x, :c)',
            ['e' => $email, 'h' => self::codeHash($email, $code), 'x' => gmdate('Y-m-d H:i:s', time() + (int) Config::get('auth.email_code.ttl', 600)), 'c' => gmdate('Y-m-d H:i:s')],
        );
        $sent = (new Mailer())->send($email, "Ton code My Clermont : {$code}",
            "Bonjour,\n\nVoici ton code de connexion à My Clermont : {$code}\n\nIl est valable 10 minutes. "
            . "Si tu n'as rien demandé, ignore simplement ce message.\n\nL'équipe My Clermont");
        if (!$sent) {
            Logger::error('Code de connexion : e-mail non envoyé', ['email_hash' => sha1($email)]);
        }

        return Response::noContent();
    }

    public function emailVerify(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email|max:191', 'code' => 'required|string|max:6']);
        $email = mb_strtolower($data['email']);

        $row = Database::fetch('SELECT code_hash, attempts, expires_at FROM auth_email_codes WHERE email = :e', ['e' => $email]);
        $invalid = new ValidationException(['code' => 'Code incorrect ou expiré.']);
        if ($row === null || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw $invalid;
        }
        if (!hash_equals($row['code_hash'], self::codeHash($email, $data['code']))) {
            // Au-delà du nombre d'essais, le code est détruit : impossible de deviner 6 chiffres
            Database::execute(
                (int) $row['attempts'] + 1 >= (int) Config::get('auth.email_code.max_attempts', 5)
                    ? 'DELETE FROM auth_email_codes WHERE email = :e'
                    : 'UPDATE auth_email_codes SET attempts = attempts + 1 WHERE email = :e',
                ['e' => $email],
            );
            throw $invalid;
        }
        Database::execute('DELETE FROM auth_email_codes WHERE email = :e', ['e' => $email]);

        $user = $this->users->findByEmail($email);
        $created = false;
        if ($user === null) {
            $user = $this->users->createFromIdentity(['email' => $email, 'display_name' => ucfirst((string) strtok($email, '@'))]);
            $created = true;
        }

        return $this->session($user, $request, $created);
    }

    public function refresh(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['refresh_token' => 'required|string|max:200']);
        $result = (new TokenService())->rotate($data['refresh_token'], $request->attributes['device']);
        $user = $this->users->findById($result['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw HttpException::unauthorized('unauthenticated', 'Compte désactivé.');
        }
        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);

        return Response::success(self::authSession($result['tokens'], $user));
    }

    public function logout(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['refresh_token' => 'nullable|string|max:200']);
        $device = $request->attributes['device'];
        $repo = new RefreshTokenRepository();
        if (!empty($data['refresh_token']) && ($row = $repo->findByHash(Crypto::hashToken($data['refresh_token']))) && (int) $row['device_id'] === (int) $device['id']) {
            $row['family_id'] ? $repo->revokeFamily((string) $row['family_id']) : $repo->revoke((int) $row['id']);
        } elseif (isset($request->attributes['user_id'])) {
            $repo->revokeForDevice((int) $request->attributes['user_id'], (int) $device['id']);
        }
        (new DeviceRepository())->attachUser($device, null);

        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        return Response::success(UserRepository::presentSpec($this->current($request)));
    }

    public function update(Request $request): Response
    {
        $input = $request->json();
        $data = Validator::validate($input, [
            'display_name'          => 'string|min:2|max:40',
            'bio'                   => 'nullable|string|max:160',
            'neighborhood'          => 'nullable|string|max:100',
            'notifications_enabled' => 'boolean',
        ]);
        $user = $this->current($request);
        $fields = [];
        $errors = [];

        if (isset($data['display_name'])) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $data['display_name']));
            if (mb_strlen($name) < 2) {
                $errors['display_name'] = 'Ton nom doit faire au moins 2 caractères.';
            } elseif (in_array(mb_strtolower($name), UserRepository::RESERVED_USERNAMES, true)) {
                throw new ValidationException(['display_name' => 'Ce nom n\'est pas disponible, essaie une variante.']);
            } else {
                $fields['display_name'] = $name;
            }
        }
        if (array_key_exists('bio', $data)) {
            $fields['bio'] = $data['bio'] === null || trim($data['bio']) === '' ? null : trim($data['bio']);
        }
        if (array_key_exists('neighborhood', $data)) {
            if ($data['neighborhood'] === null || $data['neighborhood'] === '') {
                $fields['neighborhood_id'] = null;
            } elseif ($hood = Database::fetch('SELECT id FROM neighborhoods WHERE (name = :n OR slug = :n2) AND is_active = 1', ['n' => $data['neighborhood'], 'n2' => $data['neighborhood']])) {
                $fields['neighborhood_id'] = (int) $hood['id'];
            } else {
                $errors['neighborhood'] = 'Quartier inconnu.';
            }
        }
        if (isset($data['notifications_enabled'])) {
            $fields['notifications_enabled'] = $data['notifications_enabled'] ? 1 : 0;
        }
        if (isset($input['avatar'])) {
            $avatar = $input['avatar'];
            if (($avatar['kind'] ?? null) === 'symbol' && in_array($avatar['value'] ?? null, (array) Config::get('auth.avatar_symbols'), true)) {
                $fields += ['avatar_kind' => 'symbol', 'avatar_value' => $avatar['value']];
            } elseif (($avatar['kind'] ?? null) === 'photo' && is_string($avatar['url'] ?? null)
                && str_starts_with($avatar['url'], (string) Config::get('app.media_base_url') . '/')) {
                // Uniquement une photo déjà envoyée sur nos serveurs (POST /uploads)
                $fields += ['avatar_kind' => 'photo', 'avatar_value' => $avatar['url']];
            } else {
                $errors['avatar'] = 'Avatar non autorisé.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Profil du brief §6 : complétion après Apple / Google (tout est obligatoire), ou modification depuis les Réglages
        $profileKeys = ['first_name', 'phone', 'gender', 'gender_detail', 'pronouns', 'pronouns_detail', 'city', 'postal_code',
                        'city_served', 'notify_city_launch', 'marketing_opt_in', 'consent_version', 'age_confirmed'];
        $profile = [];
        if (array_intersect_key($input, array_flip($profileKeys)) !== []) {
            $completing = !\App\Modules\Account\UserProfile::isComplete($user);
            $profile = \App\Modules\Account\UserProfile::columns($input, $completing);
            if ($completing || (isset($profile['consent_version']) && $profile['consent_version'] !== $user['consent_version'])) {
                $profile['consented_at'] = gmdate('Y-m-d H:i:s');
            }
            if (!$completing) {
                unset($profile['age_confirmed_at']);
            }
            // Le nom affiché suit le prénom tant qu'il n'a pas été choisi à part
            if (isset($profile['first_name']) && !isset($fields['display_name']) && ($user['display_name'] === null || $user['display_name'] === $user['first_name'])) {
                $fields['display_name'] = $profile['first_name'];
            }
        }
        $this->users->saveProfile((int) $user['id'], $profile);
        $user = $this->users->update((int) $user['id'], $fields);
        Events::dispatch('account.profile_updated', ['user_id' => (int) $user['id'], 'user' => $user]);

        return Response::success(UserRepository::presentSpec($this->users->findById((int) $user['id']) ?? $user));
    }

    /** 202 : compte désactivé tout de suite, jetons révoqués, données effacées sous 30 jours (account:purge). */
    public function delete(Request $request): Response
    {
        $user = $this->current($request);
        $now = gmdate('Y-m-d H:i:s');
        Database::execute("UPDATE users SET status = 'deleted', deleted_at = :d, updated_at = :d2 WHERE id = :id", ['d' => $now, 'd2' => $now, 'id' => $user['id']]);
        (new RefreshTokenRepository())->revokeAllForUser((int) $user['id']);
        Database::execute('UPDATE devices SET user_id = NULL WHERE user_id = :u', ['u' => $user['id']]);
        (new DeviceRepository())->forget((string) $request->attributes['device']['uid']);

        if ($user['apple_refresh_encrypted']) {
            try {
                (new AppleClient())->revoke(Crypto::decrypt($user['apple_refresh_encrypted']));
            } catch (\Throwable $e) {
                Logger::warning('Révocation Apple en échec (refaite à l\'effacement)', ['user_id' => $user['id'], 'error' => $e->getMessage()]);
            }
        }
        Events::dispatch('account.deleted', ['user_id' => (int) $user['id']]);

        return Response::json(['status' => 'scheduled', 'purgeAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) Config::get('auth.deletion_days', 30))], 202);
    }

    private function session(array $user, Request $request, bool $created): Response
    {
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden('forbidden', $user['status'] === 'deleted' ? 'Ce compte a été supprimé.' : 'Ce compte est suspendu.');
        }
        $device = $request->attributes['device'];
        (new DeviceRepository())->attachUser($device, (int) $user['id']);
        if ($created) {
            Events::dispatch('account.registered', ['user_id' => (int) $user['id'], 'referred_by' => null]);
        }
        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);
        $user = $this->users->findById((int) $user['id']) ?? $user;

        return Response::success(self::authSession((new TokenService())->issue((int) $user['id'], $device, null, (string) $user['role']), $user), $created ? 201 : 200);
    }

    /**
     * SocialSession du brief §6 : jetons émis dans tous les cas ; tant que le profil n'est pas complet (pas de consentVersion),
     * isNewUser = true, user = null, avec l'e-mail et le prénom connus pour préremplir l'inscription (PATCH /me ensuite).
     */
    private function socialSession(array $user, Request $request, bool $created, ?string $tokenEmail): Response
    {
        $session = $this->session($user, $request, $created)->payload['data'];
        $user = $this->users->findById((int) $user['id']) ?? $user;
        if (\App\Modules\Account\UserProfile::isComplete($user)) {
            return Response::success($session + ['is_new_user' => false]);
        }
        $session['user'] = null;

        return Response::success($session + [
            'is_new_user' => true,
            'email'       => (string) ($user['email'] ?? $tokenEmail ?? ''),
            'first_name'  => (string) ($user['first_name'] ?? ''),
        ]);
    }

    private function keepFirstName(array $user, ?string $name): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        if (empty($user['first_name']) && mb_strlen($name) >= 2) {
            $this->users->saveProfile((int) $user['id'], ['first_name' => mb_substr($name, 0, 40)]);
        }
    }

    /** AuthSession de la spec : { accessToken, refreshToken, expiresIn, user } */
    public static function authSession(array $tokens, array $user): array
    {
        return [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'user'          => UserRepository::presentSpec($user),
        ];
    }

    private function current(Request $request): array
    {
        $user = $this->users->findById((int) $request->attributes['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw HttpException::unauthorized('unauthenticated', 'Compte introuvable ou supprimé.');
        }

        return $user;
    }

    private static function verified(array $claims): bool
    {
        $v = $claims['email_verified'] ?? false;

        return $v === true || $v === 'true';
    }

    private static function codeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, (string) Config::get('app.key'));
    }
}
