<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Content\ContentSources;

final class EventRepository
{
    private const COLUMNS = 'e.id, e.uid, e.slug, e.title, e.description, e.image_url, e.image_caption, e.starts_at, e.ends_at,
                             e.venue_name, e.city, e.address, e.latitude, e.longitude, e.neighborhood_id,
                             e.price_label, e.price_min_cents, e.ticket_url, e.capacity, e.participants_count,
                             e.tags, e.notice, e.status, e.is_featured,
                             e.external_url,
                             c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon, c.color AS category_color, '
                          . ContentSources::SELECT;

    private const FROM = 'events e JOIN event_categories c ON c.id = e.category_id JOIN content_sources s ON s.id = e.source_id';

    /**
     * Agenda : événements publiés pas encore terminés (ceux en cours restent visibles), du plus proche au plus lointain.
     * Pagination par curseur (starts_at, id) : stable et rapide même sur des milliers d'événements.
     *
     * @param array{category?: ?string, from?: ?string, to?: ?string, neighborhood_id?: ?int, free?: bool, featured?: bool} $filters dates UTC
     * @param array{0: string, 1: int}|null $after curseur [starts_at, id]
     */
    public function upcoming(array $filters, int $limit, ?array $after): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE e.status = \'published\' AND e.ends_at >= :now';
        $params = ['now' => gmdate('Y-m-d H:i:s'), 'limit' => $limit];

        if (!empty($filters['category'])) {
            $sql .= ' AND c.slug = :category';
            $params['category'] = $filters['category'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND e.ends_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND e.starts_at < :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['neighborhood_id'])) {
            $sql .= ' AND e.neighborhood_id = :hood';
            $params['hood'] = $filters['neighborhood_id'];
        }
        if (!empty($filters['free'])) {
            $sql .= ' AND e.price_min_cents = 0';
        }
        if (!empty($filters['featured'])) {
            $sql .= ' AND e.is_featured = 1';
        }
        if ($after !== null) {
            $sql .= ' AND (e.starts_at > :after_date OR (e.starts_at = :after_date2 AND e.id > :after_id))';
            $params += ['after_date' => $after[0], 'after_date2' => $after[0], 'after_id' => $after[1]];
        }

        return Database::fetchAll($sql . ' ORDER BY e.starts_at, e.id LIMIT :limit', $params);
    }

    /** Recherche par uid (UUID) ou par slug (liens de partage). */
    public function find(string $idOrSlug, bool $publishedOnly = true): ?array
    {
        $column = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idOrSlug) ? 'e.uid' : 'e.slug';
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$column} = :v";
        if ($publishedOnly) {
            $sql .= ' AND e.status IN (\'published\', \'cancelled\')'; // un événement annulé reste consultable
        }

        return Database::fetch($sql, ['v' => $column === 'e.uid' ? strtolower($idOrSlug) : $idOrSlug]);
    }

    /** @return array<string, array> uid => ligne, pour une liste d'uids (favoris) */
    public function findManyByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        [$in, $params] = self::inClause('u', $uids);
        $rows = Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE e.uid IN ({$in}) AND e.status <> 'draft'", $params);

        return array_column($rows, null, 'uid');
    }

    public function schedule(int $eventId): array
    {
        return Database::fetchAll(
            'SELECT TIME_FORMAT(time, \'%H:%i\') AS time, label FROM event_schedule_items WHERE event_id = :e ORDER BY position, time',
            ['e' => $eventId],
        );
    }

    /**
     * Filtres de l'agenda : catégories avec le nombre d'événements à venir (« Sport 2 », « Musique 1 »).
     * Mis en cache quelques secondes : la requête est commune à tous les utilisateurs.
     */
    public function categoriesWithCounts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('events:categories'))) {
            return $cached;
        }

        $rows = Database::fetchAll(
            'SELECT c.slug, c.name, c.icon, c.color, COUNT(e.id) AS count
             FROM event_categories c
             LEFT JOIN events e ON e.category_id = c.id AND e.status = \'published\' AND e.ends_at >= :now
             GROUP BY c.id ORDER BY c.sort_order',
            ['now' => gmdate('Y-m-d H:i:s')],
        );
        $categories = array_map(static fn (array $r) => [
            'slug' => $r['slug'], 'name' => $r['name'], 'icon' => $r['icon'], 'color' => $r['color'], 'count' => (int) $r['count'],
        ], $rows);

        if (Cache::isInMemory()) {
            Cache::store()->set('events:categories', $categories, (int) Config::get('events.counts_cache_ttl', 60));
        }

        return $categories;
    }

    /** @return list<int> ids parmi $eventIds auxquels l'utilisateur participe */
    public function participatingIds(int $userId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        [$in, $params] = self::inClause('e', $eventIds);

        return array_map('intval', array_column(Database::fetchAll(
            "SELECT event_id FROM event_participants WHERE user_id = :u AND event_id IN ({$in})",
            $params + ['u' => $userId],
        ), 'event_id'));
    }

    /**
     * Inscription atomique : le compteur n'est incrémenté que si la ligne est créée
     * et si la capacité n'est pas atteinte. Retourne 'added' | 'already' | 'full'.
     */
    public function participate(int $eventId, int $userId): string
    {
        return Database::transaction(function () use ($eventId, $userId): string {
            // Verrou sur l'événement : deux inscriptions simultanées ne peuvent pas dépasser la capacité
            $event = Database::fetch('SELECT capacity, participants_count FROM events WHERE id = :e FOR UPDATE', ['e' => $eventId]);

            $inserted = Database::execute(
                'INSERT IGNORE INTO event_participants (event_id, user_id, created_at) VALUES (:e, :u, :d)',
                ['e' => $eventId, 'u' => $userId, 'd' => gmdate('Y-m-d H:i:s')],
            ) > 0;
            if (!$inserted) {
                return 'already';
            }
            if ($event['capacity'] !== null && (int) $event['participants_count'] >= (int) $event['capacity']) {
                Database::execute('DELETE FROM event_participants WHERE event_id = :e AND user_id = :u', ['e' => $eventId, 'u' => $userId]);
                return 'full';
            }
            Database::execute('UPDATE events SET participants_count = participants_count + 1 WHERE id = :e', ['e' => $eventId]);

            return 'added';
        });
    }

    public function cancelParticipation(int $eventId, int $userId): bool
    {
        return Database::transaction(function () use ($eventId, $userId): bool {
            $deleted = Database::execute(
                'DELETE FROM event_participants WHERE event_id = :e AND user_id = :u',
                ['e' => $eventId, 'u' => $userId],
            ) > 0;
            if ($deleted) {
                Database::execute(
                    'UPDATE events SET participants_count = GREATEST(participants_count, 1) - 1 WHERE id = :e',
                    ['e' => $eventId],
                );
            }
            return $deleted;
        });
    }

    public function participantsCount(int $eventId): int
    {
        return (int) (Database::fetch('SELECT participants_count FROM events WHERE id = :e', ['e' => $eventId])['participants_count'] ?? 0);
    }

    /** Événements auxquels l'utilisateur participe (à venir par défaut). */
    public function participations(int $userId, bool $past, int $limit): array
    {
        $order = $past ? 'DESC' : 'ASC';
        $cmp = $past ? '<' : '>=';

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM event_participants p JOIN events e ON e.id = p.event_id
             JOIN event_categories c ON c.id = e.category_id JOIN content_sources s ON s.id = e.source_id
             WHERE p.user_id = :u AND e.status <> \'draft\' AND e.ends_at ' . $cmp . " :now
             ORDER BY e.starts_at {$order} LIMIT :limit",
            ['u' => $userId, 'now' => gmdate('Y-m-d H:i:s'), 'limit' => $limit],
        );
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('events:categories');
        }
    }

    /** @return array{0: string, 1: array} */
    private static function inClause(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $placeholders[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }
}
