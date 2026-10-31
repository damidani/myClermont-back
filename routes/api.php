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
    // Compatibilité avec l'app déjà livrée (build 16) : routes historiques du site, publiques et non signées
    $router->group(['middleware' => ['throttle:public,ip']], static function (Router $r): void {
        $r->get('/evenements.json', [App\Modules\Events\SpecEventController::class, 'legacyFeed']);
        $r->get('/photos/manifest.json', [App\Modules\Places\MapController::class, 'manifest']);
        $r->get('/api/trafic', [App\Modules\Mobility\TrafficController::class, 'legacy']);
    });

    // L'API répond sous /v1 et sous /api/v1 (adresse de la spec : https://myclermont.app/api/v1)
    foreach (['/v1', '/api/v1'] as $prefix) {
    $router->group(['prefix' => $prefix], static function (Router $r) use ($modules): void {

        // --- Public --------------------------------------------------------
        $r->get('/health', [HealthController::class, 'index']);

        // --- Enregistrement d'appareil (clé application) -----------------
        $r->post('/devices/register', [DeviceController::class, 'register'], ['throttle:register,ip', 'app.signature']);

        // --- Notifications serveur de l'App Store (appelées par Apple : vérifiées par leur signature JWS) ---
        $r->post('/webhooks/apple/app-store', [App\Modules\Subscriptions\SubscriptionsModule::class, 'webhook'], ['throttle:public,ip']);

        // --- Canal entre assistants (Claude de l'app ↔ Claude du back) : jeton X-Agent-Token, hors signature d'appareil ---
        $r->group(['middleware' => ['throttle:public,ip']], static function (Router $r): void {
            $r->get('/agents/status', [App\Modules\Agents\AgentsModule::class, 'status']);
            $r->get('/agents/bundle', [App\Modules\Agents\AgentsModule::class, 'bundle']);
            $r->post('/agents/messages', [App\Modules\Agents\AgentsModule::class, 'create']);
            $r->get('/agents/messages', [App\Modules\Agents\AgentsModule::class, 'index']);
            $r->get('/agents/messages/{id}', [App\Modules\Agents\AgentsModule::class, 'show']);
            $r->patch('/agents/messages/{id}', [App\Modules\Agents\AgentsModule::class, 'update']);
        });

        // --- Envoi direct d'un fichier sur l'URL signée (jeton dans l'URL, valable 10 min) ---
        $r->put('/uploads/{id}/blob', [App\Content\Uploads\UploadController::class, 'receive'], ['throttle:upload,ip']);

        // --- Toutes les routes suivantes exigent un appareil signé ---------
        $r->group(['middleware' => ['device', 'throttle:device']], static function (Router $r) use ($modules): void {
            $r->get('/meta', [App\Http\Controllers\MetaController::class, 'index']);
            $r->group(['middleware' => ['auth']], static function (Router $r): void {
                $r->post('/uploads', [App\Content\Uploads\UploadController::class, 'create'], ['throttle:upload']);
                $r->post('/uploads/{id}/confirm', [App\Content\Uploads\UploadController::class, 'confirm']);
            });
            $r->get('/devices/me', [DeviceController::class, 'show']);
            $r->patch('/devices/me', [DeviceController::class, 'update']);

            foreach ($modules as $module) {
                $module->routes($r);
            }
        });
    });
    }
};
