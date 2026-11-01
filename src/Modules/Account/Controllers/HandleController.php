<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Account\Handles;

/**
 * Pseudos publics (demandes 1a584b24 et 8417181e), compte obligatoire.
 *
 *   GET /users/search?q=sam&limit=8          → { items: [{ handle, avatar }], nextCursor: null }
 *   GET /users/handle-availability?handle=…  → { handle, available, reason: taken|invalid|reserved|null, suggestions }
 */
final class HandleController
{
    /** Autocomplétion après un « @ » : ceux qui commencent par q, puis ceux qui le contiennent ; comptes actifs, appelant exclu. */
    public function search(Request $request): Response
    {
        $q = mb_strtolower(trim(ltrim((string) ($request->query()['q'] ?? ''), '@')));
        $limit = max(1, min(8, (int) ($request->query()['limit'] ?? 8)));
        if ($q === '' || !preg_match('/^[a-z0-9._-]{1,30}$/', $q)) {
            return Response::success([], 200, ['next_cursor' => null]);
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $rows = Database::fetchAll(
            "SELECT handle, avatar_kind, avatar_value FROM users
             WHERE status = 'active' AND handle IS NOT NULL AND id <> :me AND handle LIKE :c
             ORDER BY handle LIKE :p DESC, CHAR_LENGTH(handle), handle LIMIT {$limit}",
            ['me' => $request->attributes['user_id'], 'c' => '%' . $like . '%', 'p' => $like . '%'],
        );
        $items = array_map(static fn (array $u) => [
            'handle' => $u['handle'],
            'avatar' => $u['avatar_kind'] === 'photo' ? ['kind' => 'photo', 'url' => $u['avatar_value']] : ['kind' => 'symbol', 'value' => $u['avatar_value'] ?? 'person.crop.circle.fill'],
        ], $rows);

        return Response::success($items, 200, ['next_cursor' => null]);
    }

    public function availability(Request $request): Response
    {
        return Response::success(Handles::availability((string) ($request->query()['handle'] ?? ''), (int) $request->attributes['user_id']));
    }
}
