<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

use App\Core\Module;
use App\Core\Router;

/**
 * Favoris génériques. Ce module ne connaît aucun type : ce sont les modules Lieux, Annonces...
 * qui enregistrent le leur via FavoriteTypes::register() (voir FavoriteProvider).
 *
 * Événements émis :
 *   favorite.added    {user_id, type, target_id, tags}   (seulement si l'élément n'était pas déjà en favori)
 *   favorite.removed  {user_id, type, target_id}
 */
final class FavoritesModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            // Spec v1
            $r->get('/me/favorites', [SpecFavoriteController::class, 'index']);
            $r->post('/me/favorites/import', [SpecFavoriteController::class, 'import']);
            // Brief iOS §9 : clé de l'app, encodée dans l'URL
            $r->put('/me/favorites/{key}', [SpecFavoriteController::class, 'putKey']);
            $r->delete('/me/favorites/{key}', [SpecFavoriteController::class, 'deleteKey']);
            $r->put('/me/favorites/{targetType:[a-z]+}/{targetId:[A-Za-z0-9_-]+}', [SpecFavoriteController::class, 'put']);
            $r->delete('/me/favorites/{targetType:[a-z]+}/{targetId:[A-Za-z0-9_-]+}', [SpecFavoriteController::class, 'delete']);

            $r->get('/users/me/favorites', [FavoriteController::class, 'index']);
            $r->put('/users/me/favorites/{type:[a-z_]+}/{id:[A-Za-z0-9_-]+}', [FavoriteController::class, 'add']);
            $r->delete('/users/me/favorites/{type:[a-z_]+}/{id:[A-Za-z0-9_-]+}', [FavoriteController::class, 'remove']);
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
