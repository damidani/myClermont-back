<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Module;
use App\Core\Router;
use App\Modules\Account\Controllers\AuthController;
use App\Modules\Account\Controllers\ExportController;
use App\Modules\Account\Controllers\IdentityController;
use App\Modules\Account\Controllers\ProfileController;

/**
 * Inscription, connexion, sessions, profil (pseudo, bio, quartier), parrainage.
 *
 * Événements émis :
 *   account.registered       {user_id, referred_by}
 *   account.logged_in        {user_id}          connexion, inscription, refresh (= ouverture de l'app)
 *   account.profile_updated  {user_id, user}
 */
final class AccountModule implements Module
{
    public function routes(Router $r): void
    {
        // Spec v1 : Apple (iOS), Google (Android), code par e-mail (secours) — 10 / min par IP
        $r->post('/auth/apple', [IdentityController::class, 'apple'], ['throttle:login,ip']);
        $r->post('/auth/google', [IdentityController::class, 'google'], ['throttle:login,ip']);
        $r->post('/auth/email/start', [IdentityController::class, 'emailStart'], ['throttle:login,ip']);
        $r->post('/auth/email/verify', [IdentityController::class, 'emailVerify'], ['throttle:login,ip']);
        $r->post('/auth/refresh', [IdentityController::class, 'refresh'], ['throttle:login,ip']);
        $r->post('/auth/logout', [IdentityController::class, 'logout'], ['auth:optional']);

        // Inscription e-mail + mot de passe de l'app iOS (brief §6)
        $r->post('/auth/register', [AuthController::class, 'register'], ['throttle:login,ip']);
        $r->post('/auth/login', [AuthController::class, 'login'], ['throttle:login,ip']);
        $r->post('/auth/password/forgot', [AuthController::class, 'forgot'], ['throttle:login,ip']);

        $r->get('/neighborhoods', [ProfileController::class, 'neighborhoods']);
        $r->get('/users/username-available', [ProfileController::class, 'usernameAvailable']);
        $r->get('/users/{username}', [ProfileController::class, 'show']);

        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->get('/me', [IdentityController::class, 'me']);
            $r->patch('/me', [IdentityController::class, 'update']);
            $r->delete('/me', [IdentityController::class, 'delete']);
            $r->get('/me/export', [ExportController::class, 'export'], ['throttle:contact']);
            $r->get('/users/me', [ProfileController::class, 'me']);
            $r->patch('/users/me', [ProfileController::class, 'update']);
            $r->put('/users/me/password', [ProfileController::class, 'changePassword'], ['throttle:login,ip']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
