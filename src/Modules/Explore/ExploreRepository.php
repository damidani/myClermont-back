<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Core\Database;

final class ExploreRepository
{
    /** Contenu programmé : publié et dans sa période (starts_at / ends_at facultatifs). */
    private const LIVE = "status = 'published' AND (starts_at IS NULL OR starts_at <= :now_a) AND (ends_at IS NULL OR ends_at > :now_b)";

    public function sections(): array
    {
        return Database::fetchAll('SELECT id, slug, name, icon, is_chip FROM explore_sections ORDER BY sort_order');
    }

    public function stories(): array
    {
        return Database::fetchAll(
            'SELECT id, uid, title, subtitle, cover_url, icon, updated_at FROM explore_stories WHERE ' . self::LIVE . ' ORDER BY sort_order, id',
            self::now(),
        );
    }

    public function story(string $uid): ?array
    {
        return Database::fetch(
            'SELECT id, uid, title, subtitle, cover_url, icon, updated_at FROM explore_stories WHERE uid = :u AND ' . self::LIVE,
            ['u' => strtolower($uid)] + self::now(),
        );
    }

    public function slides(int $storyId): array
    {
        return Database::fetchAll('SELECT * FROM explore_story_slides WHERE story_id = :s ORDER BY position, id', ['s' => $storyId]);
    }

    /** @return array<int, string> story_id => date de dernière vue */
    public function viewedStories(int $userId): array
    {
        return array_column(Database::fetchAll('SELECT story_id, viewed_at FROM explore_story_views WHERE user_id = :u', ['u' => $userId]), 'viewed_at', 'story_id');
    }

    public function markStoryViewed(int $storyId, int $userId): void
    {
        Database::execute(
            'INSERT INTO explore_story_views (story_id, user_id, viewed_at) VALUES (:s, :u, :d) ON DUPLICATE KEY UPDATE viewed_at = :d2',
            ['s' => $storyId, 'u' => $userId, 'd' => gmdate('Y-m-d H:i:s'), 'd2' => gmdate('Y-m-d H:i:s')],
        );
    }

    public function highlights(): array
    {
        return Database::fetchAll('SELECT * FROM explore_highlights WHERE ' . self::LIVE . ' ORDER BY sort_order, id', self::now());
    }

    public function offers(?string $sectionSlug, int $limit): array
    {
        $sql = 'SELECT o.*, s.slug AS section_slug, s.name AS section_name FROM offers o LEFT JOIN explore_sections s ON s.id = o.section_id
                WHERE ' . self::live('o');
        $params = self::now() + ['limit' => $limit];
        if ($sectionSlug !== null) {
            $sql .= ' AND s.slug = :sec';
            $params['sec'] = $sectionSlug;
        }

        return Database::fetchAll($sql . ' ORDER BY o.sort_order, o.id LIMIT :limit', $params);
    }

    public function offer(string $uid): ?array
    {
        return Database::fetch(
            "SELECT o.*, s.slug AS section_slug, s.name AS section_name FROM offers o LEFT JOIN explore_sections s ON s.id = o.section_id
             WHERE o.uid = :u AND o.status IN ('published', 'archived')",
            ['u' => strtolower($uid)],
        );
    }

    public function offersByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$in, $params] = self::in($uids);

        return array_column(Database::fetchAll(
            "SELECT o.*, s.slug AS section_slug, s.name AS section_name FROM offers o LEFT JOIN explore_sections s ON s.id = o.section_id
             WHERE o.uid IN ({$in}) AND o.status <> 'draft'",
            $params,
        ), null, 'uid');
    }

    public function userClaims(int $offerId, int $userId): int
    {
        return (int) Database::fetch('SELECT COUNT(*) AS n FROM offer_claims WHERE offer_id = :o AND user_id = :u', ['o' => $offerId, 'u' => $userId])['n'];
    }

    /**
     * Utilisation d'une offre, atomique : le stock ne peut pas être dépassé même avec des demandes simultanées.
     * @return bool false si le stock est épuisé
     */
    public function claim(int $offerId, int $userId): bool
    {
        return Database::transaction(function () use ($offerId, $userId): bool {
            $updated = Database::execute(
                'UPDATE offers SET claims_count = claims_count + 1 WHERE id = :o AND (max_claims IS NULL OR claims_count < max_claims)',
                ['o' => $offerId],
            );
            if ($updated === 0) {
                return false;
            }
            Database::execute('INSERT INTO offer_claims (offer_id, user_id, claimed_at) VALUES (:o, :u, :d)', ['o' => $offerId, 'u' => $userId, 'd' => gmdate('Y-m-d H:i:s')]);

            return true;
        });
    }

    public function items(?string $sectionSlug, int $limit, int $offset): array
    {
        $sql = 'SELECT i.*, s.slug AS section_slug, s.name AS section_name, s.icon AS section_icon
                FROM explore_items i JOIN explore_sections s ON s.id = i.section_id
                WHERE ' . self::live('i');
        $params = self::now() + ['limit' => $limit, 'offset' => $offset];
        if ($sectionSlug !== null) {
            $sql .= ' AND s.slug = :sec';
            $params['sec'] = $sectionSlug;
        }

        return Database::fetchAll($sql . ' ORDER BY i.sort_order, i.id LIMIT :limit OFFSET :offset', $params);
    }

    public function itemsCount(?string $sectionSlug): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM explore_items i JOIN explore_sections s ON s.id = i.section_id WHERE ' . self::live('i');
        $params = self::now();
        if ($sectionSlug !== null) {
            $sql .= ' AND s.slug = :sec';
            $params['sec'] = $sectionSlug;
        }

        return (int) Database::fetch($sql, $params)['n'];
    }

    public function itemsByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$in, $params] = self::in($uids);

        return array_column(Database::fetchAll(
            "SELECT i.*, s.slug AS section_slug, s.name AS section_name, s.icon AS section_icon
             FROM explore_items i JOIN explore_sections s ON s.id = i.section_id WHERE i.uid IN ({$in}) AND i.status = 'published'",
            $params,
        ), null, 'uid');
    }

    /** Condition « publié et dans sa période » pour une table aliasée. */
    private static function live(string $alias): string
    {
        return "{$alias}.status = 'published' AND ({$alias}.starts_at IS NULL OR {$alias}.starts_at <= :now_a) AND ({$alias}.ends_at IS NULL OR {$alias}.ends_at > :now_b)";
    }

    private static function now(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return ['now_a' => $now, 'now_b' => $now];
    }

    /** @return array{0: string, 1: array} */
    private static function in(array $values): array
    {
        $in = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $in[] = ":v{$i}";
            $params["v{$i}"] = $v;
        }

        return [implode(', ', $in), $params];
    }
}
