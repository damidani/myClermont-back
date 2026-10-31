<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Database;

/**
 * File « À modérer » : tout ce qui attend une décision humaine, sur une seule page, avec un bouton par décision.
 *   - publications, commentaires et avis retenus par le filtre automatique (status = pending) ;
 *   - événements proposés par les utilisateurs ;
 *   - signalements ouverts (table reports).
 */
final class Moderation
{
    /** type => [table, colonne clé publique, statut publié, statut refusé, rôles autorisés en plus d'admin] */
    private const TYPES = [
        'post'    => ['community_posts', 'uid', 'visible', 'deleted', ['moderator']],
        'comment' => ['community_comments', 'uid', 'visible', 'deleted', ['moderator']],
        'review'  => ['place_reviews', 'uid', 'visible', 'hidden', ['moderator', 'editor']],
        'event'   => ['events', 'slug', 'published', 'rejected', ['editor']],
    ];

    public static function allows(string $type, string $role): bool
    {
        return $role === 'admin' || in_array($role, self::TYPES[$type][4] ?? ($type === 'report' ? ['moderator'] : []), true);
    }

    /** Nombre total d'éléments en attente visibles pour ce rôle (badge du menu). */
    public static function count(string $role): int
    {
        $n = 0;
        foreach (self::queue($role) as $items) {
            $n += count($items);
        }

        return $n;
    }

    /** @return array<string, list<array>> type => éléments en attente */
    public static function queue(string $role): array
    {
        $q = static function (string $sql): array {
            try {
                return Database::fetchAll($sql);
            } catch (\PDOException) {
                return []; // module non installé
            }
        };
        $who = "COALESCE(u.first_name, u.display_name, u.username, 'Compte supprimé')";
        $out = [];
        if (self::allows('post', $role)) {
            $out['post'] = $q("SELECT p.uid AS id, p.body AS text, p.type, p.created_at, {$who} AS author, u.id AS user_id
                               FROM community_posts p LEFT JOIN users u ON u.id = p.user_id WHERE p.status = 'pending' ORDER BY p.id LIMIT 100");
        }
        if (self::allows('comment', $role)) {
            $out['comment'] = $q("SELECT c.uid AS id, c.body AS text, c.created_at, {$who} AS author, u.id AS user_id
                                  FROM community_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.status = 'pending' ORDER BY c.id LIMIT 100");
        }
        if (self::allows('review', $role)) {
            $out['review'] = $q("SELECT r.uid AS id, r.comment AS text, r.rating, r.created_at, COALESCE(pl.name, r.place_key) AS place, {$who} AS author, u.id AS user_id
                                 FROM place_reviews r LEFT JOIN places pl ON pl.id = r.place_id LEFT JOIN users u ON u.id = r.user_id
                                 WHERE r.status = 'pending' ORDER BY r.id LIMIT 100");
        }
        if (self::allows('event', $role)) {
            $out['event'] = $q("SELECT e.slug AS id, e.title, e.summary AS text, e.venue_name, e.address, e.starts_at, e.created_at, {$who} AS author, u.id AS user_id
                                FROM events e LEFT JOIN users u ON u.id = e.author_user_id WHERE e.status = 'pending' AND e.deleted_at IS NULL ORDER BY e.id LIMIT 100");
        }
        if (self::allows('report', $role)) {
            $out['report'] = $q("SELECT r.uid AS id, r.target_type, r.target_id, r.reason, r.details, r.created_at,
                                        COUNT(*) OVER (PARTITION BY r.target_type, r.target_id) AS reports_on_target
                                 FROM reports r WHERE r.resolved_at IS NULL ORDER BY r.created_at LIMIT 100");
        }

        return $out;
    }

    /**
     * Applique une décision. $action : publish | refuse | ban (refuse + bannit l'auteur) ; pour un signalement : remove | dismiss | ban.
     * @return string message de confirmation
     */
    public static function decide(string $type, string $id, string $action, array $admin, string $ip): string
    {
        if (!self::allows($type, (string) $admin['role'])) {
            throw new \DomainException('Action non autorisée pour ton rôle.');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($type === 'report') {
            return self::resolveReport($id, $action, $admin, $now, $ip);
        }
        [$table, $key, $published, $refused] = self::TYPES[$type] ?? throw new \DomainException('Type inconnu.');
        $row = Database::fetch("SELECT * FROM {$table} WHERE {$key} = :k", ['k' => $id]) ?? throw new \DomainException('Élément introuvable (déjà traité ?).');

        $status = match ($action) {
            'publish' => $published,
            'refuse', 'ban' => $refused,
            default => throw new \DomainException('Action inconnue.'),
        };
        Database::execute("UPDATE {$table} SET status = :s" . (array_key_exists('updated_at', $row) ? ', updated_at = :d' : '') . " WHERE {$key} = :k",
            ['s' => $status, 'k' => $id] + (array_key_exists('updated_at', $row) ? ['d' => $now] : []));
        if ($type === 'comment' && $action === 'publish') {
            Database::execute('UPDATE community_posts SET comments_count = comments_count + 1 WHERE id = :p', ['p' => $row['post_id']]);
        }
        if ($type === 'review' && $row['place_id'] !== null) {
            \App\Modules\Places\ReviewController::recompute((int) $row['place_id']);
        }
        $userId = $row['user_id'] ?? $row['author_user_id'] ?? null;
        if ($action === 'ban' && $userId !== null) {
            self::ban((int) $userId);
        }
        Audit::log((int) $admin['id'], 'moderation.' . $action, $ip, $table, (string) $id, ['status' => [$row['status'] ?? null, $status]]);
        CacheInvalidator::afterWrite($table, $row, null);

        return match ($action) {
            'publish' => 'Publié : c\'est maintenant visible dans l\'app.',
            'refuse'  => 'Refusé : le contenu reste invisible.',
            'ban'     => 'Refusé et auteur banni (ses sessions sont fermées).',
        };
    }

    private static function resolveReport(string $id, string $action, array $admin, string $now, string $ip): string
    {
        $report = Database::fetch('SELECT * FROM reports WHERE uid = :u', ['u' => $id]) ?? throw new \DomainException('Signalement introuvable.');
        $resolution = match ($action) {
            'remove' => 'removed', 'dismiss' => 'dismissed', 'ban' => 'user_banned',
            default => throw new \DomainException('Action inconnue.'),
        };
        if ($resolution !== 'dismissed') {
            $target = self::target((string) $report['target_type'], (string) $report['target_id']);
            if ($target !== null) {
                [$table, $key, $hidden, $userColumn] = $target;
                $row = Database::fetch("SELECT * FROM {$table} WHERE {$key} = :k", ['k' => $report['target_id']]);
                if ($row !== null) {
                    Database::execute("UPDATE {$table} SET status = :s WHERE {$key} = :k", ['s' => $hidden, 'k' => $report['target_id']]);
                    if ($resolution === 'user_banned' && $row[$userColumn] !== null) {
                        self::ban((int) $row[$userColumn]);
                    }
                }
            } elseif ($report['target_type'] === 'user' && $resolution === 'user_banned') {
                $u = Database::fetch('SELECT id FROM users WHERE uid = :u', ['u' => $report['target_id']]);
                $u && self::ban((int) $u['id']);
            }
        }
        // Tous les signalements du même contenu sont clos ensemble
        Database::execute(
            'UPDATE reports SET resolution = :r, resolved_at = :d WHERE target_type = :t AND target_id = :id AND resolved_at IS NULL',
            ['r' => $resolution, 'd' => $now, 't' => $report['target_type'], 'id' => $report['target_id']],
        );
        Audit::log((int) $admin['id'], 'report.' . $resolution, $ip, 'reports', $id, []);

        return match ($resolution) {
            'removed'     => 'Contenu retiré de l\'app.',
            'dismissed'   => 'Signalement rejeté : le contenu reste en ligne.',
            'user_banned' => 'Contenu retiré et auteur banni.',
        };
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}|null table, clé, statut masqué, colonne auteur */
    private static function target(string $type, string $id): ?array
    {
        return match ($type) {
            'post'       => ['community_posts', 'uid', 'hidden', 'user_id'],
            'comment'    => ['community_comments', 'uid', 'deleted', 'user_id'],
            'review'     => ['place_reviews', 'uid', 'hidden', 'user_id'],
            'classified' => ['classifieds', 'uid', 'hidden', 'user_id'],
            'event'      => ['events', 'slug', 'cancelled', 'author_user_id'],
            default      => null,
        };
    }

    private static function ban(int $userId): void
    {
        Database::execute("UPDATE users SET status = 'banned', updated_at = :d WHERE id = :id AND role <> 'admin'", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
        (new \App\Repositories\RefreshTokenRepository())->revokeAllForUser($userId);
    }
}
