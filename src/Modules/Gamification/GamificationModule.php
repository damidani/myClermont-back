<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

use App\Core\Module;
use App\Core\Router;
use App\Modules\Gamification\Controllers\GamificationController;

/**
 * Points et badges attribués automatiquement. Règles : config/gamification.php.
 */
final class GamificationModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->get('/badges', [GamificationController::class, 'badges']);
            $r->get('/users/me/points', [GamificationController::class, 'points']);
        });
    }

    public function listeners(): array
    {
        return [
            'account.registered'      => [[GamificationListeners::class, 'onRegistered']],
            'account.logged_in'       => [[GamificationListeners::class, 'onLoggedIn']],
            'account.profile_updated' => [[GamificationListeners::class, 'onProfileUpdated']],
            'favorite.added'          => [[GamificationListeners::class, 'onFavoriteAdded']],
            'event.participation_added' => [[GamificationListeners::class, 'onEventParticipation']],
            'community.post_created'    => [[GamificationListeners::class, 'onCommunityPost']],
            'community.poll_voted'      => [[GamificationListeners::class, 'onPollVoted']],
            'classifieds.published'     => [[GamificationListeners::class, 'onClassifiedPublished']],
            'classifieds.sold'          => [[GamificationListeners::class, 'onClassifiedSold']],
        ];
    }

    public function boot(): void
    {
    }
}
