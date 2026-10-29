<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\Documents\DocumentController;
use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Emploi & stages : offres, filtres par contrat, fiche, candidature avec CV / lettre (PDF).
 *
 * Événement émis : jobs.application_submitted {user_id|null, offer_uid, application_uid}
 */
final class JobsModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/jobs', [JobController::class, 'index']);
            $r->get('/jobs/filters', [JobController::class, 'filtersCatalog']);
            $r->get('/jobs/{id}', [JobController::class, 'show']);
            $r->post('/jobs/{id}/apply', [JobController::class, 'apply'], ['throttle:contact,device']);
            $r->post('/jobs/{id}/applications', [JobController::class, 'apply'], ['throttle:contact,device']);   // spec v1

            // Documents (CV, lettres) : envoi en corps brut application/pdf, 5 Mo max
            $r->post('/documents', [DocumentController::class, 'upload'], ['throttle:upload,device']);
            $r->delete('/documents/{id}', [DocumentController::class, 'delete']);
        });

        $r->get('/users/me/applications', [JobController::class, 'mine'], ['auth']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('jobs', new JobImportTarget());

        FavoriteTypes::register('job', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new JobRepository())->findManyByUid($ids) as $uid => $offer) {
                    $out[$uid] = ['tags' => [$offer['contract'], $offer['category_slug']], 'summary' => JobPresenter::card($offer)];
                }

                return $out;
            }
        });
    }
}
