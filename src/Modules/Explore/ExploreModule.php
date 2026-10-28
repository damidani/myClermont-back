<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Explorer : écran d'accueil éditorial (salutation, météo, stories, événement flash,
 * exclusivités, « À découvrir ») et recherche globale sur tous les modules.
 *
 * Événement émis : explore.offer_claimed {user_id, offer_uid}
 */
final class ExploreModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/explore', [ExploreController::class, 'home']);
            $r->get('/explore/discover', [ExploreController::class, 'discover']);
            $r->get('/explore/stories/{id}', [ExploreController::class, 'story']);
            $r->get('/explore/offers', [ExploreController::class, 'offers']);
            $r->get('/explore/offers/{id}', [ExploreController::class, 'offer']);
            $r->get('/explore/search', [ExploreController::class, 'search']);
            $r->get('/explore/weather', [ExploreController::class, 'weather']);
        });

        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/explore/stories/{id}/view', [ExploreController::class, 'storyViewed']);
            $r->post('/explore/offers/{id}/claim', [ExploreController::class, 'claim']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        // Cœurs des cartes « Exclusivités » et « À découvrir »
        FavoriteTypes::register('offer', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new ExploreRepository())->offersByUid($ids) as $uid => $o) {
                    $out[$uid] = ['tags' => ['bons-plans'], 'summary' => ExplorePresenter::offer($o)];
                }

                return $out;
            }
        });

        FavoriteTypes::register('explore_item', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new ExploreRepository())->itemsByUid($ids) as $uid => $i) {
                    $out[$uid] = ['tags' => [$i['section_slug']], 'summary' => ExplorePresenter::item($i)];
                }

                return $out;
            }
        });
    }
}
