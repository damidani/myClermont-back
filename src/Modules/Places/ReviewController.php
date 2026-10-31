<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Account\Repositories\UserRepository;
use App\Modules\Moderation\ContentFilter;
use App\Modules\Favorites\SpecFavoriteController;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * Avis selon la spec v1 : un avis par compte et par lieu ; la note est publiée tout de suite,
 * un texte passe par le filtre automatique avant d'apparaître. Moyenne et nombre recalculés à chaque écriture.
 *
 *   GET    /places/{key}/reviews?cursor=  { items: [{ id, rating, text, author, createdAt }], average, count, nextCursor }
 *   PUT    /places/{key}/reviews/me       { rating, text? }  → Review
 *   DELETE /places/{key}/reviews/me       204
 *   POST   /me/reviews/import             { items: [{ key, rating, text? }] } ou { ratings: { "<clé>": note } }  → { imported, skipped }
 *
 * {key} (brief iOS §9) : UUID d'un lieu du back, ou clé de l'app (spot-…, place-<nom>), encodée dans l'URL.
 * Une clé inconnue du back (lieu issu d'Apple Plans) range l'avis sous la clé elle-même (place_key).
 */
final class ReviewController
{
    public function index(Request $request): Response
    {
        $place = $this->place((string) $request->param('id'));
        $cursor = $request->query()['cursor'] ?? null;
        $before = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : PHP_INT_MAX;
        [$where, $params] = self::scope($place);
        $rows = Database::fetchAll(
            "SELECT r.id, r.uid, r.rating, r.comment, r.status, r.created_at, u.uid AS user_uid, u.display_name, u.first_name, u.username, u.avatar_kind, u.avatar_value, u.status AS user_status
             FROM place_reviews r JOIN users u ON u.id = r.user_id
             WHERE {$where} AND r.status IN ('visible', 'pending') AND r.id < :b ORDER BY r.id DESC LIMIT 21",
            $params + ['b' => $before],
        );
        $next = count($rows) > 20 ? (string) $rows[19]['id'] : null;

        return Response::success(array_map([self::class, 'present'], array_slice($rows, 0, 20)), 200, [
            'average'     => (int) $place['rating_count'] > 0 ? round((float) $place['rating_avg'], 1) : null,
            'count'       => (int) $place['rating_count'],
            'next_cursor' => $next,
        ]);
    }

    public function put(Request $request): Response
    {
        $place = $this->place((string) $request->param('id'));
        $data = Validator::validate($request->json(), ['rating' => 'required|integer|min:1|max:5', 'text' => 'nullable|string|max:1000']);
        $text = isset($data['text']) && trim($data['text']) !== '' ? trim($data['text']) : null;
        // Brief §5 : un avis contenant un lien ou un nom de domaine attend un modérateur ; la note compte tout de suite
        $status = ContentFilter::check($text) === null && !ContentFilter::hasLink($text) ? 'visible' : 'pending';
        $userId = (int) $request->attributes['user_id'];

        $isNew = self::save($place, $userId, $data['rating'], $text, $status);
        if ($isNew && $place['id'] !== null) {
            Events::dispatch('place.reviewed', ['user_id' => $userId, 'place_id' => (int) $place['id']]);
        }
        [$where, $params] = self::scope($place);
        $row = Database::fetch(
            "SELECT r.id, r.uid, r.rating, r.comment, r.status, r.created_at, u.uid AS user_uid, u.display_name, u.first_name, u.username, u.avatar_kind, u.avatar_value, u.status AS user_status
             FROM place_reviews r JOIN users u ON u.id = r.user_id WHERE {$where} AND r.user_id = :u",
            $params + ['u' => $userId],
        );

        return Response::success(self::present($row, true));
    }

    public function delete(Request $request): Response
    {
        $place = $this->place((string) $request->param('id'));
        [$where, $params] = self::scope($place);
        Database::execute("DELETE r FROM place_reviews r WHERE {$where} AND r.user_id = :u", $params + ['u' => $request->attributes['user_id']]);
        if ($place['id'] !== null) {
            self::recompute((int) $place['id']);
        }

        return Response::noContent();
    }

    /** Notes et avis laissés sur le téléphone avant la connexion ; un avis déjà publié depuis le compte n'est pas écrasé. */
    public function import(Request $request): Response
    {
        $json = $request->json();
        $items = $json['items'] ?? null;
        if ($items === null && is_array($json['ratings'] ?? null) && !array_is_list($json['ratings'])) {
            $items = array_map(static fn ($k, $r) => ['key' => (string) $k, 'rating' => $r], array_keys($json['ratings']), $json['ratings']);
        }
        if (!is_array($items) || !array_is_list($items) || count($items) > 500) {
            throw new ValidationException(['items' => 'Liste { key, rating, text } attendue (500 maximum).']);
        }
        $userId = (int) $request->attributes['user_id'];
        $imported = $skipped = 0;
        foreach ($items as $item) {
            $key = is_array($item) && is_string($item['key'] ?? null) ? $item['key'] : null;
            $rating = is_array($item) ? ($item['rating'] ?? null) : null;
            if ($key === null || $key === '' || mb_strlen($key) > 200 || !is_int($rating) || $rating < 1 || $rating > 5) {
                $skipped++;
                continue;
            }
            $place = $this->place($key);
            [$where, $params] = self::scope($place);
            if (Database::fetch("SELECT 1 FROM place_reviews r WHERE {$where} AND r.user_id = :u", $params + ['u' => $userId])) {
                $skipped++;
                continue;
            }
            $text = is_string($item['text'] ?? null) && trim($item['text']) !== '' ? mb_substr(trim($item['text']), 0, 1000) : null;
            $status = ContentFilter::check($text) === null && !ContentFilter::hasLink($text) ? 'visible' : 'pending';
            self::save($place, $userId, $rating, $text, $status);
            $imported++;
        }

        return Response::success(['imported' => $imported, 'skipped' => $skipped]);
    }

    public static function present(array $r, bool $mine = false): array
    {
        return [
            'id'         => $r['uid'],
            'rating'     => (int) $r['rating'],
            // Texte en attente de modération : visible seulement par son auteur
            'text'       => $r['status'] === 'visible' || $mine ? $r['comment'] : null,
            'author'     => UserRepository::presentAuthor([
                'uid' => $r['user_uid'], 'display_name' => $r['first_name'] ?? $r['display_name'], 'username' => $r['username'],
                'avatar_kind' => $r['avatar_kind'], 'avatar_value' => $r['avatar_value'], 'status' => $r['user_status'],
            ]),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
        ];
    }

    /** @return bool true si l'avis est nouveau */
    private static function save(array $place, int $userId, int $rating, ?string $text, string $status): bool
    {
        return Database::transaction(static function () use ($place, $userId, $rating, $text, $status): bool {
            $now = gmdate('Y-m-d H:i:s');
            $n = Database::execute(
                'INSERT INTO place_reviews (uid, place_id, place_key, user_id, rating, comment, status, created_at, updated_at) VALUES (:uid, :p, :k, :u, :r, :c, :s, :n1, :n2)
                 ON DUPLICATE KEY UPDATE rating = :r2, comment = :c2, status = :s2, updated_at = :n3',
                ['uid' => Crypto::uuid4(), 'p' => $place['id'], 'k' => $place['id'] === null ? $place['key'] : null, 'u' => $userId, 'r' => $rating, 'c' => $text,
                 's' => $status, 'n1' => $now, 'n2' => $now, 'r2' => $rating, 'c2' => $text, 's2' => $status, 'n3' => $now],
            );
            if ($place['id'] !== null) {
                self::recompute((int) $place['id']);
            }

            return $n === 1;
        });
    }

    /** La note compte tout de suite, même si le texte attend la modération. */
    public static function recompute(int $placeId): void
    {
        Database::execute(
            "UPDATE places p SET
                rating_count = (SELECT COUNT(*) FROM place_reviews r WHERE r.place_id = p.id AND r.status IN ('visible', 'pending')),
                rating_avg   = (SELECT ROUND(AVG(rating), 1) FROM place_reviews r WHERE r.place_id = p.id AND r.status IN ('visible', 'pending'))
             WHERE p.id = :p",
            ['p' => $placeId],
        );
    }

    /**
     * Lieu désigné par un UUID du back ou une clé de l'app : ['id' => id du lieu ou null, 'key' => clé, 'rating_avg', 'rating_count'].
     * Un UUID inconnu reste une erreur 404 ; une clé de l'app inconnue est acceptée (lieu hors du back).
     */
    private function place(string $raw): array
    {
        $key = \App\Modules\Favorites\SpecFavoriteController::key($raw);
        $uid = preg_match('/^[0-9a-f-]{36}$/i', $key) ? strtolower($key) : null;
        if ($uid === null && ($target = SpecFavoriteController::resolve($key)) !== null && $target[0] === 'place') {
            $uid = $target[1];
        }
        if ($uid !== null) {
            $p = Database::fetch("SELECT id, rating_avg, rating_count FROM places WHERE uid = :u AND status = 'published' AND deleted_at IS NULL", ['u' => $uid]);
            if ($p !== null) {
                return ['id' => (int) $p['id'], 'key' => $key] + $p;
            }
            if (preg_match('/^[0-9a-f-]{36}$/i', $key)) {
                throw new HttpException(404, 'place_not_found', 'Ce lieu n\'existe pas ou n\'est plus en ligne.');
            }
        }
        $stats = Database::fetch(
            "SELECT COUNT(*) AS n, ROUND(AVG(rating), 1) AS avg FROM place_reviews WHERE place_key = :k AND status IN ('visible', 'pending')",
            ['k' => $key],
        );

        return ['id' => null, 'key' => $key, 'rating_avg' => $stats['avg'] ?? null, 'rating_count' => (int) ($stats['n'] ?? 0)];
    }

    /** @return array{0: string, 1: array} condition SQL sur place_reviews r */
    private static function scope(array $place): array
    {
        return $place['id'] !== null ? ['r.place_id = :p', ['p' => $place['id']]] : ['r.place_key = :k', ['k' => $place['key']]];
    }
}
