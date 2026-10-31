<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Carte selon la spec v1 (docs/SPEC_V1.txt › Carte) : lieux édités par My Clermont au format MapSpot de l'app.
 *
 *   GET /places              bbox?, category?, partner?, updatedSince?   → { items: Place[] } (2 000 max, sans pagination)
 *   GET /places/{id}         PlaceDetail
 *   GET /places/{id}/photos  { items: Photo[] }
 *   GET /partners            { items: [{ key, name, placeId, until }] }
 *   GET /photos/manifest.json  (compatibilité build 16, non signé)
 */
final class MapController
{
    public const CATEGORIES = ['incontournable', 'evenement', 'spot', 'restaurant', 'fastFood', 'cafe', 'boulangerie', 'culture', 'nature', 'sport', 'carburant', 'lieu'];
    public const NATURE_KINDS = ['balade', 'panorama', 'peche', 'baignade', 'piqueNique', 'champignons', 'muguet'];

    /** Zone servie (lon/lat) : la même boîte que la carte de l'app. */
    public const ZONE = [2.74, 45.52, 3.44, 46.08];

    /** Catégorie de l'app : forcée sur le lieu, sinon « incontournable », sinon celle de sa catégorie. */
    private const APP_CATEGORY = "COALESCE(p.app_category, IF(p.is_must_see = 1, 'incontournable', c.app_category))";
    private const PARTNER = '(p.is_partner = 1 AND (p.partner_until IS NULL OR p.partner_until >= UTC_DATE()))';

    public function index(Request $request): Response
    {
        $q = $request->query();
        $where = ["p.status = 'published'", 'p.deleted_at IS NULL'];
        $params = [];

        [$minLon, $minLat, $maxLon, $maxLat] = isset($q['bbox']) ? self::bbox((string) $q['bbox']) : self::ZONE;
        $where[] = 'p.latitude BETWEEN :minLat AND :maxLat AND p.longitude BETWEEN :minLon AND :maxLon';
        $params += ['minLat' => $minLat, 'maxLat' => $maxLat, 'minLon' => $minLon, 'maxLon' => $maxLon];

        if (isset($q['category'])) {
            if (!in_array($q['category'], self::CATEGORIES, true)) {
                throw new ValidationException(['category' => 'Catégorie inconnue.']);
            }
            $where[] = self::APP_CATEGORY . ' = :cat';
            $params['cat'] = $q['category'];
        }
        if (isset($q['partner'])) {
            $where[] = in_array($q['partner'], ['1', 'true'], true) ? self::PARTNER : 'NOT ' . self::PARTNER;
        }
        if (isset($q['updated_since'])) {
            $since = strtotime((string) $q['updated_since']);
            if ($since === false) {
                throw new ValidationException(['updated_since' => 'Date invalide (ISO 8601 attendu).']);
            }
            $where[] = 'p.updated_at >= :since';
            $params['since'] = gmdate('Y-m-d H:i:s', $since);
        }

        // Toute la carte tient dans la mémoire partagée : même requête = aucune lecture MySQL pendant 10 min
        $key = 'map:places:' . self::version() . ':' . sha1(json_encode($params));
        $items = Cache::store()->get($key);
        if (!is_array($items)) {
            $items = array_map([self::class, 'place'], Database::fetchAll(
                'SELECT ' . self::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id WHERE '
                . implode(' AND ', $where) . ' ORDER BY p.is_must_see DESC, p.id LIMIT 2000',
                $params,
            ));
            Cache::store()->set($key, $items, 600);
        }

        return Response::success($items)->withHeader('Cache-Control', 'private, max-age=600');
    }

    public function show(Request $request): Response
    {
        $p = $this->find((string) $request->param('id'));
        $json = static fn (?string $v) => $v === null ? [] : (json_decode($v, true) ?: []);
        $nature = $p['nature'] !== null ? $json($p['nature']) : null;
        $partner = (bool) $p['is_partner_active'];

        $detail = self::place($p) + [
            'address' => trim(implode(', ', array_filter([$p['address'], trim(($p['postal_code'] ?? '') . ' ' . $p['city'])]))) ?: null,
            'guide'   => $p['tagline'] || $p['facts'] || $p['highlights'] || $p['timeline'] ? [
                'tagline'    => $p['tagline'],
                'facts'      => $json($p['facts']),
                'highlights' => $json($p['highlights']),
                'timeline'   => $json($p['timeline']),
            ] : null,
            'nature'  => $nature === null ? null : [
                'kinds'     => array_values(array_intersect((array) ($nature['kinds'] ?? []), self::NATURE_KINDS)),
                'area'      => $nature['area'] ?? null,
                'highlight' => $nature['highlight'] ?? null,
                'summary'   => $nature['summary'] ?? null,
                'facts'     => (array) ($nature['facts'] ?? []),
                'propice'   => $nature['propice'] ?? null,
                'warning'   => $nature['warning'] ?? null,
            ],
            'photos'  => self::photos((int) $p['id']),
            'rating'  => ['average' => (int) $p['rating_count'] > 0 ? round((float) $p['rating_avg'], 1) : null, 'count' => (int) $p['rating_count']],
            'partner' => $partner ? [
                'since'    => $p['partner_since'],
                'deal_ids' => self::dealIds((int) $p['id']),
            ] : null,
        ];

        return Response::success($detail)->withHeader('Cache-Control', 'private, max-age=600');
    }

    public function photosOf(Request $request): Response
    {
        $p = $this->find((string) $request->param('id'));

        return Response::success(self::photos((int) $p['id']))->withHeader('Cache-Control', 'private, max-age=3600');
    }

    public function partners(Request $request): Response
    {
        $rows = Database::fetchAll(
            'SELECT p.uid, p.slug, p.name, p.partner_until FROM places p
             WHERE p.status = \'published\' AND p.deleted_at IS NULL AND ' . self::PARTNER . ' ORDER BY p.name',
        );

        return Response::success(array_map(static fn (array $r) => [
            'key'      => $r['slug'] ?? $r['uid'],
            'name'     => $r['name'],
            'place_id' => $r['uid'],
            'until'    => $r['partner_until'],
        ], $rows))->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /** Compatibilité build 16 : { "spots": { "<nom du lieu>": [{ url, credit }] } }. Les photos Wikimedia / Look Around restent ajoutées par l'app. */
    public function manifest(Request $request): Response
    {
        $spots = Cache::store()->get('map:manifest:' . self::version());
        if (!is_array($spots)) {
            $spots = [];
            foreach (Database::fetchAll(
                'SELECT COALESCE(p.demo_name, p.name) AS name, ph.url, ph.credit FROM place_photos ph JOIN places p ON p.id = ph.place_id
                 WHERE p.status = \'published\' AND p.deleted_at IS NULL ORDER BY p.id, ph.position',
            ) as $r) {
                $spots[$r['name']][] = ['url' => $r['url'], 'credit' => $r['credit']];
            }
            Cache::store()->set('map:manifest:' . self::version(), $spots, 600);
        }

        return Response::json(['spots' => (object) $spots])->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=3600');
    }

    /** Place = exactement les champs de MapSpot dans l'app. */
    public static function place(array $p): array
    {
        return [
            'id'          => $p['uid'],
            'name'        => $p['name'],
            'category'    => $p['app_category_value'],
            'latitude'    => round((float) $p['latitude'], 6),
            'longitude'   => round((float) $p['longitude'], 6),
            'subtitle'    => (string) ($p['subtitle'] ?? $p['tagline'] ?? ''),   // brief iOS §12.3 : jamais null
            'phone'       => $p['phone'],
            'website'     => $p['website_url'],
            'is_partner'  => (bool) $p['is_partner_active'],
            'demo_name'   => $p['demo_name'] ?? $p['name'],
            'nature_kind' => $p['nature_kind'],
        ];
    }

    /** Version de la carte : changée à chaque modification de lieu (forgetPins) → les caches ci-dessus expirent d'eux-mêmes. */
    public static function version(): string
    {
        return (new PlaceRepository())->pinSet()['version'];
    }

    public static function columns(): string
    {
        return 'p.id, p.uid, p.slug, p.name, p.demo_name, p.subtitle, p.tagline, p.latitude, p.longitude, p.phone, p.website_url, '
             . self::APP_CATEGORY . ' AS app_category_value, ' . self::PARTNER . ' AS is_partner_active, '
             . "JSON_UNQUOTE(JSON_EXTRACT(p.nature, '$.kinds[0]')) AS nature_kind";
    }

    private function find(string $id): array
    {
        $by = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? 'p.uid' : (preg_match('/^[a-z0-9-]{1,140}$/', $id) ? 'p.slug' : null);
        $p = $by === null ? null : Database::fetch(
            'SELECT ' . self::columns() . ', p.address, p.postal_code, p.city, p.facts, p.highlights, p.timeline, p.nature, p.rating_avg, p.rating_count, p.partner_since
             FROM places p JOIN place_categories c ON c.id = p.category_id WHERE ' . $by . " = :id AND p.status = 'published' AND p.deleted_at IS NULL",
            ['id' => strtolower($id)],
        );

        return $p ?? throw new HttpException(404, 'place_not_found', 'Ce lieu n\'existe pas ou n\'est plus en ligne.');
    }

    /** @return list<array{url: string, credit: ?string, source: string, width: ?int, height: ?int}> */
    private static function photos(int $placeId): array
    {
        return array_map(static fn (array $r) => [
            'url' => $r['url'], 'credit' => $r['credit'], 'source' => $r['source'],
            'width' => $r['width'] === null ? null : (int) $r['width'], 'height' => $r['height'] === null ? null : (int) $r['height'],
        ], Database::fetchAll('SELECT url, credit, source, width, height FROM place_photos WHERE place_id = :p ORDER BY position, id', ['p' => $placeId]));
    }

    /** @return list<string> */
    private static function dealIds(int $placeId): array
    {
        try {
            return array_column(Database::fetchAll(
                "SELECT uid FROM deals WHERE place_id = :p AND status = 'published' AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
                ['p' => $placeId],
            ), 'uid');
        } catch (\PDOException) {
            return []; // module Bons plans pas encore migré
        }
    }

    /** « minLon,minLat,maxLon,maxLat », limité à la zone servie. */
    private static function bbox(string $raw): array
    {
        $v = array_map('trim', explode(',', $raw));
        if (count($v) !== 4 || array_filter($v, static fn ($x) => !is_numeric($x)) !== []) {
            throw new ValidationException(['bbox' => 'Format attendu : minLon,minLat,maxLon,maxLat.']);
        }
        [$a, $b, $c, $d] = array_map('floatval', $v);
        if ($a >= $c || $b >= $d) {
            throw new ValidationException(['bbox' => 'Boîte invalide.']);
        }

        return [max($a, self::ZONE[0]), max($b, self::ZONE[1]), min($c, self::ZONE[2]), min($d, self::ZONE[3])];
    }
}
