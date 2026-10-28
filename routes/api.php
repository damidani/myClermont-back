<?php

declare(strict_types=1);

/*
 * Routes du noyau + montage des modules (config/modules.php).
 *
 * Middlewares disponibles (alias définis dans config/app.php) :
 *   app.signature       requête signée avec la clé application (enregistrement d'appareil uniquement)
 *   device              requête signée par un appareil enregistré (TOUTES les autres routes)
 *   auth                utilisateur connecté (Authorization: Bearer), à placer après "device"
 *   premium             utilisateur connecté ET premium actif, à placer après "auth"
 *   throttle:<profil>[,device|ip]   limitation de débit (profils dans config/security.php)
 *
 * Les routes de chaque module sont dans src/Modules/<Module>/<Module>Module.php.
 */

use App\Core\Module;
use App\Core\Router;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HealthController;

/** @param list<Module> $modules */
return static function (Router $router, array $modules): void {
    $router->group(['prefix' => '/v1'], static function (Router $r) use ($modules): void {

        // --- Public --------------------------------------------------------
        $r->get('/health', [HealthController::class, 'index']);

        // --- Enregistrement d'appareil (clé application) -----------------
        $r->post('/devices/register', [DeviceController::class, 'register'], ['throttle:register,ip', 'app.signature']);

        // --- Toutes les routes suivantes exigent un appareil signé ---------
        $r->group(['middleware' => ['device', 'throttle:device']], static function (Router $r) use ($modules): void {
            $r->get('/devices/me', [DeviceController::class, 'show']);
            $r->patch('/devices/me', [DeviceController::class, 'update']);

            foreach ($modules as $module) {
                $module->routes($r);
            }
        });
    });
};
