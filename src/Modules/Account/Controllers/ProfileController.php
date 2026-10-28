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
use App\Repositories\RefreshTokenRepository;
use App\Services\TokenService;
use App\Support\Validator;

final class ProfileController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /** GET /v1/users/me — profil complet de l'utilisateur connecté */
    public function me(Request $request): Response
    {
        $user = $this->currentUser($request);

        return Response::success(UserRepository::present($user, (new GamificationRepository())->badgesOf((int) $user['id']), true));
    }

    /** PATCH /v1/users/me — pseudo, bio, quartier */
    public function update(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'username'        => 'username',
            'bio'             => 'nullable|string|max:500',
            'neighborhood_id' => 'nullable|integer',
        ]);
        $user = $this->currentUser($request);

        $errors = [];
        if (isset($data['username'])
            && (in_array(strtolower($data['username']), UserRepository::RESERVED_USERNAMES, true)
                || $this->users->usernameTaken($data['username'], (int) $user['id']))) {
            $errors['username'] = 'Ce pseudo est déjà pris.';
        }
        if (array_key_exists('username', $data) && $data['username'] === null) {
            $errors['username'] = 'Le pseudo ne peut pas être vide.';
        }
        if (isset($data['neighborhood_id']) && !(new NeighborhoodRepository())->exists($data['neighborhood_id'])) {
            $errors['neighborhood_id'] = 'Quartier inconnu.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->update((int) $user['id'], $data);
        Events::dispatch('account.profile_updated', ['user_id' => (int) $user['id'], 'user' => $user]);

        $user = $this->users->findById((int) $user['id']) ?? $user; // points éventuellement gagnés

        return Response::success(UserRepository::present($user, (new GamificationRepository())->badgesOf((int) $user['id']), true));
    }

    /**
     * PUT /v1/users/me/password
     * Ferme toutes les sessions (tous appareils) et renvoie de nouveaux jetons pour l'appareil courant.
     */
    public function changePassword(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'current_password' => 'required|string|max:128',
            'new_password'     => 'required|string|min:8|max:128',
        ]);
        $user = $this->currentUser($request);

        if (!password_verify($data['current_password'], $user['password_hash'])) {
            throw new ValidationException(['current_password' => 'Mot de passe actuel incorrect.']);
        }

        $this->users->updatePasswordHash((int) $user['id'], UserRepository::hashPassword($data['new_password']));
        (new RefreshTokenRepository())->revokeAllForUser((int) $user['id']);

        return Response::success((new TokenService())->issue((int) $user['id'], $request->attributes['device']));
    }

    /** GET /v1/users/{username} — profil public (sans e-mail) */
    public function show(Request $request): Response
    {
        $user = $this->users->findByUsername((string) $request->param('username'));
        if ($user === null || $user['status'] !== 'active') {
            throw new HttpException(404, 'user_not_found', 'Utilisateur introuvable.');
        }

        return Response::success(UserRepository::present($user, (new GamificationRepository())->badgesOf((int) $user['id'])));
    }

    /** GET /v1/users/username-available?username=xxx — vérification en direct pendant la saisie */
    public function usernameAvailable(Request $request): Response
    {
        $data = Validator::validate($request->query(), ['username' => 'required|username']);
        $available = !in_array(strtolower($data['username']), UserRepository::RESERVED_USERNAMES, true)
            && !$this->users->usernameTaken($data['username']);

        return Response::success(['username' => $data['username'], 'available' => $available]);
    }

    /** GET /v1/neighborhoods */
    public function neighborhoods(Request $request): Response
    {
        return Response::success((new NeighborhoodRepository())->all());
    }

    private function currentUser(Request $request): array
    {
        $user = $this->users->findById($request->attributes['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw HttpException::unauthorized('unauthenticated', 'Compte introuvable ou désactivé.');
        }

        return $user;
    }
}
