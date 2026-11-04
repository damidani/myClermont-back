<?php

declare(strict_types=1);

namespace App\Modules\City;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

final class CityController
{
    /** Domaines des filtres de l'app → symbole SF par défaut */
    public const ASSOCIATION_ICONS = ['Sport' => 'figure.run', 'Culture' => 'theatermasks.fill', 'Solidarité' => 'heart.fill', 'Environnement' => 'leaf.fill',
                                      'Jeunesse' => 'graduationcap.fill', 'Santé' => 'cross.case.fill', 'Vie locale' => 'person.3.fill'];

    public function news(Request $request): Response
    {
        $q = $request->query();
        $where = ["status = 'published'", '(published_at IS NULL OR published_at <= UTC_TIMESTAMP())'];
        $p = [];
        if (isset($q['kind']) && $q['kind'] !== '') {
            in_array($q['kind'], ['article', 'video'], true) || throw new ValidationException(['kind' => 'article ou video.']);
            $where[] = 'kind = :k';
            $p['k'] = $q['kind'];
        }
        $this->common($q, $where, $p, ['title', 'summary']);
        [$rows, $next] = $this->page("SELECT * FROM news_items WHERE %s ORDER BY COALESCE(published_at, created_at) DESC, id DESC", $where, $p, $q);

        return Response::success(array_map([self::class, 'newsCard'], $rows), 200, ['next_cursor' => $next]);
    }

    public function newsItem(Request $request): Response
    {
        $row = Database::fetch("SELECT * FROM news_items WHERE uid = :u AND status = 'published'", ['u' => (string) $request->param('id')])
            ?? throw new HttpException(404, 'news_not_found', 'Article introuvable.');

        return Response::success(self::newsCard($row));
    }

    public function associations(Request $request): Response
    {
        $q = $request->query();
        $where = ["status = 'published'"];
        $p = [];
        $this->common($q, $where, $p, ['name', 'description', 'district']);
        // Celles qui ont un site d'abord, puis les plus récentes
        [$rows, $next] = $this->page('SELECT * FROM associations WHERE %s ORDER BY website_url IS NULL, created_on DESC, id DESC', $where, $p, $q);

        return Response::success(array_map([self::class, 'associationCard'], $rows), 200, ['next_cursor' => $next]);
    }

    public function association(Request $request): Response
    {
        $row = Database::fetch("SELECT * FROM associations WHERE uid = :u AND status = 'published'", ['u' => (string) $request->param('id')])
            ?? throw new HttpException(404, 'association_not_found', 'Association introuvable.');

        return Response::success(self::associationCard($row));
    }

    /** Balade du jour : celle programmée aujourd'hui (heure de Paris), sinon rotation quotidienne des balades publiées */
    public function featuredItinerary(Request $request): Response
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $row = Database::fetch("SELECT * FROM itineraries WHERE status = 'published' AND featured_on = :d ORDER BY id LIMIT 1", ['d' => $today]);
        if ($row === null) {
            $all = Database::fetchAll("SELECT * FROM itineraries WHERE status = 'published' AND featured_on IS NULL ORDER BY id");
            $row = $all === [] ? null : $all[intdiv(strtotime($today . ' UTC'), 86400) % count($all)];
        }
        if ($row === null) {
            throw new HttpException(404, 'no_itinerary', 'Pas de balade du jour.');
        }
        $stops = array_values(array_filter((array) json_decode((string) $row['stops'], true), static fn ($s) => is_array($s) && isset($s['name'], $s['latitude'], $s['longitude'])));

        return Response::success([
            'id' => $row['uid'], 'title' => $row['title'], 'subtitle' => $row['subtitle'],
            'stops' => array_map(static fn ($s) => array_filter(['name' => (string) $s['name'], 'latitude' => (float) $s['latitude'], 'longitude' => (float) $s['longitude'],
                                                                  'place_id' => isset($s['placeId']) ? (string) $s['placeId'] : null], static fn ($v) => $v !== null), $stops),
        ]);
    }

    public static function newsCard(array $n): array
    {
        $https = static fn (?string $u) => is_string($u) && str_starts_with($u, 'https://') ? $u : null;

        return [
            'id' => $n['uid'], 'title' => $n['title'], 'kind' => $n['kind'], 'category' => $n['category'], 'summary' => (string) $n['summary'],
            'body' => $n['kind'] === 'article' ? (string) $n['body'] : null, 'image' => $https($n['image_url']),
            'video_url' => $n['kind'] === 'video' ? $https($n['video_url']) : null, 'author' => $n['author'] ?: 'MyClermont',
            'published_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime(($n['published_at'] ?? $n['created_at']) . ' UTC')),
            'reading_minutes' => $n['kind'] === 'article' ? ($n['reading_minutes'] !== null ? (int) $n['reading_minutes'] : max(1, (int) round(str_word_count(strip_tags((string) $n['body'])) / 200))) : null,
        ];
    }

    public static function associationCard(array $a): array
    {
        return array_filter([
            'id' => $a['uid'], 'name' => $a['name'], 'category' => $a['category'], 'district' => (string) $a['district'],
            'icon' => $a['icon'] ?: (self::ASSOCIATION_ICONS[$a['category']] ?? 'person.3.fill'), 'description' => (string) $a['description'],
            'activities' => array_values(array_filter((array) json_decode((string) $a['activities'], true), 'is_string')),
            'address' => $a['address'], 'email' => $a['email'], 'phone' => $a['phone'], 'website' => $a['website_url'],
            'latitude' => $a['latitude'] !== null ? (float) $a['latitude'] : null, 'longitude' => $a['longitude'] !== null ? (float) $a['longitude'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** Filtres communs : category (libellé exact), q (recherche dans $columns) */
    private function common(array $q, array &$where, array &$p, array $columns): void
    {
        if (isset($q['category']) && $q['category'] !== '' && $q['category'] !== 'Toutes') {
            $where[] = 'category = :cat';
            $p['cat'] = mb_substr((string) $q['category'], 0, 40);
        }
        if (isset($q['q']) && trim((string) $q['q']) !== '') {
            $like = [];
            foreach ($columns as $i => $c) {
                $like[] = "{$c} LIKE :q{$i}";
                $p["q{$i}"] = '%' . mb_substr(trim((string) $q['q']), 0, 100) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
    }

    /** Pagination par numéro de page dans le curseur (comme les autres modules Ma Ville) */
    private function page(string $sql, array $where, array $p, array $q): array
    {
        $limit = max(1, min(50, (int) ($q['limit'] ?? 20)));
        $page = max(1, (int) ($q['cursor'] ?? 1));
        $rows = Database::fetchAll(sprintf($sql, implode(' AND ', $where)) . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . (($page - 1) * $limit), $p);

        return [array_slice($rows, 0, $limit), count($rows) > $limit ? (string) ($page + 1) : null];
    }
}
