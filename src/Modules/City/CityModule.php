<?php

declare(strict_types=1);

namespace App\Modules\City;

use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;

/**
 * Ma Ville, contenus éditoriaux : News par MyClermont (372b9b1b), Associations (e3551d70, remplace Logement),
 * Balade du jour (4220897f §2). Saisie dans /admin ; les associations sont importées du JOAFE (open data).
 *
 *   GET /news?kind=article|video&category=&q=&cursor=      → { items: News[], nextCursor }
 *   GET /news/{id}
 *   GET /associations?category=&q=&cursor=                 → { items: Association[], nextCursor }
 *   GET /associations/{id}
 *   GET /itineraries/featured  (alias /routes/daily)       → { id, title, subtitle, stops: [{ name, latitude, longitude, placeId? }] } ou 404
 */
final class CityModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/news', [CityController::class, 'news']);
            $r->get('/news/{id}', [CityController::class, 'newsItem']);
            $r->get('/associations', [CityController::class, 'associations']);
            $r->get('/associations/{id}', [CityController::class, 'association']);
            $r->get('/itineraries/featured', [CityController::class, 'featuredItinerary']);
            $r->get('/routes/daily', [CityController::class, 'featuredItinerary']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('associations', new AssociationImportTarget());
    }
}
