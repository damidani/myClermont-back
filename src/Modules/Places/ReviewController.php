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
 *   GET    /places/{id}/reviews?cursor=   { items: [{ id, rating, text, author, createdAt }], average, count, nextCursor }
 *   PUT    /places/{id}/reviews/me        { rating, text? }  → Review
 *   DELETE /places/{id}/reviews/me        204
 *   POST   /me/reviews/import             { ratings: { "<clé locale>": note } }  → { imported, skipped }
 */
final class ReviewController
{
    public function index(Request $request): Response
    {
        $place = $this->place((string) $request->param('id'));
        $cursor = $request->query()['cursor'] ?? null;
        $before = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : PHP_INT_MAX;
        $rows = Database::fetchAll(
            "SELECT r.id, r.uid, r.rating, r.comment, r.status, r.created_at, u.uid AS user_uid, u.display_name, u.username, u.avatar_kind, u.avatar_value, u.status AS user_status
             FROM place_reviews r JOIN users u ON u.id = r.user_id
             WHERE r.place_id = :p AND r.status IN ('visible', 'pending') AND r.id < :b ORDER BY r.id DESC LIMIT 21",
            ['p' => $place['id'], 'b' => $before],
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

        $isNew = self::save((int) $place['id'], $userId, $data['rating'], $text, $status);
        if ($isNew) {
            Events::dispatch('place.reviewed', ['user_id' => $userId, 'place_id' => (int) $place['id']]);
        }
        $row = Database::fetch(
            'SELECT r.id, r.uid, r.rating, r.comment, r.status, r.created_at, u.uid AS user_uid, u.display_name, u.username, u.avatar_kind, u.avatar_value, u.status AS user_status
             FROM place_reviews r JOIN users u ON u.id = r.user_id WHERE r.place_id = :p AND r.user_id = :u',
            ['p' => $place['id'], 'u' => $userId],
        );

        return Response::success(self::present($row, true));
    }

    public function delete(Request $request): Response
    {
        $place = $this->place((string) $request->param('id'));
        Database::execute('DELETE FROM place_reviews WHERE place_id = :p AND user_id = :u', ['p' => $place['id'], 'u' => $request->attributes['user_id']]);
        self::recompute((int) $place['id']);

        return Response::noContent();
    }

    /** Notes laissées sur le téléphone avant la connexion. */
    public function import(Request $request): Response
    {
        $ratings = $request->json()['ratings'] ?? null;
        if (!is_array($ratings) || array_is_list($ratings) || count($ratings) > 500) {
            throw new ValidationException(['ratings' => 'Objet { clé locale : note } attendu (500 maximum).']);
        }
        $imported = $skipped = 0;
        foreach ($ratings as $key => $rating) {
            $target = SpecFavoriteController::resolve((string) $key);
            if ($target === null || $target[0] !== 'place' || !is_int($rating) || $rating < 1 || $rating > 5) {
                $skipped++;
                continue;
            }
            $placeId = (int) Database::fetch('SELECT id FROM places WHERE uid = :u', ['u' => $target[1]])['id'];
            // Un avis déjà publié depuis le compte n'est pas écrasé par une note locale
            if (Database::fetch('SELECT 1 FROM place_reviews WHERE place_id = :p AND user_id = :u', ['p' => $placeId, 'u' => $request->attributes['user_id']])) {
                $skipped++;
                continue;
            }
            self::save($placeId, (int) $request->attributes['user_id'], $rating, null, 'visible');
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
                'uid' => $r['user_uid'], 'display_name' => $r['display_name'], 'username' => $r['username'],
                'avatar_kind' => $r['avatar_kind'], 'avatar_value' => $r['avatar_value'], 'status' => $r['user_status'],
            ]),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
        ];
    }

    /** @return bool true si l'avis est nouveau */
    private static function save(int $placeId, int $userId, int $rating, ?string $text, string $status): bool
    {
        return Database::transaction(static function () use ($placeId, $userId, $rating, $text, $status): bool {
            $now = gmdate('Y-m-d H:i:s');
            $n = Database::execute(
                'INSERT INTO place_reviews (uid, place_id, user_id, rating, comment, status, created_at, updated_at) VALUES (:uid, :p, :u, :r, :c, :s, :n1, :n2)
                 ON DUPLICATE KEY UPDATE rating = :r2, comment = :c2, status = :s2, updated_at = :n3',
                ['uid' => Crypto::uuid4(), 'p' => $placeId, 'u' => $userId, 'r' => $rating, 'c' => $text, 's' => $status, 'n1' => $now, 'n2' => $now,
                 'r2' => $rating, 'c2' => $text, 's2' => $status, 'n3' => $now],
            );
            self::recompute($placeId);

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

    private function place(string $uid): array
    {
        $p = preg_match('/^[0-9a-f-]{36}$/i', $uid)
            ? Database::fetch("SELECT id, rating_avg, rating_count FROM places WHERE uid = :u AND status = 'published' AND deleted_at IS NULL", ['u' => strtolower($uid)])
            : null;

        return $p ?? throw new HttpException(404, 'place_not_found', 'Ce lieu n\'existe pas ou n\'est plus en ligne.');
    }
}
