<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Cache\Cache;
use App\Content\ContentSources;
use App\Core\Config;
use App\Core\Database;

/**
 * Lecture des lieux.
 *
 * Toute la carte repose sur un « jeu de repères » minimal (id, position, catégorie, drapeaux, nom)
 * chargé une fois depuis MySQL puis gardé en mémoire partagée : servir la carte ne touche plus la base.
 * Sa version (empreinte) sert d'ETag : si rien n'a changé, l'app reçoit un 304 sans contenu.
 */
final class PlaceRepository
{
    public const FLAG_PARTNER = 1;
    public const FLAG_MUST_SEE = 2;

    private const DETAIL_COLUMNS = 'p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon, c.color AS category_color, '
                                 . ContentSources::SELECT;

    private const FROM = 'places p JOIN place_categories c ON c.id = p.category_id JOIN content_sources s ON s.id = p.source_id';

    /**
     * @return array{version: string, categories: list<array>, pins: list<array{0: string, 1: float, 2: float, 3: int, 4: int, 5: string}>}
     *         pins : [uid, lat, lng, index de catégorie, drapeaux, nom]
     */
    public function pinSet(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('places:pins'))) {
            return $cached;
        }

        $categories = Database::fetchAll('SELECT id, slug, name, icon, color FROM place_categories ORDER BY sort_order');
        $index = array_flip(array_map('intval', array_column($categories, 'id')));

        $pins = [];
        foreach (Database::fetchAll("SELECT uid, latitude, longitude, category_id, is_partner, is_must_see, name, updated_at FROM places WHERE status = 'published'") as $p) {
            $pins[] = [
                $p['uid'],
                round((float) $p['latitude'], 5),   // 5 décimales ≈ 1 m : suffisant et plus léger
                round((float) $p['longitude'], 5),
                $index[(int) $p['category_id']],
                ($p['is_partner'] ? self::FLAG_PARTNER : 0) | ($p['is_must_see'] ? self::FLAG_MUST_SEE : 0),
                $p['name'],
            ];
        }

        $version = substr(sha1(json_encode([$pins, array_column($categories, 'slug')])), 0, 16);
        $set = [
            'version'    => $version,
            'categories' => array_map(static fn (array $c) => ['slug' => $c['slug'], 'name' => $c['name'], 'icon' => $c['icon'], 'color' => $c['color']], $categories),
            'pins'       => $pins,
        ];

        if (Cache::isInMemory()) {
            Cache::store()->set('places:pins', $set, (int) Config::get('places.pins_cache_ttl', 3600));
        }

        return $set;
    }

    /** À appeler après toute modification de lieu (import, saisie, avis qui change la note n'en a pas besoin). */
    public function forgetPins(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('places:pins');
        }
    }

    public function find(string $uid): ?array
    {
        return Database::fetch('SELECT ' . self::DETAIL_COLUMNS . ' FROM ' . self::FROM . " WHERE p.uid = :u AND p.status IN ('published', 'archived')", ['u' => strtolower($uid)]);
    }

    /** Cartes complètes pour une liste d'uids (liste « lieux proches », recherche, favoris). */
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

        return array_column(Database::fetchAll(
            'SELECT p.uid, p.name, p.tagline, p.cover_url, p.latitude, p.longitude, p.address, p.city, p.hours_label, p.opening_hours, p.price_label,
                    p.is_partner, p.is_must_see, p.rating_avg, p.rating_count, p.display_rating, p.display_rating_label, p.tags, p.status,
                    c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
             FROM places p JOIN place_categories c ON c.id = p.category_id WHERE p.uid IN (' . implode(', ', $in) . ") AND p.status <> 'draft'",
            $params,
        ), null, 'uid');
    }

    /** Recherche texte (nom, catégorie, mots-clés, adresse, tags). */
    public function searchUids(string $query, int $limit): array
    {
        $where = "p.status = 'published'";
        $params = ['limit' => $limit];
        foreach (array_slice(preg_split('/\s+/', trim($query)) ?: [], 0, 5) as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $where .= " AND (p.name LIKE :q{$i}a OR c.name LIKE :q{$i}b OR p.keywords LIKE :q{$i}c OR p.address LIKE :q{$i}d OR p.tags LIKE :q{$i}e)";
            $params += ["q{$i}a" => $like, "q{$i}b" => $like, "q{$i}c" => $like, "q{$i}d" => $like, "q{$i}e" => $like];
        }

        return array_column(Database::fetchAll(
            "SELECT p.uid FROM places p JOIN place_categories c ON c.id = p.category_id WHERE {$where}
             ORDER BY p.is_must_see DESC, p.is_partner DESC, p.rating_count DESC LIMIT :limit",
            $params,
        ), 'uid');
    }

    public function reviews(int $placeId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT r.uid, r.rating, r.comment, r.created_at, u.username FROM place_reviews r JOIN users u ON u.id = r.user_id
             WHERE r.place_id = :p AND r.status = 'visible' ORDER BY r.id DESC LIMIT :limit",
            ['p' => $placeId, 'limit' => $limit],
        );
    }

    public function myReview(int $placeId, int $userId): ?array
    {
        return Database::fetch('SELECT rating, comment, updated_at FROM place_reviews WHERE place_id = :p AND user_id = :u', ['p' => $placeId, 'u' => $userId]);
    }

    public function saveReview(int $placeId, int $userId, int $rating, ?string $comment): void
    {
        Database::transaction(function () use ($placeId, $userId, $rating, $comment): void {
            $now = gmdate('Y-m-d H:i:s');
            Database::execute(
                'INSERT INTO place_reviews (uid, place_id, user_id, rating, comment, created_at, updated_at) VALUES (:uid, :p, :u, :r, :c, :n1, :n2)
                 ON DUPLICATE KEY UPDATE rating = :r2, comment = :c2, updated_at = :n3',
                ['uid' => \App\Security\Crypto::uuid4(), 'p' => $placeId, 'u' => $userId, 'r' => $rating, 'c' => $comment, 'n1' => $now, 'n2' => $now,
                 'r2' => $rating, 'c2' => $comment, 'n3' => $now],
            );
            Database::execute(
                "UPDATE places p SET
                    rating_count = (SELECT COUNT(*) FROM place_reviews r WHERE r.place_id = p.id AND r.status = 'visible'),
                    rating_avg   = (SELECT ROUND(AVG(rating), 1) FROM place_reviews r WHERE r.place_id = p.id AND r.status = 'visible')
                 WHERE p.id = :p",
                ['p' => $placeId],
            );
        });
    }
}
