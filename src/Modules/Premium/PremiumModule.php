<?php

declare(strict_types=1);

namespace App\Modules\Premium;

use App\Core\Config;
use App\Core\Database;
use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Statut premium. Avantages actuels : multiplicateur de points (config gamification.premium_multiplier)
 * et accès aux routes protégées par le middleware "premium".
 *
 * À venir : validation des achats App Store / Google Play (POST /users/me/premium/purchases).
 * En attendant, le premium s'attribue en CLI : php bin/console premium:grant <email> <jours>
 *
 * Événement émis : premium.granted {user_id, days, source, until}
 */
final class PremiumModule implements Module
{
    public function routes(Router $r): void
    {
        $r->get('/users/me/premium', [self::class, 'status'], ['auth']);
    }

    /** GET /v1/users/me/premium */
    public function status(Request $request): Response
    {
        $row = Database::fetch(
            'SELECT premium_until, premium_source FROM users WHERE id = :u',
            ['u' => $request->attributes['user_id']],
        ) ?? ['premium_until' => null, 'premium_source' => null];

        $active = PremiumService::isActive($row);

        return Response::success([
            'is_premium' => $active,
            'until'      => $active ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row['premium_until'] . ' UTC')) : null,
            'source'     => $active ? $row['premium_source'] : null,
            'perks'      => [
                'points_multiplier' => (float) Config::get('gamification.premium_multiplier', 1),
            ],
        ]);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
