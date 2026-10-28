<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    /**
     * GET /v1/health — public, sans signature, sans base de données.
     * Sert au monitoring et permet à l'app de synchroniser son horloge (server_time).
     */
    public function index(Request $request): Response
    {
        return Response::success([
            'status'      => 'ok',
            'server_time' => time(),
        ]);
    }
}
