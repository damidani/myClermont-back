<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Core\Module;
use App\Core\Router;

/** Clermont IA (spec v1) : POST /assistant/answer — 10 questions par minute au plus, puis quotas. */
final class AssistantModule implements Module
{
    public function routes(Router $r): void
    {
        $r->post('/assistant/answer', [AssistantController::class, 'answer'], ['auth:optional', 'throttle:ai']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
