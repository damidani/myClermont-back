<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cache\Cache;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    /**
     * GET /v1/health — public, sans signature. Spec v1 : { status, db, redis, version } pour la surveillance de disponibilité,
     * plus server_time pour que l'app synchronise son horloge. « redis » désigne le cache (APCu ou MySQL ici).
     * Répond 503 si la base ne répond pas.
     */
    public function index(Request $request): Response
    {
        $db = 'ok';
        try {
            Database::fetch('SELECT 1 AS ok');
        } catch (\Throwable) {
            $db = 'down';
        }
        $cache = 'ok';
        try {
            Cache::store()->set('health:ping', 1, 10);
            $cache = Cache::store()->get('health:ping') === 1 || $db === 'down' ? 'ok' : 'degraded';
        } catch (\Throwable) {
            $cache = 'down';
        }

        return Response::success([
            'status'      => $db === 'ok' ? 'ok' : 'degraded',
            'db'          => $db,
            'redis'       => $cache,
            'version'     => (string) (getenv('APP_VERSION') ?: '1.0.0'),
            'server_time' => time(),
        ], $db === 'ok' ? 200 : 503)->withHeader('Cache-Control', 'no-store');
    }
}
