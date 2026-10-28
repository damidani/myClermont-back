<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Services : annuaire de professionnels par catégorie, fiche, demande de devis, avis vérifiés.
 *
 * Événements émis :
 *   services.quote_requested {user_id|null, provider_uid}
 *   services.review_saved    {user_id, provider_uid}
 */
final class ServicesModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/services', [ServiceController::class, 'index']);
            $r->get('/services/categories', [ServiceController::class, 'categories']);
            $r->get('/services/{id}', [ServiceController::class, 'show']);
            $r->post('/services/{id}/quote', [ServiceController::class, 'requestQuote'], ['throttle:contact,device']);
        });
        $r->put('/services/{id}/review', [ServiceController::class, 'review'], ['auth']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('services', new ServiceImportTarget());

        FavoriteTypes::register('service', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new ServiceRepository())->findManyByUid($ids) as $uid => $provider) {
                    $out[$uid] = ['tags' => [$provider['category_slug']], 'summary' => ServicePresenter::card($provider)];
                }

                return $out;
            }
        });
    }
}
