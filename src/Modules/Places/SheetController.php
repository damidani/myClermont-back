<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Modules\Deals\DealController;
use App\Modules\Pro\PlaceController as ProPlace;
use App\Modules\Pro\Pro;

/**
 * GET /places/{id}/sheet (demande iOS b6496778) : la fiche d'un pin au format Place du dashboard (GET /pro/place),
 * avec rating, deals, events et isPartner. {id} = identifiant de l'app (« nom@lat,lon »), uid ou slug.
 * Seulement la version publiée : une modification en vérification garde l'ancienne version des champs vérifiés.
 * 404 s'il n'y a pas de fiche publiée (l'app garde alors la fiche Apple Plans).
 */
final class SheetController
{
    private const DAYS = ['mon' => 'lun', 'tue' => 'mar', 'wed' => 'mer', 'thu' => 'jeu', 'fri' => 'ven', 'sat' => 'sam', 'sun' => 'dim'];

    public function show(Request $request): Response
    {
        $found = MapController::findPublished((string) $request->param('id'));
        $place = $found === null ? null : Database::fetch("SELECT * FROM places WHERE id = :id AND status = 'published' AND deleted_at IS NULL",
            ['id' => Database::fetch('SELECT id FROM places WHERE uid = :u', ['u' => $found['uid']])['id'] ?? 0]);
        if ($place === null) {
            // Lieu sans fiche (repère Apple Plans, commerce non inscrit) mais avec un avis de la team publié : fiche minimale (4a9dc3a3)
            $id = rawurldecode((string) $request->param('id'));
            $draft = Database::fetch("SELECT p.* FROM places p JOIN place_team_reviews r ON r.place_id = p.id AND r.status = 'publie'
                                      WHERE (p.app_id = :a OR p.uid = :u OR p.slug = :s) AND p.deleted_at IS NULL LIMIT 1", ['a' => $id, 'u' => $id, 's' => $id]);
            if ($draft === null) {
                throw new HttpException(404, 'place_not_found', 'Pas de fiche publiée pour ce lieu.');
            }
            $sheet = self::minimal($draft);
        } else {
            $sheet = self::sheet($place);
        }
        $etag = '"' . substr(sha1(json_encode($sheet)), 0, 16) . '"';
        if (trim((string) $request->header('if-none-match', '')) === $etag) {
            return Response::notModified($etag);
        }

        return Response::success($sheet)->withHeader('ETag', $etag)->withHeader('Cache-Control', 'private, max-age=300');
    }

    public static function sheet(array $place): array
    {
        $d = $place['pro'] !== null && $place['business_id'] !== null ? ProPlace::published($place) : self::fromColumns($place);
        $d['photos'] = array_values(array_filter(array_map(static fn ($p) => ['src' => self::absolute((string) $p['src'])] + $p + ['caption' => '', 'credit' => null], $d['photos']),
            static fn ($p) => $p['src'] !== ''));
        $have = array_column($d['photos'], 'id');
        $d['photos'] = array_merge($d['photos'], array_values(array_filter(self::visitorPhotos((int) $place['id']), static fn ($p) => !in_array($p['id'], $have, true))));
        unset($d['status']);

        $rating = Database::fetch("SELECT ROUND(AVG(rating), 1) AS average, COUNT(*) AS n FROM place_reviews WHERE place_id = :p AND status = 'visible' AND reported_at IS NULL", ['p' => $place['id']]);
        $partner = Database::fetch('SELECT ' . MapController::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $place['id']]);

        return ['id' => $place['app_id'] ?? $place['uid']] + $d + [
            'is_partner' => (bool) ($partner['is_partner_active'] ?? false),
            'rating' => (int) ($rating['n'] ?? 0) > 0 ? ['average' => (float) $rating['average'], 'count' => (int) $rating['n']] : null,
            'deals' => self::deals($place),
            'events' => self::events($place),
            'team_review' => \App\Modules\Staff\TeamReviewController::published((int) $place['id']),
            'updated_at' => Pro::iso($place['updated_at']),
        ];
    }

    /** id, nom, catégorie, position, champs vides et l'avis de la team */
    private static function minimal(array $p): array
    {
        $empty = ['subtitle' => '', 'tagline' => '', 'description' => '', 'address' => '', 'postal_code' => '', 'city' => (string) $p['city'], 'phone' => '', 'email' => '',
                  'website' => '', 'instagram' => '', 'facebook' => '', 'booking_url' => '', 'menu_url' => '', 'price_level' => 0, 'attributes' => [],
                  'hours' => array_fill_keys(array_values(self::DAYS), []), 'exceptional' => [], 'photos' => [], 'cover_id' => null, 'highlights' => [], 'tip' => ''];

        return ['id' => $p['app_id'] ?? $p['uid'], 'name' => $p['name'], 'category' => $p['app_category'] ?? 'lieu', 'latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude']]
            + $empty + ['is_partner' => false, 'rating' => null, 'deals' => [], 'events' => [], 'team_review' => \App\Modules\Staff\TeamReviewController::published((int) $p['id']),
                        'updated_at' => Pro::iso($p['updated_at'])];
    }

    /** Lieu édité par My Clermont (sans espace pro) : même forme, à partir des colonnes */
    private static function fromColumns(array $p): array
    {
        $hours = array_fill_keys(array_values(self::DAYS), []);
        $oh = json_decode((string) $p['opening_hours'], true);
        foreach (self::DAYS as $en => $fr) {
            foreach ((array) ($oh[$en] ?? []) as $slot) {
                if (is_array($slot) && count($slot) === 2) {
                    $hours[$fr][] = ['open' => (string) $slot[0], 'close' => (string) $slot[1]];
                }
            }
        }
        $highlights = array_values(array_filter((array) json_decode((string) ($p['highlights'] ?? ''), true), 'is_array'));

        return [
            'name' => $p['name'], 'category' => $p['app_category'] ?? 'lieu', 'subtitle' => (string) $p['subtitle'], 'tagline' => (string) $p['tagline'],
            'description' => (string) $p['description'], 'address' => (string) $p['address'], 'postal_code' => (string) $p['postal_code'], 'city' => (string) $p['city'],
            'latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude'], 'phone' => (string) $p['phone'], 'email' => '', 'website' => (string) $p['website_url'],
            'instagram' => '', 'facebook' => '', 'booking_url' => '', 'menu_url' => '', 'price_level' => 0, 'attributes' => [], 'hours' => $hours, 'exceptional' => [],
            'photos' => $p['cover_url'] ? [['id' => 'cover', 'src' => $p['cover_url'], 'caption' => '', 'credit' => null]] : [], 'cover_id' => $p['cover_url'] ? 'cover' : null,
            'highlights' => array_map(static fn ($h) => ['icon' => (string) ($h['icon'] ?? 'star'), 'title' => (string) ($h['title'] ?? ''), 'text' => (string) ($h['text'] ?? '')], $highlights),
            'tip' => (string) $p['tip'],
        ];
    }

    /** Photos des visiteurs validées (ca817cb4) : crédit = pseudo */
    private static function visitorPhotos(int $placeId): array
    {
        return array_map(static fn ($r) => ['id' => 'ph_' . $r['uid'], 'src' => self::absolute($r['url']), 'caption' => (string) $r['caption'], 'credit' => $r['handle']],
            Database::fetchAll("SELECT v.uid, v.url, v.caption, u.handle FROM place_visitor_photos v LEFT JOIN users u ON u.id = v.user_id
                                WHERE v.place_id = :p AND v.status = 'approved' AND v.in_gallery = 1 ORDER BY v.decided_at DESC LIMIT 30", ['p' => $placeId]));
    }

    private static function deals(array $place): array
    {
        $rows = Database::fetchAll(
            "SELECT d.*, p.uid AS place_uid FROM deals d LEFT JOIN places p ON p.id = d.place_id
             WHERE (d.place_id = :p OR (d.business_id IS NOT NULL AND d.business_id = :b)) AND d.status = 'published' AND d.deleted_at IS NULL
               AND (d.pro_status IS NULL OR d.pro_status = 'actif') AND (d.expires_at IS NULL OR d.expires_at > UTC_TIMESTAMP())
             ORDER BY d.id DESC LIMIT 3",
            ['p' => $place['id'], 'b' => $place['business_id'] ?? 0],
        );

        return array_map(static function ($row) {
            $d = DealController::present($row);
            return ['id' => $d['id'], 'title' => $d['title'], 'discount' => $d['discount'], 'reduced' => $d['reduced'], 'original' => $d['original'], 'expires_at' => $d['expires_at']];
        }, $rows);
    }

    private static function events(array $place): array
    {
        return array_map(static fn ($e) => ['id' => $e['slug'], 'title' => $e['title'], 'start' => Pro::paris($e['starts_at']), 'venue' => (string) $e['venue_name']],
            Database::fetchAll(
                "SELECT slug, title, starts_at, venue_name FROM events
                 WHERE (place_id = :p OR (business_id IS NOT NULL AND business_id = :b)) AND status = 'published' AND deleted_at IS NULL AND ends_at >= UTC_TIMESTAMP()
                 ORDER BY starts_at LIMIT 3",
                ['p' => $place['id'], 'b' => $place['business_id'] ?? 0],
            ));
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? Config::get('app.url', 'https://api.myclermont.app') . $url : $url;
    }
}
