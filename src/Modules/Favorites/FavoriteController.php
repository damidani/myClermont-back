<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

final class FavoriteController
{
    private FavoriteRepository $favorites;

    public function __construct()
    {
        $this->favorites = new FavoriteRepository();
    }

    /**
     * GET /v1/users/me/favorites?type=place&limit=30&before=<cursor>
     * GET /v1/users/me/favorites?type=place&ids=a,b,c   → uniquement les ids en favori parmi ceux-là
     */
    public function index(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $query = $request->query();
        $type = isset($query['type']) ? (string) $query['type'] : null;
        if ($type !== null) {
            $this->provider($type); // valide le type
        }

        if ($type !== null && isset($query['ids'])) {
            $ids = array_slice(array_filter(explode(',', (string) $query['ids'])), 0, 200);
            return Response::success(['type' => $type, 'favorited' => $this->favorites->filterFavorited($userId, $type, $ids)]);
        }

        $limit = max(1, min(100, (int) ($query['limit'] ?? 30)));
        $before = isset($query['before']) ? $this->decodeCursor((string) $query['before']) : null;
        $rows = $this->favorites->list($userId, $type, $limit, $before);

        // Un appel par type pour récupérer les aperçus (pas de N+1)
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['type']][] = $row['target_id'];
        }
        $details = [];
        foreach ($byType as $t => $ids) {
            $details[$t] = FavoriteTypes::get($t)?->find($ids) ?? [];
        }

        $items = array_map(static fn (array $row) => [
            'type'       => $row['type'],
            'id'         => $row['target_id'],
            'item'       => $details[$row['type']][$row['target_id']]['summary'] ?? null, // null = élément supprimé
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
        ], $rows);

        $last = end($rows);

        return Response::success($items, 200, [
            'counts'      => $this->favorites->counts($userId),
            'next_before' => count($rows) === $limit && $last ? base64_encode($last['created_at']) : null,
        ]);
    }

    /** PUT /v1/users/me/favorites/{type}/{id} — idempotent */
    public function add(Request $request): Response
    {
        [$type, $id] = [(string) $request->param('type'), (string) $request->param('id')];
        $provider = $this->provider($type);
        if (strlen($id) > 64) {
            throw new HttpException(404, 'favorite_target_not_found', 'Cet élément n\'existe pas.');
        }

        $found = $provider->find([$id]);
        if (!isset($found[$id])) {
            throw new HttpException(404, 'favorite_target_not_found', 'Cet élément n\'existe pas.');
        }

        $userId = $request->attributes['user_id'];
        if ($this->favorites->add($userId, $type, $id)) {
            Events::dispatch('favorite.added', [
                'user_id' => $userId, 'type' => $type, 'target_id' => $id, 'tags' => $found[$id]['tags'] ?? [],
            ]);
        }

        return Response::success(['type' => $type, 'id' => $id, 'favorited' => true]);
    }

    /** DELETE /v1/users/me/favorites/{type}/{id} — idempotent */
    public function remove(Request $request): Response
    {
        [$type, $id] = [(string) $request->param('type'), (string) $request->param('id')];
        $this->provider($type);

        if ($this->favorites->remove($request->attributes['user_id'], $type, $id)) {
            Events::dispatch('favorite.removed', ['user_id' => $request->attributes['user_id'], 'type' => $type, 'target_id' => $id]);
        }

        return Response::success(['type' => $type, 'id' => $id, 'favorited' => false]);
    }

    private function provider(string $type): FavoriteProvider
    {
        return FavoriteTypes::get($type)
            ?? throw new ValidationException(['type' => 'Type inconnu. Types disponibles : ' . (implode(', ', FavoriteTypes::all()) ?: 'aucun')]);
    }

    private function decodeCursor(string $cursor): string
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $decoded)) {
            throw new ValidationException(['before' => 'Curseur invalide.']);
        }

        return $decoded;
    }
}
