<?php

declare(strict_types=1);

namespace App\Modules\Mobility;

use App\Core\Module;
use App\Core\Router;

/**
 * Mobilité : destinations rapides, itinéraires en transports en commun (Navitia),
 * autocomplétion des lieux, info trafic. Voiture / à pied : calcul natif dans l'app.
 */
final class MobilityModule implements Module
{
    public function routes(Router $r): void
    {
        $r->get('/mobility/destinations', [MobilityController::class, 'destinations']);
        $r->get('/mobility/traffic', [MobilityController::class, 'traffic']);
        // Appels à un service externe (quota) : limités par appareil
        $r->get('/mobility/journeys', [MobilityController::class, 'journeys'], ['throttle:mobility,device']);
        $r->get('/mobility/places', [MobilityController::class, 'places'], ['throttle:mobility,device']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
