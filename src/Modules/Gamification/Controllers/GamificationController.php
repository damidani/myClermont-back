<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Gamification\GamificationRepository;

final class GamificationController
{
    /**
     * GET /v1/badges — catalogue complet avec, pour chaque badge, la progression de l'utilisateur.
     */
    public function badges(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $repo = new GamificationRepository();
        $catalog = (array) Config::get('gamification.badges', []);

        $earned = array_column(
            Database::fetchAll('SELECT badge_code, earned_at FROM user_badges WHERE user_id = :u', ['u' => $userId]),
            'earned_at',
            'badge_code',
        );
        $stats = $repo->stats($userId, array_map(static fn (array $b) => $b['rule']['stat'], $catalog));

        $badges = [];
        foreach ($catalog as $code => $badge) {
            $target = (int) $badge['rule']['min'];
            $badges[] = GamificationRepository::presentBadge($code, $badge, $earned[$code] ?? null) + [
                'earned'   => isset($earned[$code]),
                'progress' => ['current' => min($stats[$badge['rule']['stat']] ?? 0, $target), 'target' => $target],
                'points'   => (int) $badge['points'],
            ];
        }

        return Response::success($badges);
    }

    /**
     * GET /v1/users/me/points?before=<id>&limit=30 — solde et historique (pagination par curseur).
     */
    public function points(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $query = $request->query();
        $limit = max(1, min(100, (int) ($query['limit'] ?? 30)));
        $before = isset($query['before']) ? (int) $query['before'] : null;

        $rows = (new GamificationRepository())->pointsHistory($userId, $limit, $before);
        $balance = Database::fetch('SELECT points FROM users WHERE id = :u', ['u' => $userId]);

        return Response::success(
            [
                'balance' => (int) ($balance['points'] ?? 0),
                'history' => array_map(static fn (array $r) => [
                    'id'         => (int) $r['id'],
                    'amount'     => (int) $r['amount'],
                    'reason'     => $r['reason'],
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
                ], $rows),
            ],
            200,
            ['next_before' => count($rows) === $limit ? (int) end($rows)['id'] : null],
        );
    }
}
