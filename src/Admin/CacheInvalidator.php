<?php

declare(strict_types=1);

namespace App\Admin;

use App\Cache\Cache;
use App\Core\Database;

/**
 * Après une modification dans le back-office, l'app doit voir le changement tout de suite :
 * on efface les caches des listes / compteurs / repères concernés.
 */
final class CacheInvalidator
{
    /** Table modifiée => clés de cache à effacer */
    private const KEYS = [
        'places' => ['places:pins'], 'place_categories' => ['places:pins'], 'place_reviews' => ['places:pins'],
        'events' => ['events:categories'], 'event_categories' => ['events:categories'],
        'housing_listings' => ['housing:counts'],
        'job_offers' => ['jobs:counts'], 'job_categories' => ['jobs:counts'],
        'service_providers' => ['services:categories'], 'service_categories' => ['services:categories'],
        'classifieds' => ['classifieds:counts'], 'classified_categories' => ['classifieds:counts'],
        'community_posts' => ['community:counts'],
        'mobility_destinations' => ['mobility:destinations'],
        'neighborhoods' => ['neighborhoods'],
    ];

    private const EXPLORE = ['explore_sections', 'explore_items', 'explore_highlights', 'explore_stories', 'explore_story_slides', 'offers', 'events', 'places'];

    public static function afterWrite(string $table, ?array $before, ?array $after): void
    {
        $store = Cache::store();
        foreach (self::KEYS[$table] ?? [] as $key) {
            $store->delete($key);
        }
        if (in_array($table, self::EXPLORE, true)) {
            self::forgetExplore();
        }

        $row = $after ?? $before ?? [];
        if ($table === 'devices' && isset($row['uid'])) {
            $store->delete('device:' . $row['uid']);
        }
        // Compte désactivé / supprimé : ses sessions de l'app tombent (le jeton d'accès expire sous 15 min)
        if ($table === 'users' && isset($row['id']) && ($after === null || ($after['status'] ?? 'active') !== 'active')) {
            Database::execute('UPDATE refresh_tokens SET revoked_at = :d WHERE user_id = :u AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'u' => $row['id']]);
        }
        if ($table === 'admin_users' && isset($row['id']) && ($after === null || ($after['status'] ?? 'active') !== 'active')) {
            Database::execute('DELETE FROM admin_sessions WHERE admin_id = :a', ['a' => $row['id']]);
        }
    }

    /** Bouton « Vider les caches » du tableau de bord. */
    public static function all(): void
    {
        foreach (array_unique(array_merge(...array_values(self::KEYS))) as $key) {
            Cache::store()->delete($key);
        }
        self::forgetExplore();
        Schema::forget();
    }

    private static function forgetExplore(): void
    {
        Cache::store()->delete('explore:home:all');
        foreach (Database::fetchAll('SELECT slug FROM explore_sections') as $s) {
            Cache::store()->delete('explore:home:' . $s['slug']);
        }
    }
}
