<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Cache\Cache;
use App\Content\ContentSources;
use App\Core\Config;
use App\Core\Database;

final class HousingRepository
{
    private const COLUMNS = 'h.id, h.uid, h.external_url, h.transaction, h.property_type, h.title, h.description, h.price_cents,
                             h.charges_cents, h.charges_included, h.surface_m2, h.rooms, h.bedrooms, h.furnished, h.floor,
                             h.energy_class, h.available_from, h.features, h.district_label, h.neighborhood_id, h.city,
                             h.postal_code, h.latitude, h.longitude, h.image_caption, h.notice, h.status, h.published_at,
                             n.name AS neighborhood_name,
                             (SELECT url FROM housing_images i WHERE i.listing_id = h.id ORDER BY position LIMIT 1) AS cover_url,
                             (SELECT COUNT(*) FROM housing_images i WHERE i.listing_id = h.id) AS image_count, '
                          . ContentSources::SELECT;

    private const FROM = 'housing_listings h
                          JOIN content_sources s ON s.id = h.source_id
                          LEFT JOIN neighborhoods n ON n.id = h.neighborhood_id';

    /** Tris disponibles : colonne de tri + sens (la pagination par curseur s'appuie dessus). */
    public const SORTS = [
        'recent'     => ['h.published_at', 'DESC'],
        'price_asc'  => ['h.price_cents', 'ASC'],
        'price_desc' => ['h.price_cents', 'DESC'],
    ];

    /**
     * @param array{transaction?: ?string, furnished?: bool, max_price_cents?: ?int, min_rooms?: ?int,
     *              neighborhood_id?: ?int, property_type?: ?string, q?: ?string,
     *              bbox?: ?array{0: float, 1: float, 2: float, 3: float}} $f
     * @param array{0: string|int, 1: int}|null $after curseur [valeur de tri, id]
     */
    public function search(array $f, string $sort, int $limit, ?array $after): array
    {
        [$where, $params] = $this->where($f);
        [$column, $dir] = self::SORTS[$sort];

        if ($after !== null) {
            $op = $dir === 'DESC' ? '<' : '>';
            $where .= " AND ({$column} {$op} :after_v OR ({$column} = :after_v2 AND h.id {$op} :after_id))";
            $params += ['after_v' => $after[0], 'after_v2' => $after[0], 'after_id' => $after[1]];
        }

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} ORDER BY {$column} {$dir}, h.id {$dir} LIMIT :limit",
            $params + ['limit' => $limit],
        );
    }

    /** Mode carte : épingles légères (pas de description, pas d'images). */
    public function pins(array $f, int $limit): array
    {
        [$where, $params] = $this->where($f);

        return Database::fetchAll(
            "SELECT h.uid, h.transaction, h.price_cents, h.latitude, h.longitude FROM housing_listings h
             LEFT JOIN neighborhoods n ON n.id = h.neighborhood_id
             WHERE {$where} AND h.latitude IS NOT NULL ORDER BY h.published_at DESC LIMIT :limit",
            $params + ['limit' => $limit],
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);

        return (int) Database::fetch(
            "SELECT COUNT(*) AS n FROM housing_listings h LEFT JOIN neighborhoods n ON n.id = h.neighborhood_id WHERE {$where}",
            $params,
        )['n'];
    }

    /** Compteurs « Tous 5 · Location 2 · Achat 2 · Colocation 1 » (cache court, commun à tous). */
    public function transactionCounts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('housing:counts'))) {
            return $cached;
        }

        $counts = array_fill_keys(array_keys((array) Config::get('housing.transactions')), 0);
        foreach (Database::fetchAll("SELECT transaction, COUNT(*) AS n FROM housing_listings WHERE status = 'published' GROUP BY transaction") as $row) {
            $counts[$row['transaction']] = (int) $row['n'];
        }

        if (Cache::isInMemory()) {
            Cache::store()->set('housing:counts', $counts, (int) Config::get('housing.counts_cache_ttl', 60));
        }

        return $counts;
    }

    public function find(string $uid): ?array
    {
        return Database::fetch(
            'SELECT ' . self::COLUMNS . ', h.contact_name, h.contact_email FROM ' . self::FROM . ' WHERE h.uid = :u AND h.status IN (\'published\', \'archived\')',
            ['u' => strtolower($uid)],
        );
    }

    /** @return array<string, array> uid => ligne */
    public function findManyByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$in, $params] = self::in('u', $uids);

        return array_column(
            Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE h.uid IN ({$in}) AND h.status <> 'draft'", $params),
            null,
            'uid',
        );
    }

    /** @return list<string> */
    public function images(int $listingId): array
    {
        return array_column(
            Database::fetchAll('SELECT url FROM housing_images WHERE listing_id = :l ORDER BY position', ['l' => $listingId]),
            'url',
        );
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('housing:counts');
        }
    }

    /** @return array{0: string, 1: array} */
    private function where(array $f): array
    {
        $where = 'h.status = \'published\'';
        $params = [];

        if (!empty($f['transaction'])) {
            $where .= ' AND h.transaction = :tx';
            $params['tx'] = $f['transaction'];
        }
        if (!empty($f['property_type'])) {
            $where .= ' AND h.property_type = :pt';
            $params['pt'] = $f['property_type'];
        }
        if (!empty($f['furnished'])) {
            $where .= ' AND h.furnished = 1';
        }
        if (!empty($f['max_price_cents'])) {
            $where .= ' AND h.price_cents <= :maxp';
            $params['maxp'] = $f['max_price_cents'];
        }
        if (!empty($f['min_rooms'])) {
            $where .= ' AND h.rooms >= :rooms';
            $params['rooms'] = $f['min_rooms'];
        }
        if (!empty($f['min_surface'])) {
            $where .= ' AND h.surface_m2 >= :minsurf';
            $params['minsurf'] = $f['min_surface'];
        }
        if (!empty($f['district'])) {
            $where .= ' AND (h.district_label = :district OR n.name = :district2)';
            $params['district'] = $f['district'];
            $params['district2'] = $f['district'];
        }
        if (!empty($f['neighborhood_id'])) {
            $where .= ' AND h.neighborhood_id = :hood';
            $params['hood'] = $f['neighborhood_id'];
        }
        if (!empty($f['bbox'])) {
            [$minLat, $minLng, $maxLat, $maxLng] = $f['bbox'];
            $where .= ' AND h.latitude BETWEEN :minlat AND :maxlat AND h.longitude BETWEEN :minlng AND :maxlng';
            $params += ['minlat' => (string) $minLat, 'maxlat' => (string) $maxLat, 'minlng' => (string) $minLng, 'maxlng' => (string) $maxLng];
        }
        if (!empty($f['q'])) {
            // « Quartier, ville, type de bien… » : chaque mot doit apparaître dans au moins un champ
            foreach (array_slice(preg_split('/\s+/', trim($f['q'])) ?: [], 0, 5) as $i => $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';
                $where .= " AND (h.title LIKE :q{$i}a OR h.city LIKE :q{$i}b OR h.district_label LIKE :q{$i}c
                                 OR n.name LIKE :q{$i}d OR h.postal_code LIKE :q{$i}e OR h.property_type IN (:q{$i}type))";
                $params += [
                    "q{$i}a" => $like, "q{$i}b" => $like, "q{$i}c" => $like, "q{$i}d" => $like, "q{$i}e" => $like,
                    "q{$i}type" => self::typeFromWord($word),
                ];
            }
        }

        return [$where, $params];
    }

    /** « appartement » → apartment, « maison » → house... (recherche en français) */
    private static function typeFromWord(string $word): string
    {
        $word = mb_strtolower($word);
        foreach ((array) Config::get('housing.property_types') as $code => $label) {
            if (str_starts_with(mb_strtolower($label), $word) && mb_strlen($word) >= 3) {
                return $code;
            }
        }

        return '__none__';
    }

    /** @return array{0: string, 1: array} */
    private static function in(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $placeholders[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $v;
        }

        return [implode(', ', $placeholders), $params];
    }
}
