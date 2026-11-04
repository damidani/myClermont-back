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

                $r->get('/overview', [MerchantController::class, 'overview']);
                $r->get('/merchants', [MerchantController::class, 'index']);
                $r->get('/merchants/{id}', [MerchantController::class, 'show']);
                $r->post('/merchants/{id}/approve', [MerchantController::class, 'approve']);
                $r->post('/merchants/{id}/request-info', [MerchantController::class, 'requestInfo']);
                $r->post('/merchants/{id}/reject', [MerchantController::class, 'reject']);
                $r->post('/merchants/{id}/plan', [MerchantController::class, 'plan']);

                $r->get('/broadcasts', [BroadcastController::class, 'index']);
                $r->post('/broadcasts', [BroadcastController::class, 'create']);
                $r->post('/broadcasts/audience', [BroadcastController::class, 'audience']);
                $r->patch('/broadcasts/{id}', [BroadcastController::class, 'update']);
                $r->post('/broadcasts/{id}/send', [BroadcastController::class, 'send']);
                $r->post('/broadcasts/{id}/test', [BroadcastController::class, 'test']);
                $r->post('/broadcasts/{id}/cancel', [BroadcastController::class, 'cancel']);
                $r->get('/settings', [BroadcastController::class, 'settings']);
                $r->put('/settings', [BroadcastController::class, 'updateSettings']);
                $r->patch('/users/{id}/staff', [BroadcastController::class, 'staff']);

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
