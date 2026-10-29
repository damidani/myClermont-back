<?php

declare(strict_types=1);

namespace App\Modules\Moderation;

use App\Core\Module;
use App\Core\Router;

/** Signalements, blocages, filtre automatique des contenus (spec v1). */
final class ModerationModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/reports', [ReportController::class, 'store'], ['throttle:report']);
            $r->post('/me/blocks/{userId}', [ReportController::class, 'block']);
            $r->delete('/me/blocks/{userId}', [ReportController::class, 'unblock']);
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
