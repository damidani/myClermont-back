<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Avis de la fiche (demande 4) : liste filtrée + résumé, réponse publique, signalement à l'équipe.
 * Un avis signalé reste visible dans l'app mais sort de la moyenne et du taux de réponse jusqu'à la décision.
 */
final class ReviewController
{
    public const REASONS = ['Faux avis ou concurrent', 'Propos injurieux ou haineux', 'Hors sujet', 'Données personnelles', 'Conflit d\'intérêts'];

    public function index(Request $request): Response
    {
        $place = Pro::place($request);
        $q = $request->query();
        [$limit, $offset] = Pro::page($request);
        $where = ["r.place_id = :p", "r.status IN ('visible', 'pending')"];
        $params = ['p' => $place['id']];
        $where[] = match ($q['filter'] ?? 'tous') {
            'a_repondre' => 'r.reply_text IS NULL AND r.reported_at IS NULL',
            'repondus'   => 'r.reply_text IS NOT NULL',
            'signales'   => 'r.reported_at IS NOT NULL',
            default      => '1 = 1',
        };
        if (is_string($q['q'] ?? null) && trim($q['q']) !== '') {
            $where[] = '(r.comment LIKE :q OR u.handle LIKE :q2)';
            $params['q'] = $params['q2'] = '%' . addcslashes(trim($q['q']), '%_\\') . '%';
        }
        $order = match ($q['sort'] ?? 'recent') { 'best' => 'r.rating DESC, r.id DESC', 'worst' => 'r.rating ASC, r.id DESC', default => 'r.id DESC' };
        $rows = Database::fetchAll(
            'SELECT r.*, u.handle, u.first_name, NULL AS last_name, u.display_name FROM place_reviews r LEFT JOIN users u ON u.id = r.user_id
             WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT " . ($limit + 1) . " OFFSET {$offset}", $params);

        return Response::success(array_map([self::class, 'present'], array_slice($rows, 0, $limit)), 200, [
            'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null,
            'summary'     => self::summary((int) $place['id']),
        ]);
    }

    public function reply(Request $request): Response
    {
        $review = $this->find($request);
        $text = trim((string) ($request->json()['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 1000) {
            throw new ValidationException(['text' => 'Ta réponse doit faire entre 1 et 1000 caractères.']);
        }
        if ($request->method === 'POST' && $review['reply_text'] !== null) {
            throw new HttpException(409, 'conflict', 'Tu as déjà répondu à cet avis : modifie ta réponse.', ['reason' => 'already_replied']);
        }
        Database::execute('UPDATE place_reviews SET reply_text = :t, reply_at = COALESCE(reply_at, :d) WHERE id = :id', ['t' => $text, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $review['id']]);
        if ($review['user_id'] !== null && $review['reply_text'] === null) {
            self::notifyReply((int) $review['user_id'], (int) $review['place_id'], $text);
        }

        return Response::success(self::present($this->find($request)));
    }

    public function deleteReply(Request $request): Response
    {
        $review = $this->find($request);
        Database::execute('UPDATE place_reviews SET reply_text = NULL, reply_at = NULL WHERE id = :id', ['id' => $review['id']]);

        return Response::success(self::present($this->find($request)));
    }

    public function report(Request $request): Response
    {
        $review = $this->find($request);
        $j = $request->json();
        if (!in_array($j['reason'] ?? null, self::REASONS, true)) {
            throw new ValidationException(['reason' => 'Choisis un motif de signalement.']);
        }
        $details = trim((string) ($j['details'] ?? ''));
        if (mb_strlen($details) > 500) {
            throw new ValidationException(['details' => '500 caractères au plus.']);
        }
        Database::execute('UPDATE place_reviews SET reported_reason = :r, reported_details = :d, reported_at = :at WHERE id = :id',
            ['r' => $j['reason'], 'd' => $details ?: null, 'at' => gmdate('Y-m-d H:i:s'), 'id' => $review['id']]);
        \App\Modules\Places\ReviewController::recompute((int) $review['place_id']);

        return Response::success(self::present($this->find($request)));
    }

    public function unreport(Request $request): Response
    {
        $review = $this->find($request);
        Database::execute('UPDATE place_reviews SET reported_reason = NULL, reported_details = NULL, reported_at = NULL WHERE id = :id', ['id' => $review['id']]);
        \App\Modules\Places\ReviewController::recompute((int) $review['place_id']);

        return Response::success(self::present($this->find($request)));
    }

    public static function present(array $r): array
    {
        return [
            'id' => $r['uid'], 'author' => \App\Modules\Account\Handles::display($r['handle'] ?? null, Pro::shortName($r['first_name'] ?? null, $r['last_name'] ?? null, $r['display_name'] ?? null)), 'rating' => (int) $r['rating'],
            'text' => (string) $r['comment'], 'created_at' => Pro::iso($r['created_at']),
            'reply' => $r['reply_text'] !== null ? ['text' => $r['reply_text'], 'created_at' => Pro::iso($r['reply_at'])] : null,
            'reported' => $r['reported_at'] !== null ? ['reason' => $r['reported_reason'], 'created_at' => Pro::iso($r['reported_at'])] : null,
        ];
    }

    public static function summary(int $placeId): array
    {
        $rows = Database::fetchAll("SELECT rating, reply_text IS NOT NULL AS replied FROM place_reviews WHERE place_id = :p AND status IN ('visible', 'pending') AND reported_at IS NULL",
            ['p' => $placeId]);
        $dist = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
        $replied = 0;
        foreach ($rows as $r) {
            $dist[(string) $r['rating']]++;
            $replied += (int) $r['replied'];
        }
        $n = count($rows);

        return ['average' => $n ? round(array_sum(array_column($rows, 'rating')) / $n, 1) : 0, 'count' => $n, 'distribution' => $dist,
                'response_rate' => $n ? round($replied / $n, 3) : 0, 'to_answer' => $n - $replied];
    }

    /** review_replied (4890352c) : le commerçant a répondu à mon avis */
    private static function notifyReply(int $userId, int $placeId, string $text): void
    {
        $p = Database::fetch('SELECT ' . \App\Modules\Places\MapController::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $placeId]);
        if ($p === null) {
            return;
        }
        $spot = \App\Modules\Places\MapController::place($p);
        \App\Modules\Notifications\Notices::send($userId, 'review_replied', "💬 {$spot['name']} a répondu à ton avis", \App\Modules\Notifications\Notices::excerpt($text), [
            'type' => 'place', 'placeKey' => 'spot-' . $spot['id'], 'name' => $spot['name'], 'latitude' => $spot['latitude'], 'longitude' => $spot['longitude'], 'category' => $spot['category'],
        ]);
    }

    private function find(Request $request): array
    {
        return Database::fetch(
            "SELECT r.*, u.handle, u.first_name, NULL AS last_name, u.display_name FROM place_reviews r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.uid = :u AND r.place_id = :p AND r.status IN ('visible', 'pending')",
            ['u' => (string) $request->param('id'), 'p' => Pro::business($request)['place_id']],
        ) ?? throw new HttpException(404, 'not_found', 'Avis introuvable.');
    }
}
