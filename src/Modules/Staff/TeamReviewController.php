<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Admin\Audit;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Places\VisitorPhotos;
use App\Security\Crypto;

/**
 * « L'avis de la team MyClermont » (demandes iOS 4a9dc3a3, 2399ca9a), rédigé depuis l'espace /admin du dashboard :
 *   GET /admin/team-reviews?status=brouillon|publie&q=     → { items: AdminTeamReview[], nextCursor, counts: { brouillon, publie } }
 *   GET /admin/places/search?q=                            → { items: PinRef[] }
 *   PUT /admin/places/{key}/team-review                    → AdminTeamReview (crée l'ébauche du pin si besoin : pin { name, latitude, longitude })
 *   DELETE /admin/places/{key}/team-review                 → 204
 *   GET /admin/places/{key}/team-review/history            → { items: TeamReviewAuditEntry[], nextCursor }
 * Le commerçant le lit dans GET /pro/place (publié seulement) ; l'app dans GET /places/{id}/sheet.
 */
final class TeamReviewController
{
    public const VERDICTS = ['Coup de cœur', 'Recommandé', 'Bon plan', 'Incontournable'];

    public function index(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = [max(1, min(100, (int) ($q['limit'] ?? 30))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
        $where = ['1 = 1'];
        $params = [];
        if (in_array($q['status'] ?? null, ['brouillon', 'publie'], true)) {
            $where[] = 'r.status = :s';
            $params['s'] = $q['status'];
        }
        if (trim((string) ($q['q'] ?? '')) !== '') {
            $where[] = '(p.name LIKE :q OR r.title LIKE :q2 OR p.city LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . trim((string) $q['q']) . '%';
        }
        $rows = Database::fetchAll('SELECT r.* FROM place_team_reviews r JOIN places p ON p.id = r.place_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.updated_at DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $params);
        $counts = ['brouillon' => 0, 'publie' => 0];
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM place_team_reviews GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }

        return Response::success(['items' => array_map([self::class, 'admin'], array_slice($rows, 0, $limit)),
                                  'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null, 'counts' => $counts]);
    }

    /** Recherche d'un pin à commenter, avec ou sans commerçant */
    public function search(Request $request): Response
    {
        $q = trim((string) ($request->query()['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return Response::success(['items' => []]);
        }
        $rows = Database::fetchAll("SELECT id, uid, app_id, name, city, latitude, longitude, business_id FROM places
                                    WHERE deleted_at IS NULL AND (name LIKE :q OR app_id LIKE :q2) ORDER BY status = 'published' DESC, name LIMIT 20",
            ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%']);

        return Response::success(['items' => array_map([self::class, 'pin'], $rows)]);
    }

    public function put(Request $request): Response
    {
        $key = rawurldecode((string) $request->param('key'));
        $j = $request->json();
        $place = self::place($key, is_array($j['pin'] ?? null) ? $j['pin'] : null);
        $staff = $request->attributes['staff'];
        $data = self::validate($j);
        $now = gmdate('Y-m-d H:i:s');
        $existing = Database::fetch('SELECT * FROM place_team_reviews WHERE place_id = :p', ['p' => $place['id']]);
        $publish = $data['status'] === 'publie';
        $publishedAt = $publish ? ($existing['published_at'] ?? $now) : null;   // gardée en modification, effacée au retrait
        $row = $data + ['published_at' => $publishedAt, 'updated_at' => $now, 'updated_by' => (string) $staff['name'], 'place_key' => $key];
        if ($existing === null) {
            Database::insert('INSERT INTO place_team_reviews (place_id, ' . implode(', ', array_keys($row)) . ', created_at) VALUES (:place_id, :' . implode(', :', array_keys($row)) . ', :created_at)',
                $row + ['place_id' => $place['id'], 'created_at' => $now]);
        } else {
            $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($row)));
            Database::execute("UPDATE place_team_reviews SET {$sets} WHERE id = :id", $row + ['id' => $existing['id']]);
        }
        $action = match (true) {
            $existing === null => $publish ? 'publie' : 'redige',
            $existing['status'] === 'publie' && !$publish => 'retire',
            $existing['status'] !== 'publie' && $publish => 'publie',
            $publish => 'modifie_en_ligne',
            default => 'modifie',
        };
        self::audit($key, (string) $staff['name'], $action, mb_substr($data['title'] ?: $data['text'], 0, 120));
        Audit::log((int) $staff['id'], 'team_review.' . $action, $request->ip, 'places', $key);
        if ($action === 'publie' && $place['business_id'] !== null) {
            \App\Modules\Pro\Pro::notify((int) $place['business_id'], 'moderation', 'La team MyClermont a publié un avis sur ta fiche', $data['title'] ?: 'Découvre-le dans « Ma fiche ».', '/fiche');
        }
        \App\Admin\CacheInvalidator::afterWrite('places', ['id' => $place['id']], null);

        return Response::success(self::admin(Database::fetch('SELECT * FROM place_team_reviews WHERE place_id = :p', ['p' => $place['id']])));
    }

    public function destroy(Request $request): Response
    {
        $key = rawurldecode((string) $request->param('key'));
        $place = self::place($key, null);
        if (Database::execute('DELETE FROM place_team_reviews WHERE place_id = :p', ['p' => $place['id']]) === 0) {
            throw new HttpException(404, 'not_found', 'Pas d\'avis de la team sur ce lieu.');
        }
        self::audit($key, (string) $request->attributes['staff']['name'], 'supprime', null);
        Audit::log((int) $request->attributes['staff']['id'], 'team_review.supprime', $request->ip, 'places', $key);
        \App\Admin\CacheInvalidator::afterWrite('places', ['id' => $place['id']], null);

        return Response::noContent();
    }

    public function history(Request $request): Response
    {
        $key = rawurldecode((string) $request->param('key'));
        $q = $request->query();
        [$limit, $offset] = [max(1, min(100, (int) ($q['limit'] ?? 50))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
        $rows = Database::fetchAll('SELECT * FROM place_team_review_audit WHERE place_key = :k ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, ['k' => $key]);

        return Response::success([
            'items' => array_map(static fn ($a) => ['id' => $a['uid'], 'at' => VisitorPhotos::iso($a['created_at']), 'pin_key' => $a['place_key'],
                'actor' => ['kind' => $a['actor_kind'], 'name' => $a['actor_name']], 'action' => $a['action'], 'summary' => $a['summary']], array_slice($rows, 0, $limit)),
            'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null,
        ]);
    }

    /** TeamReview de l'app (publié) ou du dashboard ; null si absent */
    public static function present(?array $r, bool $withStatus = false): ?array
    {
        if ($r === null) {
            return null;
        }
        $out = [
            'title' => $r['title'], 'text' => $r['text'], 'rating' => $r['rating'] === null ? null : (float) $r['rating'], 'verdict' => $r['verdict'],
            'pros' => json_decode($r['pros'], true) ?: [], 'cons' => json_decode($r['cons'], true) ?: [], 'author' => $r['author'],
            'visited_at' => VisitorPhotos::iso($r['visited_at']), 'published_at' => VisitorPhotos::iso($r['published_at']),
        ];

        return $withStatus ? $out + ['status' => $r['status']] : $out;
    }

    /** Avis publié d'un lieu (app, fiche pro) */
    public static function published(int $placeId): ?array
    {
        return self::present(Database::fetch("SELECT * FROM place_team_reviews WHERE place_id = :p AND status = 'publie'", ['p' => $placeId]));
    }

    private static function admin(array $r): array
    {
        $p = Database::fetch('SELECT id, uid, app_id, name, city, latitude, longitude, business_id FROM places WHERE id = :id', ['id' => $r['place_id']]);

        return ['pin' => self::pin($p, $r['place_key']), 'review' => self::present($r, true), 'created_at' => VisitorPhotos::iso($r['created_at']),
                'updated_at' => VisitorPhotos::iso($r['updated_at']), 'updated_by' => $r['updated_by']];
    }

    /** PinRef : { key, kind, name, city, claimedBy } (claimedBy : commerçant inscrit, quel que soit son statut) */
    private static function pin(array $p, ?string $key = null): array
    {
        $claim = $p['business_id'] ? Database::fetch('SELECT id, trade_name, verification FROM pro_businesses WHERE id = :b AND deleted_at IS NULL', ['b' => $p['business_id']]) : null;
        $key ??= $p['business_id'] ? 'place-' . ($p['app_id'] ?? $p['uid']) : 'spot-' . ($p['app_id'] ?? $p['uid']);

        return ['key' => $key, 'kind' => explode('-', $key)[0], 'name' => $p['name'], 'city' => (string) $p['city'],
                'latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude'],
                'claimed_by' => $claim ? ['business_id' => (int) $claim['id'], 'name' => $claim['trade_name'], 'status' => $claim['verification']] : null];
    }

    /** Lieu désigné par la clé de l'app ; un repère inconnu devient une ébauche si l'équipe donne nom et coordonnées */
    private static function place(string $key, ?array $pin): array
    {
        if (!preg_match('/^(spot|place|event)-.+$/su', $key)) {
            throw new HttpException(404, 'not_found', 'Lieu inconnu.');
        }
        [$placeId] = str_starts_with($key, 'event-') || $pin !== null ? VisitorPhotos::route($key, $pin ?? []) : [null];
        if ($placeId === null) {
            $id = preg_replace('/^(spot|place)-/', '', $key);
            $placeId = Database::fetch('SELECT id FROM places WHERE (app_id = :a OR uid = :u OR slug = :s) AND deleted_at IS NULL', ['a' => $id, 'u' => $id, 's' => $id])['id'] ?? null;
        }

        return Database::fetch('SELECT id, business_id FROM places WHERE id = :id', ['id' => $placeId ?? 0]) ?? throw new HttpException(404, 'not_found', 'Lieu introuvable : donne son nom et ses coordonnées pour le créer.');
    }

    private static function validate(array $j): array
    {
        $errors = [];
        $str = static function (string $k, int $max, bool $required = false) use ($j, &$errors): string {
            $v = $j[$k] ?? '';
            if (!is_string($v)) {
                $errors[$k] = 'Texte attendu.';
                return '';
            }
            $v = trim($v);
            if (mb_strlen($v) > $max) {
                $errors[$k] = "{$max} caractères au plus.";
            }
            if ($required && $v === '') {
                $errors[$k] = 'Obligatoire pour publier.';
            }

            return mb_substr($v, 0, $max);
        };
        $status = in_array($j['status'] ?? 'brouillon', ['brouillon', 'publie'], true) ? ($j['status'] ?? 'brouillon') : null;
        if ($status === null) {
            $errors['status'] = 'Statut inconnu.';
        }
        $publish = $status === 'publie';
        $out = ['title' => $str('title', 80), 'text' => $str('text', 1500, $publish), 'author' => $str('author', 60, $publish), 'status' => $status ?? 'brouillon'];
        $rating = $j['rating'] ?? null;
        if ($rating !== null && (!is_numeric($rating) || $rating < 0.5 || $rating > 5 || fmod((float) $rating * 2, 1.0) !== 0.0)) {
            $errors['rating'] = 'Note de 0,5 à 5, par demi-points.';
        }
        $out['rating'] = $rating === null ? null : (float) $rating;
        $verdict = $j['verdict'] ?? null;
        if ($verdict !== null && !in_array($verdict, self::VERDICTS, true)) {
            $errors['verdict'] = 'Verdict inconnu.';
        }
        $out['verdict'] = $verdict;
        foreach (['pros', 'cons'] as $k) {
            $list = $j[$k] ?? [];
            if (!is_array($list) || count($list) > 5 || array_filter($list, static fn ($s) => !is_string($s) || mb_strlen(trim($s)) > 80) !== []) {
                $errors[$k] = '5 éléments au plus, 80 caractères chacun.';
                $list = [];
            }
            $out[$k] = json_encode(array_values(array_filter(array_map('trim', $list), static fn ($s) => $s !== '')), JSON_UNESCAPED_UNICODE);
        }
        $visited = $j['visited_at'] ?? null;
        if ($visited !== null && (!is_string($visited) || ($ts = strtotime($visited)) === false || $ts > time() + 86400)) {
            $errors['visitedAt'] = 'Date de visite invalide (pas dans le futur).';
        }
        $out['visited_at'] = $visited !== null && isset($ts) && $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    private static function audit(string $key, string $actor, string $action, ?string $summary): void
    {
        Database::insert('INSERT INTO place_team_review_audit (uid, place_key, actor_kind, actor_name, action, summary, created_at) VALUES (:u, :k, \'admin\', :n, :a, :s, :d)',
            ['u' => Crypto::uuid4(), 'k' => mb_substr($key, 0, 255), 'n' => $actor, 'a' => $action, 's' => $summary, 'd' => gmdate('Y-m-d H:i:s')]);
    }
}
