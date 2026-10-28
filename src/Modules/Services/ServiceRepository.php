<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Cache\Cache;
use App\Content\ContentSources;
use App\Core\Database;

final class ServiceRepository
{
    private const COLUMNS = 'sp.id, sp.uid, sp.external_url, sp.name, sp.summary, sp.description, sp.services, sp.logo_url, sp.cover_url,
                             sp.hours_label, sp.price_label, sp.city, sp.neighborhood_id, sp.latitude, sp.longitude, sp.service_radius_km,
                             sp.phone, sp.website_url, sp.rating_avg, sp.rating_count, sp.display_rating, sp.display_rating_label,
                             sp.badge_label, sp.notice, sp.status,
                             c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon,
                             n.name AS neighborhood_name, '
                          . ContentSources::SELECT;

    private const FROM = 'service_providers sp
                          JOIN service_categories c ON c.id = sp.category_id
                          JOIN content_sources s ON s.id = sp.source_id
                          LEFT JOIN neighborhoods n ON n.id = sp.neighborhood_id';

    public const SORTS = ['relevance', 'rating'];

    /**
     * @param array{category?: ?string, q?: ?string, favorites_of?: ?int, neighborhood_id?: ?int} $f
     */
    public function search(array $f, string $sort, int $limit, int $offset): array
    {
        [$where, $params, $relevance] = $this->where($f);
        $order = match ($sort) {
            'rating' => 'COALESCE(sp.rating_avg, sp.display_rating, 0) DESC, sp.rating_count DESC, sp.name',
            default  => ($relevance ? "{$relevance} DESC, " : '') . 'sp.rating_count DESC, COALESCE(sp.rating_avg, sp.display_rating, 0) DESC, sp.name',
        };

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset],
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);

        return (int) Database::fetch('SELECT COUNT(*) AS n FROM ' . self::FROM . " WHERE {$where}", $params)['n'];
    }

    /** « Explorer les catégories » : toutes les catégories avec leur nombre de professionnels. */
    public function categoriesWithCounts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('services:categories'))) {
            return $cached;
        }
        $rows = array_map(static fn (array $r) => ['slug' => $r['slug'], 'name' => $r['name'], 'icon' => $r['icon'], 'count' => (int) $r['n']], Database::fetchAll(
            "SELECT c.slug, c.name, c.icon, COUNT(sp.id) AS n FROM service_categories c
             LEFT JOIN service_providers sp ON sp.category_id = c.id AND sp.status = 'published'
             GROUP BY c.id ORDER BY c.sort_order",
        ));
        if (Cache::isInMemory()) {
            Cache::store()->set('services:categories', $rows, 60);
        }

        return $rows;
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('services:categories');
        }
    }

    public function find(string $uid, bool $withContact = false): ?array
    {
        return Database::fetch(
            'SELECT ' . self::COLUMNS . ($withContact ? ', sp.contact_email' : '') . ' FROM ' . self::FROM
            . " WHERE sp.uid = :u AND sp.status IN ('published', 'archived')",
            ['u' => strtolower($uid)],
        );
    }

    public function findManyByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach (array_values($uids) as $i => $uid) {
            $in[] = ":u{$i}";
            $params["u{$i}"] = $uid;
        }

        return array_column(
            Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE sp.uid IN (' . implode(', ', $in) . ") AND sp.status <> 'draft'", $params),
            null,
            'uid',
        );
    }

    public function reviews(int $providerId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT r.uid, r.rating, r.comment, r.created_at, u.username FROM service_reviews r JOIN users u ON u.id = r.user_id
             WHERE r.provider_id = :p AND r.status = 'visible' ORDER BY r.id DESC LIMIT :limit",
            ['p' => $providerId, 'limit' => $limit],
        );
    }

    /** Crée ou remplace l'avis de l'utilisateur et recalcule la moyenne. */
    public function saveReview(int $providerId, int $userId, int $rating, ?string $comment): void
    {
        Database::transaction(function () use ($providerId, $userId, $rating, $comment): void {
            $now = gmdate('Y-m-d H:i:s');
            Database::execute(
                'INSERT INTO service_reviews (uid, provider_id, user_id, rating, comment, created_at, updated_at)
                 VALUES (:uid, :p, :u, :r, :c, :now, :now2)
                 ON DUPLICATE KEY UPDATE rating = :r2, comment = :c2, updated_at = :now3',
                ['uid' => \App\Security\Crypto::uuid4(), 'p' => $providerId, 'u' => $userId, 'r' => $rating, 'c' => $comment,
                 'now' => $now, 'now2' => $now, 'r2' => $rating, 'c2' => $comment, 'now3' => $now],
            );
            Database::execute(
                "UPDATE service_providers sp SET
                    rating_count = (SELECT COUNT(*) FROM service_reviews r WHERE r.provider_id = sp.id AND r.status = 'visible'),
                    rating_avg   = (SELECT ROUND(AVG(rating), 1) FROM service_reviews r WHERE r.provider_id = sp.id AND r.status = 'visible')
                 WHERE sp.id = :p",
                ['p' => $providerId],
            );
        });
    }

    /** @return array{0: string, 1: array, 2: ?string} where, paramètres, expression de pertinence */
    private function where(array $f): array
    {
        $where = "sp.status = 'published'";
        $params = [];
        $relevance = null;

        if (!empty($f['category'])) {
            $where .= ' AND c.slug = :cat';
            $params['cat'] = $f['category'];
        }
        if (!empty($f['neighborhood_id'])) {
            $where .= ' AND sp.neighborhood_id = :hood';
            $params['hood'] = $f['neighborhood_id'];
        }
        if (!empty($f['favorites_of'])) {
            $where .= " AND sp.uid IN (SELECT target_id FROM favorites WHERE user_id = :fav AND type = 'service')";
            $params['fav'] = $f['favorites_of'];
        }
        if (!empty($f['q'])) {
            // « Service, métier, quartier… »
            $words = array_slice(preg_split('/\s+/', trim($f['q'])) ?: [], 0, 5);
            foreach ($words as $i => $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';
                $where .= " AND (sp.name LIKE :q{$i}a OR sp.keywords LIKE :q{$i}b OR sp.summary LIKE :q{$i}c OR c.name LIKE :q{$i}d
                                 OR sp.city LIKE :q{$i}e OR n.name LIKE :q{$i}f OR sp.services LIKE :q{$i}g)";
                $params += ["q{$i}a" => $like, "q{$i}b" => $like, "q{$i}c" => $like, "q{$i}d" => $like, "q{$i}e" => $like, "q{$i}f" => $like, "q{$i}g" => $like];
            }
            // Pertinence : correspondance dans le nom > mots-clés métier > reste
            $relevance = '(CASE WHEN sp.name LIKE :rel_name THEN 2 WHEN sp.keywords LIKE :rel_kw THEN 1 ELSE 0 END)';
            $first = '%' . addcslashes($words[0] ?? '', '%_\\') . '%';
            $params += ['rel_name' => $first, 'rel_kw' => $first];
        }

        return [$where, $params, $relevance];
    }
}
