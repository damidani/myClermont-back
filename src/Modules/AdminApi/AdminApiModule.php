<?php

declare(strict_types=1);

namespace App\Modules\AdminApi;

use App\Core\Module;
use App\Core\Router;

/** Routes d'administration JSON (spec v1) : /admin/…, rôles moderator et admin. */
final class AdminApiModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth', 'role:moderator']], static function (Router $r): void {
            $r->get('/admin/reports', [AdminApiController::class, 'reports']);
            $r->post('/admin/reports/{id}/resolve', [AdminApiController::class, 'resolve']);
            $r->post('/admin/users/{id}/ban', [AdminApiController::class, 'ban']);

            $r->get('/admin/{resource}', [AdminApiController::class, 'index']);
            $r->post('/admin/{resource}', [AdminApiController::class, 'store']);
            $r->get('/admin/{resource}/{id}', [AdminApiController::class, 'show']);
            $r->patch('/admin/{resource}/{id}', [AdminApiController::class, 'update']);
            $r->delete('/admin/{resource}/{id}', [AdminApiController::class, 'destroy']);
        });
        $r->group(['middleware' => ['auth', 'role:admin']], static function (Router $r): void {
            $r->patch('/admin/users/{id}/role', [AdminApiController::class, 'role']);
            $r->post('/admin/push', [AdminApiController::class, 'push']);
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
