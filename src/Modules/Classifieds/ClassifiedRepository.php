<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Cache\Cache;
use App\Content\ContentSources;
use App\Content\Media\MediaStore;
use App\Core\Database;

final class ClassifiedRepository
{
    private const COLUMNS = 'a.id, a.uid, a.user_id, a.external_url, a.title, a.description, a.price_cents, a.is_free, a.item_condition,
                             a.location_label, a.neighborhood_id, a.latitude, a.longitude, a.badge_label, a.notice, a.status,
                             a.published_at, a.expires_at,
                             c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                             u.uid AS seller_uid, u.username AS seller_username, u.created_at AS seller_since,
                             (SELECT COALESCE(m.path, cm.url) FROM classified_media cm LEFT JOIN media m ON m.id = cm.media_id
                              WHERE cm.classified_id = a.id ORDER BY cm.position LIMIT 1) AS cover, '
                          . ContentSources::SELECT;

    private const FROM = 'classifieds a
                          JOIN classified_categories c ON c.id = a.category_id
                          JOIN content_sources s ON s.id = a.source_id
                          LEFT JOIN users u ON u.id = a.user_id';

    public const SORTS = [
        'recent'     => ['a.published_at', 'DESC'],
        'price_asc'  => ['a.price_cents', 'ASC'],
        'price_desc' => ['a.price_cents', 'DESC'],
    ];

    /** Annonce visible dans la liste : en ligne (ou réservée) et non expirée. */
    private const VISIBLE = "a.status IN ('active', 'reserved') AND a.expires_at > :now_v";

    /**
     * @param array{category?: ?string, q?: ?string, free?: bool, max_price_cents?: ?int, neighborhood_id?: ?int,
     *              location?: ?string, favorites_of?: ?int, exclude_blocked_by?: ?int} $f
     */
    public function search(array $f, string $sort, int $limit, ?array $after): array
    {
        [$where, $params] = $this->where($f);
        [$column, $dir] = self::SORTS[$sort];
        if ($after !== null) {
            $op = $dir === 'DESC' ? '<' : '>';
            $where .= " AND ({$column} {$op} :av OR ({$column} = :av2 AND a.id {$op} :aid))";
            $params += ['av' => $after[0], 'av2' => $after[0], 'aid' => $after[1]];
        }

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} ORDER BY {$column} {$dir}, a.id {$dir} LIMIT :limit",
            $params + ['limit' => $limit],
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);

        return (int) Database::fetch('SELECT COUNT(*) AS n FROM ' . self::FROM . " WHERE {$where}", $params)['n'];
    }

    /** « Tout 12 · Maison & déco 2 · … » et « 1 dons » (cache court) */
    public function counts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('classifieds:counts'))) {
            return $cached;
        }
        $now = gmdate('Y-m-d H:i:s');
        $categories = array_map(static fn (array $r) => [
            'slug' => $r['slug'], 'name' => $r['name'], 'icon' => $r['icon'], 'color' => $r['color'], 'count' => (int) $r['n'],
        ], Database::fetchAll(
            "SELECT c.slug, c.name, c.icon, c.color, COUNT(a.id) AS n FROM classified_categories c
             LEFT JOIN classifieds a ON a.category_id = c.id AND a.status IN ('active', 'reserved') AND a.expires_at > :now
             GROUP BY c.id ORDER BY c.sort_order",
            ['now' => $now],
        ));
        $free = (int) Database::fetch("SELECT COUNT(*) AS n FROM classifieds a WHERE " . self::VISIBLE . ' AND a.is_free = 1', ['now_v' => $now])['n'];
        $counts = ['categories' => $categories, 'all' => array_sum(array_column($categories, 'count')), 'free' => $free];

        if (Cache::isInMemory()) {
            Cache::store()->set('classifieds:counts', $counts, 30);
        }

        return $counts;
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('classifieds:counts');
        }
    }

    /** Annonce consultable (y compris vendue / expirée, pour les favoris et les conversations). */
    public function find(string $uid): ?array
    {
        return Database::fetch(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE a.uid = :u AND a.status NOT IN ('deleted', 'hidden')",
            ['u' => strtolower($uid)],
        );
    }

    public function findManyByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$in, $params] = self::in($uids);

        return array_column(
            Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE a.uid IN ({$in}) AND a.status NOT IN ('deleted', 'hidden')", $params),
            null,
            'uid',
        );
    }

    public function byUser(int $userId): array
    {
        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE a.user_id = :u AND a.status NOT IN ('deleted') ORDER BY a.published_at DESC LIMIT 200",
            ['u' => $userId],
        );
    }

    public function photos(int $classifiedId): array
    {
        return array_map(
            static fn (array $r) => $r['path'] !== null ? MediaStore::url($r['path']) : $r['url'],
            Database::fetchAll(
                'SELECT m.path, cm.url FROM classified_media cm LEFT JOIN media m ON m.id = cm.media_id WHERE cm.classified_id = :c ORDER BY cm.position',
                ['c' => $classifiedId],
            ),
        );
    }

    public function activeCountForUser(int $userId): int
    {
        return (int) Database::fetch("SELECT COUNT(*) AS n FROM classifieds WHERE user_id = :u AND status IN ('active', 'reserved')", ['u' => $userId])['n'];
    }

    public function sellerStats(int $userId): array
    {
        $row = Database::fetch(
            "SELECT SUM(status IN ('active', 'reserved')) AS active, SUM(status = 'sold') AS sold FROM classifieds WHERE user_id = :u",
            ['u' => $userId],
        );

        return ['active' => (int) ($row['active'] ?? 0), 'sold' => (int) ($row['sold'] ?? 0)];
    }

    public function create(int $userId, array $data, array $mediaIds): string
    {
        $uid = \App\Security\Crypto::uuid4();
        $now = gmdate('Y-m-d H:i:s');

        Database::transaction(function () use ($uid, $userId, $data, $mediaIds, $now): void {
            $id = Database::insert(
                'INSERT INTO classifieds (uid, user_id, category_id, title, description, price_cents, is_free, item_condition, location_label,
                                          neighborhood_id, status, published_at, expires_at, created_at, updated_at)
                 VALUES (:uid, :u, :cat, :title, :descr, :price, :free, :cond, :loc, :hood, \'active\', :p, :exp, :c, :up)',
                [
                    'uid' => $uid, 'u' => $userId, 'cat' => $data['category_id'], 'title' => $data['title'], 'descr' => $data['description'],
                    'price' => $data['price_cents'], 'free' => $data['is_free'] ? 1 : 0, 'cond' => $data['condition'], 'loc' => $data['location_label'],
                    'hood' => $data['neighborhood_id'], 'p' => $now, 'exp' => $data['expires_at'], 'c' => $now, 'up' => $now,
                ],
            );
            $this->replacePhotos($id, $mediaIds);
        });
        $this->forgetCounts();

        return $uid;
    }

    public function update(int $id, array $fields, ?array $mediaIds): void
    {
        Database::transaction(function () use ($id, $fields, $mediaIds): void {
            if ($fields !== []) {
                $sets = implode(', ', array_map(static fn (string $f) => "{$f} = :{$f}", array_keys($fields)));
                Database::execute("UPDATE classifieds SET {$sets}, updated_at = :updated_at WHERE id = :id", $fields + ['updated_at' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
            }
            if ($mediaIds !== null) {
                $this->replacePhotos($id, $mediaIds, keepExisting: true);
            }
        });
        $this->forgetCounts();
    }

    /**
     * @param list<int> $mediaIds ordre final des photos (médias déjà rattachés à l'annonce + nouveaux)
     */
    private function replacePhotos(int $classifiedId, array $mediaIds, bool $keepExisting = false): void
    {
        $current = array_map('intval', array_column(Database::fetchAll('SELECT media_id FROM classified_media WHERE classified_id = :c AND media_id IS NOT NULL', ['c' => $classifiedId]), 'media_id'));
        Database::execute('DELETE FROM classified_media WHERE classified_id = :c', ['c' => $classifiedId]);
        foreach (array_values($mediaIds) as $pos => $mediaId) {
            Database::execute('INSERT INTO classified_media (classified_id, media_id, position) VALUES (:c, :m, :p)', ['c' => $classifiedId, 'm' => $mediaId, 'p' => $pos]);
        }
        $media = new MediaStore();
        $media->attach(array_diff($mediaIds, $current));
        if ($keepExisting) {
            $media->deleteByIds(array_diff($current, $mediaIds)); // photos retirées de l'annonce
        }
    }

    public function report(int $classifiedId, int $userId, string $reason, ?string $details, int $threshold): bool
    {
        return Database::transaction(function () use ($classifiedId, $userId, $reason, $details, $threshold): bool {
            $added = Database::execute(
                'INSERT IGNORE INTO classified_reports (classified_id, user_id, reason, details, created_at) VALUES (:c, :u, :r, :d, :now)',
                ['c' => $classifiedId, 'u' => $userId, 'r' => $reason, 'd' => $details, 'now' => gmdate('Y-m-d H:i:s')],
            ) > 0;
            if (!$added) {
                return false;
            }
            Database::execute('UPDATE classifieds SET reports_count = reports_count + 1 WHERE id = :c', ['c' => $classifiedId]);
            $hidden = Database::execute(
                "UPDATE classifieds SET status = 'hidden' WHERE id = :c AND status IN ('active', 'reserved') AND reports_count >= :n",
                ['c' => $classifiedId, 'n' => $threshold],
            ) > 0;
            if ($hidden) {
                $this->forgetCounts();
            }

            return $hidden;
        });
    }

    public function expireOld(): int
    {
        $n = Database::execute("UPDATE classifieds SET status = 'expired' WHERE status IN ('active', 'reserved') AND expires_at <= :now", ['now' => gmdate('Y-m-d H:i:s')]);
        $this->forgetCounts();

        return $n;
    }

    /** @return array{0: string, 1: array} */
    private function where(array $f): array
    {
        $where = self::VISIBLE;
        $params = ['now_v' => gmdate('Y-m-d H:i:s')];

        if (!empty($f['category'])) {
            $where .= ' AND c.slug = :cat';
            $params['cat'] = $f['category'];
        }
        if (!empty($f['free'])) {
            $where .= ' AND a.is_free = 1';
        }
        if (!empty($f['max_price_cents'])) {
            $where .= ' AND a.price_cents <= :maxp';
            $params['maxp'] = $f['max_price_cents'];
        }
        if (!empty($f['neighborhood_id'])) {
            $where .= ' AND a.neighborhood_id = :hood';
            $params['hood'] = $f['neighborhood_id'];
        }
        if (!empty($f['favorites_of'])) {
            $where .= " AND a.uid IN (SELECT target_id FROM favorites WHERE user_id = :fav AND type = 'classified')";
            $params['fav'] = $f['favorites_of'];
        }
        if (!empty($f['exclude_blocked_by'])) {
            // Les annonces des personnes que j'ai bloquées (ou qui m'ont bloqué) n'apparaissent pas
            $where .= ' AND (a.user_id IS NULL OR a.user_id NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = :blk1
                                                               UNION SELECT blocker_id FROM user_blocks WHERE blocked_id = :blk2))';
            $params += ['blk1' => $f['exclude_blocked_by'], 'blk2' => $f['exclude_blocked_by']];
        }
        if (!empty($f['q'])) {
            foreach (array_slice(preg_split('/\s+/', trim($f['q'])) ?: [], 0, 5) as $i => $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';
                $where .= " AND (a.title LIKE :q{$i}a OR a.description LIKE :q{$i}b OR c.name LIKE :q{$i}c OR a.location_label LIKE :q{$i}d)";
                $params += ["q{$i}a" => $like, "q{$i}b" => $like, "q{$i}c" => $like, "q{$i}d" => $like];
            }
        }

        return [$where, $params];
    }

    /** @return array{0: string, 1: array} */
    private static function in(array $values): array
    {
        $in = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $in[] = ":v{$i}";
            $params["v{$i}"] = $v;
        }

        return [implode(', ', $in), $params];
    }
}
