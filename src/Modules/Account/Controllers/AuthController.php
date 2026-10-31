<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Account\Repositories\UserRepository;
use App\Modules\Account\UserProfile;
use App\Repositories\DeviceRepository;
use App\Repositories\RefreshTokenRepository;
use App\Services\TokenService;
use App\Support\Mailer;
use App\Support\Validator;

final class AuthController
{
    /** Hash factice : password_verify est exécuté même si l'e-mail n'existe pas (temps de réponse constant). */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$N1FQMGQ3VFFXWVF4UFlxaQ$eNZPsFDkwCI56fnDchRgjhJibVzm13WMDY7GffnjuoU';

    private UserRepository $users;
    private TokenService $tokens;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->tokens = new TokenService();
    }

    /**
     * POST /auth/register — ProfileBody de l'app iOS (brief §6) : 201 Session, 409 si l'e-mail existe, 422 si un champ est invalide.
     * L'ancien corps (username, neighborhood_id, referral_code) reste accepté pour les autres clients.
     */
    public function register(Request $request): Response
    {
        $json = $request->json();
        $email = is_string($json['email'] ?? null) ? mb_strtolower(trim($json['email'])) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ValidationException(['email' => 'Ton adresse e-mail n\'est pas valide.'], 422);
        }
        $password = UserProfile::checkPassword($json['password'] ?? null);
        $cols = UserProfile::columns($json, true);

        if ($this->users->findByEmail($email) !== null) {
            throw new HttpException(409, 'conflict', 'Un compte existe déjà avec cet e-mail. Connecte-toi plutôt !', ['reason' => 'email_taken', 'field' => 'email']);
        }
        $referrerId = isset($json['referral_code']) && is_string($json['referral_code']) ? $this->users->findIdByReferralCode($json['referral_code']) : null;

        try {
            $user = $this->users->createFromIdentity(['email' => $email, 'display_name' => $cols['first_name']]);
        } catch (\PDOException $e) {
            // Inscription simultanée avec le même e-mail : la contrainte UNIQUE tranche
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'conflict', 'Un compte existe déjà avec cet e-mail. Connecte-toi plutôt !', ['reason' => 'email_taken', 'field' => 'email']);
            }
            throw $e;
        }
        $this->users->saveProfile((int) $user['id'], $cols + ['consented_at' => gmdate('Y-m-d H:i:s'), 'referred_by' => $referrerId], $password);

        Events::dispatch('account.registered', ['user_id' => (int) $user['id'], 'referred_by' => $referrerId]);
        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);

        return Response::success($this->session($user, $request->attributes['device']), 201);
    }

    /** POST /auth/login {email, password} — 200 Session, 401 si les identifiants sont faux */
    public function login(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'email'    => 'required|email|max:191',
            'password' => 'required|string|max:128',
        ]);

        $user = $this->users->findByEmail($data['email']);
        $valid = password_verify($data['password'], $user['password_hash'] ?? self::DUMMY_HASH);

        if ($user === null || !$valid) {
            throw HttpException::unauthorized('invalid_credentials', 'E-mail ou mot de passe incorrect.');
        }
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden('account_disabled', $user['status'] === 'deleted' ? 'Ce compte a été supprimé.' : 'Ce compte est suspendu.');
        }

        if (password_needs_rehash($user['password_hash'], UserRepository::passwordAlgo())) {
            $this->users->updatePasswordHash((int) $user['id'], UserRepository::hashPassword($data['password']));
        }

        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);

        return Response::success($this->session($user, $request->attributes['device']));
    }

    /**
     * POST /auth/password/forgot {email} — toujours 204 (on ne révèle pas qui a un compte).
     * Lien valable 1 h, à usage unique, vers la page web /password/reset.
     */
    public function forgot(Request $request): Response
    {
        $json = $request->json();
        $email = is_string($json['email'] ?? null) ? mb_strtolower(trim($json['email'])) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'Ton adresse e-mail n\'est pas valide.'], 422);
        }
        $user = $this->users->findByEmail($email);
        if ($user !== null && $user['status'] === 'active') {
            $token = bin2hex(random_bytes(32));
            $now = gmdate('Y-m-d H:i:s');
            Database::execute('UPDATE password_resets SET used_at = :d WHERE user_id = :u AND used_at IS NULL', ['d' => $now, 'u' => $user['id']]);
            Database::insert(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:u, :h, :x, :c)',
                ['u' => $user['id'], 'h' => hash('sha256', $token), 'x' => gmdate('Y-m-d H:i:s', time() + 3600), 'c' => $now],
            );
            $link = rtrim((string) Config::get('app.url'), '/') . '/password/reset?token=' . $token;
            $sent = (new Mailer())->send($email, 'Réinitialise ton mot de passe My Clermont',
                "Bonjour,\n\nTu as demandé à réinitialiser ton mot de passe My Clermont. Choisis-en un nouveau ici :\n{$link}\n\n"
                . "Ce lien est valable 1 heure et ne sert qu'une fois. Si tu n'es pas à l'origine de cette demande, ignore simplement cet e-mail.\n\nL'équipe My Clermont");
            if (!$sent) {
                Logger::error('Réinitialisation du mot de passe : e-mail non envoyé', ['user_id' => $user['id']]);
            }
        }

        return Response::noContent();
    }

    /** POST /v1/auth/refresh — échange un refresh token contre une nouvelle paire de jetons */
    public function refresh(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'refresh_token' => 'required|string|max:128',
        ]);

        $result = $this->tokens->rotate($data['refresh_token'], $request->attributes['device']);

        // L'app rafraîchit ses jetons à chaque ouverture : compte comme visite du jour
        Events::dispatch('account.logged_in', ['user_id' => $result['user_id']]);

        return Response::success($result['tokens']);
    }

    /** POST /v1/auth/logout — ferme la session de cet appareil */
    public function logout(Request $request): Response
    {
        $device = $request->attributes['device'];
        (new RefreshTokenRepository())->revokeForDevice($request->attributes['user_id'], (int) $device['id']);
        (new DeviceRepository())->attachUser($device, null);

        return Response::noContent();
    }

    /** Session du brief §6 : { accessToken, refreshToken, expiresIn, user: UserProfile } */
    private function session(array $user, array $device): array
    {
        (new DeviceRepository())->attachUser($device, (int) $user['id']);

        // Relu après les événements : profil, points et badges à jour
        $user = $this->users->findById((int) $user['id']) ?? $user;

        return IdentityController::authSession($this->tokens->issue((int) $user['id'], $device, null, (string) ($user['role'] ?? 'user')), $user);
    }
}
