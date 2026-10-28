<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Limitation de débit par fenêtre fixe.
 *
 * Usage dans les routes : "throttle:<profil>[,<portée>]"
 *   - profil : clé de security.rate_limits (device, register, login)
 *   - portée : "device" (X-Device-Id authentifié) ou "ip"
 *
 * Exemple : "throttle:login,ip"
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly string $profile = 'device',
        private readonly string $scope = 'device',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        [$max, $window] = Config::get('security.rate_limits.' . $this->profile)
            ?? throw new \LogicException("Profil de rate-limit inconnu : {$this->profile}");

        $identity = $this->scope === 'device'
            ? ($request->attributes['device']['uid'] ?? $request->ip)
            : $request->ip;

        $now = time();
        $bucket = intdiv($now, $window);
        $resetIn = ($bucket + 1) * $window - $now;

        $hits = Cache::store()->increment("rl:{$this->profile}:{$identity}:{$bucket}", $resetIn + 1);

        if ($hits > $max) {
            throw new HttpException(429, 'too_many_requests', 'Trop de requêtes, réessayez plus tard.', null, [
                'Retry-After'           => (string) $resetIn,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) ($max - $hits));
    }
}
