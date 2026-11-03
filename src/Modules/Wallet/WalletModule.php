<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Module;
use App\Core\Router;

/** Cartes Apple Wallet (c06e670d) : routes de l'app, sous signature d'appareil. */
final class WalletModule implements Module
{
    public function routes(Router $r): void
    {
        $r->get('/wallet/status', [WalletController::class, 'status']);
        $r->get('/events/{id}/wallet-pass', [WalletController::class, 'event']);
        $r->get('/deals/{id}/wallet-pass', [WalletController::class, 'deal'], ['auth']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
