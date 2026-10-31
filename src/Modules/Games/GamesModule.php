<?php

declare(strict_types=1);

namespace App\Modules\Games;

use App\Core\Database;
use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Mini-jeux du Profil (demande de l'app iOS sur le canal agents) : une partie par jour (heure de Paris), par jeu et par compte.
 *
 *   POST /games/{gameId}/scores        { score: 0–1000, day: "YYYY-MM-DD", playedAt }   → 201 { rank } ; 409 already_played
 *   GET  /games/{gameId}/leaderboard   ?period=day|week|all&limit=1–100                  → { items: [{ rank, displayName, score, isMe }], me }
 */
final class GamesModule implements Module
{
    public const GAMES = ['fondue', 'quiz-volcans', 'truffade', 'tram'];

    public function routes(Router $r): void
    {
        $r->get('/games/{gameId}/leaderboard', [self::class, 'leaderboard'], ['auth:optional']);
        $r->post('/games/{gameId}/scores', [self::class, 'score'], ['auth', 'throttle:post']);
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }

    public function score(Request $request): Response
    {
        $game = $this->game($request);
        $userId = (int) $request->attributes['user_id'];
        $j = $request->json();
        $paris = new \DateTimeZone('Europe/Paris');
        $today = (new \DateTimeImmutable('now', $paris))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday', $paris))->format('Y-m-d');
        $errors = [];
        if (!is_int($j['score'] ?? null) || $j['score'] < 0 || $j['score'] > 1000) {
            $errors['score'] = 'Le score doit être un entier entre 0 et 1 000.';
        }
        $day = $j['day'] ?? $today;
        if (!in_array($day, [$today, $yesterday], true)) {
            $errors['day'] = 'La partie doit dater d\'aujourd\'hui ou d\'hier.';
        }
        $playedAt = is_string($j['played_at'] ?? null) && ($t = strtotime($j['played_at'])) !== false ? min($t, time()) : time();
        if ($errors !== []) {
            throw new ValidationException($errors, 422);
        }
        try {
            Database::insert(
                'INSERT INTO game_scores (user_id, game_id, day, score, played_at, created_at) VALUES (:u, :g, :d, :s, :p, :c)',
                ['u' => $userId, 'g' => $game, 'd' => $day, 's' => $j['score'], 'p' => gmdate('Y-m-d H:i:s', $playedAt), 'c' => gmdate('Y-m-d H:i:s')],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                // Le premier score du jour est conservé, même avec des envois simultanés
                throw new HttpException(409, 'conflict', 'Tu as déjà joué aujourd\'hui, reviens demain !', ['reason' => 'already_played']);
            }
            throw $e;
        }
        $board = self::board($game, 'day', $day);

        return Response::json(['rank' => $board[$userId]['rank'] ?? null], 201);
    }

    public function leaderboard(Request $request): Response
    {
        $game = $this->game($request);
        $q = $request->query();
        $period = $q['period'] ?? 'day';
        if (!in_array($period, ['day', 'week', 'all'], true)) {
            throw new ValidationException(['period' => 'Période : day, week ou all.'], 422);
        }
        $limit = isset($q['limit']) && ctype_digit((string) $q['limit']) ? (int) $q['limit'] : 50;
        if ($limit < 1 || $limit > 100) {
            throw new ValidationException(['limit' => 'limit entre 1 et 100.'], 422);
        }
        $userId = isset($request->attributes['user_id']) ? (int) $request->attributes['user_id'] : null;
        $board = self::board($game, $period, (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'));
        $line = static fn (array $r) => ['rank' => $r['rank'], 'display_name' => $r['display_name'], 'score' => $r['score'], 'is_me' => $r['user_id'] === $userId];

        return Response::json(\App\Core\Contract::camel([
            'items' => array_map($line, array_slice(array_values($board), 0, $limit)),
            'me'    => $userId !== null && isset($board[$userId]) ? $line($board[$userId]) : null,
        ]));
    }

    /**
     * Classement : meilleur score de chaque joueur sur la période ; score décroissant puis partie la plus ancienne ; rangs sans trou.
     * @return array<int, array{rank: int, user_id: int, display_name: string, score: int}> indexé par user_id, dans l'ordre
     */
    public static function board(string $game, string $period, string $today): array
    {
        $where = 'g.game_id = :g';
        $params = ['g' => $game];
        if ($period === 'day') {
            $where .= ' AND g.day = :d';
            $params['d'] = $today;
        } elseif ($period === 'week') {
            $where .= ' AND g.day >= :d';
            $params['d'] = (new \DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
        }
        $rows = Database::fetchAll(
            "SELECT t.user_id, t.score, t.played_at, u.first_name, u.display_name, u.username, u.status FROM (
                SELECT g.user_id, g.score, g.played_at, ROW_NUMBER() OVER (PARTITION BY g.user_id ORDER BY g.score DESC, g.played_at) AS rn
                FROM game_scores g WHERE {$where}
             ) t JOIN users u ON u.id = t.user_id
             WHERE t.rn = 1 AND u.status = 'active' ORDER BY t.score DESC, t.played_at, t.user_id",
            $params,
        );
        $board = [];
        foreach ($rows as $i => $r) {
            $board[(int) $r['user_id']] = ['rank' => $i + 1, 'user_id' => (int) $r['user_id'], 'display_name' => self::name($r), 'score' => (int) $r['score']];
        }

        return $board;
    }

    /** Prénom + initiale du nom (« Camille R. »), jamais l'e-mail ; « Joueur MyClermont » à défaut */
    public static function name(array $u): string
    {
        $parts = preg_split('/\s+/u', trim((string) ($u['first_name'] ?: $u['display_name'] ?: '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Joueur MyClermont';
        }

        return $parts[0] . (count($parts) > 1 ? ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '');
    }

    private function game(Request $request): string
    {
        $game = (string) $request->param('gameId');
        if (!in_array($game, self::GAMES, true)) {
            throw new HttpException(404, 'not_found', 'Jeu inconnu.');
        }

        return $game;
    }
}
