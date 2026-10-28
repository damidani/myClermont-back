<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;
use App\Modules\Messaging\MessagingContexts;

/**
 * Petites annonces : acheter, vendre, donner. Contact par la messagerie myClermont.
 *
 * Événements émis :
 *   classifieds.published {user_id, classified_uid, is_free, category}
 *   classifieds.sold      {user_id, classified_uid, is_free}
 */
final class ClassifiedsModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/classifieds', [ClassifiedController::class, 'index']);
            $r->get('/classifieds/filters', [ClassifiedController::class, 'filtersCatalog']);
            $r->get('/classifieds/{id}', [ClassifiedController::class, 'show']);
        });

        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/classifieds', [ClassifiedController::class, 'create'], ['throttle:listing,device']);
            $r->patch('/classifieds/{id}', [ClassifiedController::class, 'update']);
            $r->patch('/classifieds/{id}/status', [ClassifiedController::class, 'status']);
            $r->delete('/classifieds/{id}', [ClassifiedController::class, 'delete']);
            $r->post('/classifieds/{id}/contact', [ClassifiedController::class, 'contact'], ['throttle:contact,device']);
            $r->post('/classifieds/{id}/report', [ClassifiedController::class, 'report']);
            $r->get('/users/me/classifieds', [ClassifiedController::class, 'mine']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('classifieds', new ClassifiedImportTarget());

        FavoriteTypes::register('classified', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new ClassifiedRepository())->findManyByUid($ids) as $uid => $a) {
                    $out[$uid] = ['tags' => [$a['category_slug']], 'summary' => ClassifiedPresenter::card($a)];
                }

                return $out;
            }
        });

        // En-tête des conversations : titre, prix, photo / icône et statut de l'annonce
        MessagingContexts::register('classified', static function (array $ids): array {
            $out = [];
            foreach ((new ClassifiedRepository())->findManyByUid($ids) as $uid => $a) {
                $card = ClassifiedPresenter::card($a);
                $out[$uid] = [
                    'title'     => $card['title'],
                    'subtitle'  => $card['price']['label'],
                    'image_url' => $card['cover_url'],
                    'icon'      => $card['category']['icon'],
                    'color'     => $card['category']['color'],
                    'status'    => $card['status'],
                ];
            }

            return $out;
        });
    }
}
