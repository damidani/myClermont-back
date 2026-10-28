<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Module;
use App\Core\Router;
use App\Modules\Account\Controllers\AuthController;
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
        $r->post('/auth/register', [AuthController::class, 'register'], ['throttle:login,ip']);
        $r->post('/auth/login', [AuthController::class, 'login'], ['throttle:login,ip']);
        $r->post('/auth/refresh', [AuthController::class, 'refresh']);

        $r->get('/neighborhoods', [ProfileController::class, 'neighborhoods']);
        $r->get('/users/username-available', [ProfileController::class, 'usernameAvailable']);
        $r->get('/users/{username}', [ProfileController::class, 'show']);

        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/auth/logout', [AuthController::class, 'logout']);
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
