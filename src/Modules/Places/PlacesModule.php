<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Lieux : la Carte (repères compacts, du plus proche au plus loin, ETag / flux SSE) et les fiches lieux.
 * Type de favori "place" → badges Explorateur, Passion volcan, Gourmand.
 *
 * Événement émis : places.reviewed {user_id, place_uid}
 */
final class PlacesModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/places/pins', [PlaceController::class, 'pins']);
            $r->get('/places/pins/stream', [PlaceController::class, 'stream']);
            $r->get('/places/nearby', [PlaceController::class, 'nearby']);
            $r->get('/places/search', [PlaceController::class, 'search']);
            $r->get('/places/{id}', [PlaceController::class, 'show']);
        });
        $r->put('/places/{id}/review', [PlaceController::class, 'review'], ['auth']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('places', new PlaceImportTarget());

        FavoriteTypes::register('place', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new PlaceRepository())->findManyByUid($ids) as $uid => $p) {
                    $tags = array_filter(explode(',', (string) $p['tags']));
                    $tags[] = $p['category_slug'];
                    $out[$uid] = ['tags' => array_values(array_unique($tags)), 'summary' => PlacePresenter::card($p)];
                }

                return $out;
            }
        });
    }
}
