<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Database;

/**
 * File « À modérer » : tout ce qui attend une décision humaine, sur une seule page, avec un bouton par décision.
 *   - publications, commentaires et avis retenus par le filtre automatique (status = pending) ;
 *   - événements proposés par les utilisateurs ;
 *   - signalements ouverts (table reports) ;
 *   - espace commerçant : fiches et événements à valider, avis signalés par un commerçant, SIRET à vérifier.
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

    /** Files de l'espace commerçant => rôles autorisés en plus d'admin */
    private const PRO = ['pro_place' => ['editor'], 'pro_review' => ['moderator'], 'pro_story' => ['moderator'], 'pro_siret' => []];

    public static function allows(string $type, string $role): bool
    {
        return $role === 'admin' || in_array($role, self::TYPES[$type][4] ?? self::PRO[$type] ?? ($type === 'report' ? ['moderator'] : []), true);
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
                                FROM events e LEFT JOIN users u ON u.id = e.author_user_id
                                WHERE (e.status = 'pending' OR e.pro_status = 'en_revue') AND e.deleted_at IS NULL ORDER BY e.id LIMIT 100");
        }
        if (self::allows('report', $role)) {
            $out['report'] = $q("SELECT r.uid AS id, r.target_type, r.target_id, r.reason, r.details, r.created_at,
                                        COUNT(*) OVER (PARTITION BY r.target_type, r.target_id) AS reports_on_target
                                 FROM reports r WHERE r.resolved_at IS NULL ORDER BY r.created_at LIMIT 100");
        }

        if (self::allows('pro_place', $role)) {
            $out['pro_place'] = $q("SELECT p.uid AS id, p.name AS title, p.pro, p.pro_pending, p.status AS live, p.created_at, b.trade_name AS author, NULL AS user_id
                                    FROM places p JOIN pro_businesses b ON b.id = p.business_id WHERE p.pro_status = 'en_revue' ORDER BY p.updated_at LIMIT 100");
        }
        if (self::allows('pro_review', $role)) {
            $out['pro_review'] = $q("SELECT r.uid AS id, r.comment AS text, r.rating, r.reported_reason, r.reported_details, r.reported_at AS created_at, pl.name AS place, b.trade_name AS author
                                     FROM place_reviews r JOIN places pl ON pl.id = r.place_id JOIN pro_businesses b ON b.place_id = pl.id
                                     WHERE r.reported_at IS NOT NULL AND r.status <> 'hidden' ORDER BY r.reported_at LIMIT 100");
        }
        if (self::allows('pro_story', $role)) {
            // Contrôle a posteriori : stories en ligne ou programmées pas encore vues par la modération
            $out['pro_story'] = $q("SELECT s.uid AS id, s.media_kind, s.media_url, s.caption AS text, s.starts_at, s.created_at, b.trade_name AS author
                                    FROM pro_stories s JOIN pro_businesses b ON b.id = s.business_id
                                    WHERE s.reviewed_at IS NULL AND s.removed_at IS NULL AND s.expires_at > UTC_TIMESTAMP() ORDER BY s.starts_at LIMIT 100");
        }
        if (self::allows('pro_siret', $role)) {
            $out['pro_siret'] = $q("SELECT uid AS id, trade_name AS title, legal_name, siret, naf_code, created_at, trade_name AS author FROM pro_businesses
                                    WHERE verification = 'en_cours' AND deleted_at IS NULL ORDER BY id LIMIT 100");
        }

        return $out;
    }

    /**
     * Applique une décision. $action : publish | refuse | ban (refuse + bannit l'auteur) ; pour un signalement : remove | dismiss | ban.
     * @return string message de confirmation
     */
    public static function decide(string $type, string $id, string $action, array $admin, string $ip, string $reason = ''): string
    {
        if (!self::allows($type, (string) $admin['role'])) {
            throw new \DomainException('Action non autorisée pour ton rôle.');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($type === 'report') {
            return self::resolveReport($id, $action, $admin, $now, $ip);
        }
        if (isset(self::PRO[$type])) {
            $message = self::decidePro($type, $id, $action, trim($reason));
            Audit::log((int) $admin['id'], 'moderation.' . $type . '.' . $action, $ip, $type, $id, $reason !== '' ? ['reason' => $reason] : []);

            return $message;
        }
        // Événement d'un commerçant : son espace pro suit la décision (notification, motif de refus)
        if ($type === 'event' && ($e = Database::fetch('SELECT id, business_id FROM events WHERE slug = :s', ['s' => $id])) && $e['business_id'] !== null && $action !== 'ban') {
            $action === 'publish' ? \App\Modules\Pro\EventController::approve((int) $e['id']) : \App\Modules\Pro\EventController::reject((int) $e['id'], trim($reason));
            Audit::log((int) $admin['id'], 'moderation.' . $action, $ip, 'events', $id, $reason !== '' ? ['reason' => $reason] : []);
            CacheInvalidator::afterWrite('events', ['id' => $e['id']], null);

            return $action === 'publish' ? 'Publié : c\'est maintenant visible dans l\'app.' : 'Refusé : le commerçant est prévenu.';
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

    private static function decidePro(string $type, string $id, string $action, string $reason): string
    {
        $notify = static fn (?array $b, string $title, string $body, string $href) => $b ? \App\Modules\Pro\Pro::notify((int) $b['id'], 'moderation', $title, $body, $href) : null;
        if ($type === 'pro_place') {
            $p = Database::fetch('SELECT id, name, business_id FROM places WHERE uid = :u', ['u' => $id]) ?? throw new \DomainException('Fiche introuvable.');
            $b = Database::fetch('SELECT id FROM pro_businesses WHERE id = :b', ['b' => $p['business_id']]);
            if ($action === 'publish') {
                \App\Modules\Pro\PlaceController::approve((int) $p['id']);
                $notify($b, 'Ta fiche est en ligne', 'Elle apparaît maintenant sur la carte de l\'app My Clermont.', '/fiche');

                return 'Fiche publiée sur la carte.';
            }
            \App\Modules\Pro\PlaceController::reject((int) $p['id']);
            $notify($b, 'Ta fiche n\'a pas été validée', $reason ?: 'Elle ne respecte pas les règles de publication : corrige-la puis renvoie-la.', '/fiche');

            return 'Fiche refusée : le commerçant est prévenu.';
        }
        if ($type === 'pro_story') {
            \App\Modules\Pro\StoryController::moderate($id, $action === 'remove', $reason);

            return $action === 'remove' ? 'Story retirée : le commerçant est prévenu.' : 'Story validée.';
        }
        if ($type === 'pro_review') {
            $r = Database::fetch('SELECT r.id, r.place_id, b.id AS b_id FROM place_reviews r JOIN pro_businesses b ON b.place_id = r.place_id WHERE r.uid = :u', ['u' => $id])
                ?? throw new \DomainException('Avis introuvable.');
            $remove = $action === 'remove';
            Database::execute('UPDATE place_reviews SET status = IF(:rm = 1, \'hidden\', status), reported_at = NULL, reported_reason = NULL, reported_details = NULL WHERE id = :id',
                ['rm' => (int) $remove, 'id' => $r['id']]);
            \App\Modules\Places\ReviewController::recompute((int) $r['place_id']);
            $notify(['id' => $r['b_id']], $remove ? 'Avis retiré' : 'Avis maintenu', $remove ? 'L\'avis que tu as signalé a été retiré.'
                : ($reason ?: 'Après vérification, l\'avis que tu as signalé respecte les règles : il reste en ligne.'), '/avis');

            return $remove ? 'Avis retiré : le commerçant est prévenu.' : 'Avis maintenu : le commerçant est prévenu.';
        }
        // pro_siret
        $b = Database::fetch('SELECT id FROM pro_businesses WHERE uid = :u', ['u' => $id]) ?? throw new \DomainException('Établissement introuvable.');
        Database::execute('UPDATE pro_businesses SET verification = :v WHERE id = :id', ['v' => $action === 'publish' ? 'verifie' : 'a_verifier', 'id' => $b['id']]);
        $notify($b, $action === 'publish' ? 'SIRET vérifié' : 'SIRET à corriger', $action === 'publish' ? 'Ton établissement est vérifié.' : ($reason ?: 'Nous n\'avons pas pu vérifier ton SIRET : corrige-le dans les paramètres.'), '/parametres');

        return $action === 'publish' ? 'SIRET marqué comme vérifié.' : 'SIRET à corriger : le commerçant est prévenu.';
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
