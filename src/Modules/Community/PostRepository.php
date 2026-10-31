<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Cache\Cache;
use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Core\Database;

final class PostRepository
{
    private const COLUMNS = 'p.id, p.uid, p.user_id, p.type, p.body, p.badge_label, p.media_caption, p.likes_count, p.comments_count,
                             p.status, p.created_at, u.uid AS author_uid, u.username AS author_username, u.display_name AS author_display_name,
                             u.avatar_kind AS author_avatar_kind, u.avatar_value AS author_avatar_value, u.status AS author_status, n.name AS neighborhood_name';

    private const FROM = 'community_posts p JOIN users u ON u.id = p.user_id LEFT JOIN neighborhoods n ON n.id = p.neighborhood_id';

    /** Fil d'actualité, du plus récent au plus ancien (curseur = id). */
    public function feed(?array $types, ?int $neighborhoodId, ?int $authorId, int $limit, ?int $beforeId): array
    {
        $where = "p.status = 'visible'";
        $params = ['limit' => $limit];
        if ($types !== null) {
            $in = [];
            foreach ($types as $i => $type) {
                $in[] = ":t{$i}";
                $params["t{$i}"] = $type;
            }
            $where .= ' AND p.type IN (' . implode(', ', $in) . ')';
        }
        if ($neighborhoodId !== null) {
            $where .= ' AND p.neighborhood_id = :hood';
            $params['hood'] = $neighborhoodId;
        }
        if ($authorId !== null) {
            $where .= ' AND p.user_id = :author';
            $params['author'] = $authorId;
        }
        if ($beforeId !== null) {
            $where .= ' AND p.id < :before';
            $params['before'] = $beforeId;
        }

        return Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} ORDER BY p.id DESC LIMIT :limit", $params);
    }

    public function find(string $uid, bool $includePending = false): ?array
    {
        $status = $includePending ? "p.status IN ('visible','pending')" : "p.status = 'visible'";

        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE p.uid = :u AND {$status}", ['u' => strtolower($uid)]);
    }

    /** Compteurs des filtres (« Tout 3 · Discussions 1 · Sondages 1 »), cache court. */
    public function typeCounts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('community:counts'))) {
            return $cached;
        }
        $counts = array_fill_keys(array_keys((array) Config::get('community.types')), 0);
        foreach (Database::fetchAll("SELECT type, COUNT(*) AS n FROM community_posts WHERE status = 'visible' GROUP BY type") as $row) {
            $counts[$row['type']] = (int) $row['n'];
        }
        if (Cache::isInMemory()) {
            Cache::store()->set('community:counts', $counts, 30);
        }

        return $counts;
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('community:counts');
        }
    }

    /**
     * Données associées à une liste de posts, en 4 requêtes quel que soit le nombre de posts :
     * médias, options de sondage, likes et votes de l'utilisateur.
     *
     * @return array{media: array<int, list<array>>, options: array<int, list<array>>, liked: list<int>, votes: array<int, int>}
     */
    public function related(array $posts, ?int $userId): array
    {
        $ids = array_map('intval', array_column($posts, 'id'));
        $related = ['media' => [], 'options' => [], 'liked' => [], 'votes' => []];
        if ($ids === []) {
            return $related;
        }
        [$in, $params] = self::in($ids);

        foreach (Database::fetchAll(
            "SELECT pm.post_id, m.kind, m.mime, m.path, m.width, m.height FROM community_post_media pm
             JOIN media m ON m.id = pm.media_id WHERE pm.post_id IN ({$in}) ORDER BY pm.post_id, pm.position",
            $params,
        ) as $m) {
            $related['media'][(int) $m['post_id']][] = [
                'kind' => $m['kind'], 'mime' => $m['mime'], 'url' => MediaStore::url($m['path']),
                'width' => $m['width'] === null ? null : (int) $m['width'], 'height' => $m['height'] === null ? null : (int) $m['height'],
            ];
        }

        foreach (Database::fetchAll(
            "SELECT id, post_id, label, votes_count FROM community_poll_options WHERE post_id IN ({$in}) ORDER BY post_id, position",
            $params,
        ) as $o) {
            $related['options'][(int) $o['post_id']][] = $o;
        }

        if ($userId !== null) {
            $related['liked'] = array_map('intval', array_column(
                Database::fetchAll("SELECT post_id FROM community_likes WHERE user_id = :u AND post_id IN ({$in})", $params + ['u' => $userId]),
                'post_id',
            ));
            foreach (Database::fetchAll("SELECT post_id, option_id FROM community_poll_votes WHERE user_id = :u AND post_id IN ({$in})", $params + ['u' => $userId]) as $v) {
                $related['votes'][(int) $v['post_id']] = (int) $v['option_id'];
            }
        }

        return $related;
    }

    public function create(int $userId, string $type, ?string $body, ?int $neighborhoodId, array $pollOptions, array $mediaIds, string $status = 'visible'): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return Database::transaction(function () use ($userId, $type, $body, $neighborhoodId, $pollOptions, $mediaIds, $now, $status): int {
            $id = Database::insert(
                'INSERT INTO community_posts (uid, user_id, type, body, neighborhood_id, status, created_at, updated_at)
                 VALUES (:uid, :u, :type, :body, :hood, :st, :c, :up)',
                ['uid' => \App\Security\Crypto::uuid4(), 'u' => $userId, 'type' => $type, 'body' => $body, 'hood' => $neighborhoodId, 'st' => $status, 'c' => $now, 'up' => $now],
            );
            foreach (array_values($pollOptions) as $pos => $label) {
                Database::execute('INSERT INTO community_poll_options (post_id, label, position) VALUES (:p, :l, :pos)', ['p' => $id, 'l' => $label, 'pos' => $pos]);
            }
            foreach (array_values($mediaIds) as $pos => $mediaId) {
                Database::execute('INSERT INTO community_post_media (post_id, media_id, position) VALUES (:p, :m, :pos)', ['p' => $id, 'm' => $mediaId, 'pos' => $pos]);
            }
            (new MediaStore())->attach($mediaIds);
            $this->forgetCounts();

            return $id;
        });
    }

    /** Suppression par l'auteur : statut "deleted" + suppression des fichiers médias. */
    public function delete(int $postId): void
    {
        $mediaIds = array_column(Database::fetchAll('SELECT media_id FROM community_post_media WHERE post_id = :p', ['p' => $postId]), 'media_id');
        Database::execute("UPDATE community_posts SET status = 'deleted', updated_at = :d WHERE id = :p", ['d' => gmdate('Y-m-d H:i:s'), 'p' => $postId]);
        (new MediaStore())->deleteByIds($mediaIds);
        $this->forgetCounts();
    }

    public function like(int $postId, int $userId): bool
    {
        return Database::transaction(function () use ($postId, $userId): bool {
            $added = Database::execute('INSERT IGNORE INTO community_likes (post_id, user_id, created_at) VALUES (:p, :u, :d)', ['p' => $postId, 'u' => $userId, 'd' => gmdate('Y-m-d H:i:s')]) > 0;
            if ($added) {
                Database::execute('UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = :p', ['p' => $postId]);
            }
            return $added;
        });
    }

    public function unlike(int $postId, int $userId): void
    {
        Database::transaction(function () use ($postId, $userId): void {
            if (Database::execute('DELETE FROM community_likes WHERE post_id = :p AND user_id = :u', ['p' => $postId, 'u' => $userId]) > 0) {
                Database::execute('UPDATE community_posts SET likes_count = GREATEST(likes_count, 1) - 1 WHERE id = :p', ['p' => $postId]);
            }
        });
    }

    /** Vote unique et définitif ; false si l'utilisateur avait déjà voté. */
    public function vote(int $postId, int $optionId, int $userId): bool
    {
        return Database::transaction(function () use ($postId, $optionId, $userId): bool {
            $added = Database::execute(
                'INSERT IGNORE INTO community_poll_votes (post_id, user_id, option_id, created_at) VALUES (:p, :u, :o, :d)',
                ['p' => $postId, 'u' => $userId, 'o' => $optionId, 'd' => gmdate('Y-m-d H:i:s')],
            ) > 0;
            if ($added) {
                Database::execute('UPDATE community_poll_options SET votes_count = votes_count + 1 WHERE id = :o', ['o' => $optionId]);
            }
            return $added;
        });
    }

    public function comments(int $postId, int $limit, ?int $afterId): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.uid, c.user_id, c.body, c.created_at, u.uid AS author_uid, u.username AS author_username, u.display_name AS author_display_name,
                    u.avatar_kind AS author_avatar_kind, u.avatar_value AS author_avatar_value, u.status AS author_status
             FROM community_comments c JOIN users u ON u.id = c.user_id
             WHERE c.post_id = :p AND c.status = 'visible' AND c.id > :after ORDER BY c.id LIMIT :limit",
            ['p' => $postId, 'after' => $afterId ?? 0, 'limit' => $limit],
        );
    }

    public function addComment(int $postId, int $userId, string $body, string $status = 'visible'): array
    {
        return Database::transaction(function () use ($postId, $userId, $body, $status): array {
            $uid = \App\Security\Crypto::uuid4();
            Database::execute(
                'INSERT INTO community_comments (uid, post_id, user_id, body, status, created_at) VALUES (:uid, :p, :u, :b, :st, :d)',
                ['uid' => $uid, 'p' => $postId, 'u' => $userId, 'b' => $body, 'st' => $status, 'd' => gmdate('Y-m-d H:i:s')],
            );
            if ($status === 'visible') {
                Database::execute('UPDATE community_posts SET comments_count = comments_count + 1 WHERE id = :p', ['p' => $postId]);
            }

            return Database::fetch(
                'SELECT c.id, c.uid, c.user_id, c.body, c.created_at, u.uid AS author_uid, u.username AS author_username, u.display_name AS author_display_name,
                        u.avatar_kind AS author_avatar_kind, u.avatar_value AS author_avatar_value, u.status AS author_status
                 FROM community_comments c JOIN users u ON u.id = c.user_id WHERE c.uid = :uid',
                ['uid' => $uid],
            );
        });
    }

    public function deleteComment(string $uid, int $userId): bool
    {
        return Database::transaction(function () use ($uid, $userId): bool {
            $comment = Database::fetch("SELECT id, post_id FROM community_comments WHERE uid = :u AND user_id = :user AND status = 'visible'", ['u' => strtolower($uid), 'user' => $userId]);
            if ($comment === null) {
                return false;
            }
            Database::execute("UPDATE community_comments SET status = 'deleted' WHERE id = :id", ['id' => $comment['id']]);
            Database::execute('UPDATE community_posts SET comments_count = GREATEST(comments_count, 1) - 1 WHERE id = :p', ['p' => $comment['post_id']]);

            return true;
        });
    }

    /** Signalement ; masque le post automatiquement au-delà du seuil. Retourne true si le post vient d'être masqué. */
    public function report(int $postId, int $userId, string $reason, ?string $details): bool
    {
        return Database::transaction(function () use ($postId, $userId, $reason, $details): bool {
            $added = Database::execute(
                'INSERT IGNORE INTO community_reports (post_id, user_id, reason, details, created_at) VALUES (:p, :u, :r, :d, :now)',
                ['p' => $postId, 'u' => $userId, 'r' => $reason, 'd' => $details, 'now' => gmdate('Y-m-d H:i:s')],
            ) > 0;
            if (!$added) {
                return false;
            }
            Database::execute('UPDATE community_posts SET reports_count = reports_count + 1 WHERE id = :p', ['p' => $postId]);
            $hidden = Database::execute(
                "UPDATE community_posts SET status = 'hidden' WHERE id = :p AND status = 'visible' AND reports_count >= :n",
                ['p' => $postId, 'n' => (int) Config::get('community.auto_hide_reports', 5)],
            ) > 0;
            if ($hidden) {
                $this->forgetCounts();
            }

            return $hidden;
        });
    }

    /** @return array{0: string, 1: array} */
    private static function in(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        return [implode(', ', $placeholders), $params];
    }
}
