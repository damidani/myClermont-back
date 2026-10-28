<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Account\Repositories\NeighborhoodRepository;
use App\Modules\Account\Repositories\UserRepository;
use App\Modules\Gamification\GamificationRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\RefreshTokenRepository;
use App\Services\TokenService;
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

    /** POST /v1/auth/register — création de compte */
    public function register(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'email'           => 'required|email|max:191',
            'password'        => 'required|string|min:8|max:128',
            'username'        => 'required|username',
            'neighborhood_id' => 'nullable|integer',
            'referral_code'   => 'nullable|string|max:8',
        ]);

        $errors = [];
        if (in_array(strtolower($data['username']), UserRepository::RESERVED_USERNAMES, true) || $this->users->usernameTaken($data['username'])) {
            $errors['username'] = 'Ce pseudo est déjà pris.';
        }
        if (isset($data['neighborhood_id']) && !(new NeighborhoodRepository())->exists($data['neighborhood_id'])) {
            $errors['neighborhood_id'] = 'Quartier inconnu.';
        }
        $referrerId = null;
        if (isset($data['referral_code']) && ($referrerId = $this->users->findIdByReferralCode($data['referral_code'])) === null) {
            $errors['referral_code'] = 'Code de parrainage invalide.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByEmail($data['email']) !== null) {
            throw new HttpException(409, 'email_taken', 'Un compte existe déjà avec cette adresse e-mail.');
        }

        try {
            $user = $this->users->create($data['email'], $data['password'], $data['username'], $data['neighborhood_id'] ?? null, $referrerId);
        } catch (\PDOException $e) {
            // Inscription simultanée avec le même e-mail / pseudo : la contrainte UNIQUE tranche
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'account_conflict', 'Cet e-mail ou ce pseudo vient d\'être utilisé.');
            }
            throw $e;
        }

        Events::dispatch('account.registered', ['user_id' => (int) $user['id'], 'referred_by' => $referrerId]);
        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);

        return Response::success($this->session($user, $request->attributes['device']), 201);
    }

    /** POST /v1/auth/login */
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
            throw HttpException::forbidden('account_disabled', 'Ce compte est désactivé.');
        }

        if (password_needs_rehash($user['password_hash'], UserRepository::passwordAlgo())) {
            $this->users->updatePasswordHash((int) $user['id'], UserRepository::hashPassword($data['password']));
        }

        Events::dispatch('account.logged_in', ['user_id' => (int) $user['id']]);

        return Response::success($this->session($user, $request->attributes['device']));
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

    private function session(array $user, array $device): array
    {
        (new DeviceRepository())->attachUser($device, (int) $user['id']);

        // Relu après les événements : points et badges à jour (ex. +50 et badge de bienvenue à l'inscription)
        $user = $this->users->findById((int) $user['id']) ?? $user;

        return [
            'user'   => UserRepository::present($user, (new GamificationRepository())->badgesOf((int) $user['id']), true),
            'tokens' => $this->tokens->issue((int) $user['id'], $device),
        ];
    }
}
