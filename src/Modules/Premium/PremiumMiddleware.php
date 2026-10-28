<?php

declare(strict_types=1);

namespace App\Modules\Premium;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Http\Middleware\Middleware;

/**
 * Réserve une route aux membres premium. À placer après "auth" :
 *   $r->get('/places/secret-spots', [...], ['auth', 'premium']);
 */
final class PremiumMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!PremiumService::isActiveById($request->attributes['user_id'] ?? 0)) {
            throw new HttpException(402, 'premium_required', 'Cette fonctionnalité est réservée aux membres premium.');
        }

        return $next($request);
    }
}
