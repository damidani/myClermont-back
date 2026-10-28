<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

use App\Core\Database;

final class FavoriteRepository
{
    /** true si ajouté, false s'il était déjà en favori */
    public function add(int $userId, string $type, string $targetId): bool
    {
        return Database::execute(
            'INSERT IGNORE INTO favorites (user_id, type, target_id, created_at) VALUES (:u, :t, :id, :d)',
            ['u' => $userId, 't' => $type, 'id' => $targetId, 'd' => gmdate('Y-m-d H:i:s')],
        ) > 0;
    }

    public function remove(int $userId, string $type, string $targetId): bool
    {
        return Database::execute(
            'DELETE FROM favorites WHERE user_id = :u AND type = :t AND target_id = :id',
            ['u' => $userId, 't' => $type, 'id' => $targetId],
        ) > 0;
    }

    /**
     * Pagination par curseur sur la date d'ajout (stable même si des favoris sont ajoutés entre deux pages).
     */
    public function list(int $userId, ?string $type, int $limit, ?string $before): array
    {
        $sql = 'SELECT type, target_id, created_at FROM favorites WHERE user_id = :u';
        $params = ['u' => $userId, 'limit' => $limit];
        if ($type !== null) {
            $sql .= ' AND type = :t';
            $params['t'] = $type;
        }
        if ($before !== null) {
            $sql .= ' AND created_at < :before';
            $params['before'] = $before;
        }

        return Database::fetchAll($sql . ' ORDER BY created_at DESC, target_id DESC LIMIT :limit', $params);
    }

    /** Identifiants mis en favori parmi une liste (pour afficher les cœurs sur un écran de liste). */
    public function filterFavorited(int $userId, string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = ['u' => $userId, 't' => $type];
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ":id{$i}";
            $params["id{$i}"] = (string) $id;
        }

        return array_column(Database::fetchAll(
            'SELECT target_id FROM favorites WHERE user_id = :u AND type = :t AND target_id IN (' . implode(', ', $placeholders) . ')',
            $params,
        ), 'target_id');
    }

    /** @return array<string, int> nombre de favoris par type */
    public function counts(int $userId): array
    {
        return array_map('intval', array_column(
            Database::fetchAll('SELECT type, COUNT(*) AS n FROM favorites WHERE user_id = :u GROUP BY type', ['u' => $userId]),
            'n',
            'type',
        ));
    }
}
