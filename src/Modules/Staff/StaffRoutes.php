<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Router;

/** Espace /admin du dashboard (équipe MyClermont), monté sous /v1/admin et /api/v1/admin, hors signature d'appareil. */
final class StaffRoutes
{
    public static function routes(Router $r): void
    {
        $r->group(['prefix' => '/admin', 'middleware' => ['throttle:pro,ip']], static function (Router $r): void {
            $r->post('/auth/login', [StaffController::class, 'login'], ['throttle:pro_auth,ip']);
            $r->group(['middleware' => ['staff']], static function (Router $r): void {
                $r->post('/auth/logout', [StaffController::class, 'logout']);
                $r->get('/me', [StaffController::class, 'me']);

                $r->get('/team-reviews', [TeamReviewController::class, 'index']);
                $r->get('/places/search', [TeamReviewController::class, 'search']);
                $r->get('/places/{key:.+}/team-review/history', [TeamReviewController::class, 'history']);
                $r->put('/places/{key:.+}/team-review', [TeamReviewController::class, 'put']);
                $r->delete('/places/{key:.+}/team-review', [TeamReviewController::class, 'destroy']);

                $r->get('/visitor-photos', [StaffPhotoController::class, 'index']);
                $r->post('/visitor-photos/validate', [StaffPhotoController::class, 'validate']);
                $r->patch('/visitor-photos/{id}', [StaffPhotoController::class, 'update']);
                $r->post('/visitor-photos/{id}/refuse', [StaffPhotoController::class, 'refuse']);
                $r->post('/visitor-photos/{id}/reopen', [StaffPhotoController::class, 'reopen']);
            });
        });
    }
}
