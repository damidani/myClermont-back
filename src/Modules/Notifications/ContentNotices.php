<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Modules\Places\MapController;

/**
 * Notifications de contenu (4dc1de5e) : favoris (story, bon plan, événement d'un lieu suivi), décisions de modération
 * (publication et avis publiés ou retirés) et classement des jeux. Chacune porte un target complet pour le deep-link.
 */
final class ContentNotices
{
    /** Au plus une notification par personne, lieu et sorte dans cette fenêtre */
    private const FAVORITE_WINDOW = 6 * 3600;

    /** favorite_story | favorite_deal | favorite_event : aux personnes qui ont le lieu en favori */
    public static function favoritePlace(int $placeId, string $kind, string $title, string $body, array $target, ?string $image = null): int
    {
        $place = Database::fetch('SELECT ' . MapController::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id
                                  WHERE p.id = :id AND p.status = \'published\' AND p.deleted_at IS NULL', ['id' => $placeId]);
        if ($place === null) {
            return 0;
        }
        $spot = MapController::place($place);
        $target += ['placeKey' => 'spot-' . $spot['id'], 'name' => $spot['name'], 'latitude' => $spot['latitude'], 'longitude' => $spot['longitude'], 'category' => $spot['category']];
        $sent = 0;
        foreach (self::followers($place) as $userId) {
            $sent += Notices::send($userId, $kind, $title, $body, $target, array_filter([
                'once_per' => [$kind . ':' . $placeId, self::FAVORITE_WINDOW], 'image_url' => $image,
            ])) !== null ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Tâche favorites:notify (toutes les 5 min) : stories, bons plans et événements devenus visibles depuis le dernier passage.
     * @return array{stories: int, deals: int, events: int} notifications envoyées
     */
    public static function favoritesDue(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $out = ['stories' => 0, 'deals' => 0, 'events' => 0];
        $mark = static fn (string $table, int $id) => Database::execute("UPDATE {$table} SET favorites_notified_at = :d WHERE id = :id", ['d' => $now, 'id' => $id]);

        foreach (Database::fetchAll("SELECT s.id, s.uid, s.place_id, s.caption, s.media_url, s.media_kind FROM pro_stories s
                                     WHERE s.favorites_notified_at IS NULL AND s.removed_at IS NULL AND s.starts_at <= :n AND s.expires_at > :n2 AND s.place_id IS NOT NULL LIMIT 50",
                                     ['n' => $now, 'n2' => $now]) as $s) {
            $mark('pro_stories', (int) $s['id']);
            $name = self::placeName((int) $s['place_id']);
            $out['stories'] += self::favoritePlace((int) $s['place_id'], 'favorite_story', "📸 {$name} a publié une story",
                trim((string) $s['caption']) !== '' ? Notices::excerpt((string) $s['caption'], 80) : 'À voir avant qu\'elle disparaisse !',
                ['type' => 'place', 'id' => $s['uid']], $s['media_kind'] === 'image' ? $s['media_url'] : null);
        }
        foreach (Database::fetchAll("SELECT id, uid, place_id, title, discount_label, image_url FROM deals
                                     WHERE favorites_notified_at IS NULL AND status = 'published' AND deleted_at IS NULL AND place_id IS NOT NULL
                                       AND (starts_at IS NULL OR starts_at <= :n) AND (expires_at IS NULL OR expires_at > :n2) LIMIT 50", ['n' => $now, 'n2' => $now]) as $d) {
            $mark('deals', (int) $d['id']);
            $name = self::placeName((int) $d['place_id']);
            $out['deals'] += self::favoritePlace((int) $d['place_id'], 'favorite_deal', "🏷️ Nouveau bon plan chez {$name}",
                trim(($d['discount_label'] ? $d['discount_label'] . ' · ' : '') . $d['title']), ['type' => 'deal', 'id' => $d['uid']], $d['image_url']);
        }
        foreach (Database::fetchAll("SELECT id, slug, place_id, title, starts_at, image_url FROM events
                                     WHERE favorites_notified_at IS NULL AND status = 'published' AND deleted_at IS NULL AND place_id IS NOT NULL
                                       AND COALESCE(ends_at, starts_at) > :n LIMIT 50", ['n' => $now]) as $e) {
            $mark('events', (int) $e['id']);
            $name = self::placeName((int) $e['place_id']);
            $when = (new \DateTimeImmutable($e['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'));
            $out['events'] += self::favoritePlace((int) $e['place_id'], 'favorite_event', "📅 {$name} : {$e['title']}",
                'Le ' . $when->format('d/m') . ' à ' . $when->format('H:i') . '. Ajoute-le à ton agenda !', ['type' => 'event', 'id' => $e['slug']], $e['image_url']);
        }

        return $out;
    }

    private static function placeName(int $placeId): string
    {
        return (string) (Database::fetch('SELECT COALESCE(demo_name, name) AS n FROM places WHERE id = :id', ['id' => $placeId])['n'] ?? 'Un lieu suivi');
    }

    /** Décision de modération sur une publication ou un avis : post_published / post_removed / review_published / review_removed */
    public static function moderated(string $table, array $row, bool $published, string $reason = ''): void
    {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        $why = trim($reason) !== '' ? 'Motif : ' . rtrim(trim($reason), '. ') . '.' : null;
        if ($table === 'community_posts') {
            $target = ['type' => 'post', 'id' => $row['uid']];
            $published
                ? Notices::send($userId, 'post_published', '✅ Ta publication est en ligne', Notices::excerpt((string) ($row['body'] ?? ''), 80), $target)
                : Notices::send($userId, 'post_removed', 'Ta publication a été retirée', $why ?? 'Elle ne respecte pas les règles de la communauté.', $target);
        } elseif ($table === 'place_reviews') {
            $target = ['type' => 'review', 'id' => $row['uid']];
            if ($row['place_id'] !== null && ($p = Database::fetch('SELECT ' . MapController::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $row['place_id']]))) {
                $spot = MapController::place($p);
                $target += ['placeKey' => 'spot-' . $spot['id'], 'name' => $spot['name'], 'latitude' => $spot['latitude'], 'longitude' => $spot['longitude'], 'category' => $spot['category']];
            } elseif (!empty($row['place_key'])) {
                $target['placeKey'] = $row['place_key'];
            }
            $name = $target['name'] ?? 'ce lieu';
            $published
                ? Notices::send($userId, 'review_published', "✅ Ton avis sur {$name} est en ligne", 'Merci pour ta contribution !', $target)
                : Notices::send($userId, 'review_removed', "Ton avis sur {$name} a été retiré", $why ?? 'Il ne respecte pas les règles des avis.', $target);
        }
    }

    /** leaderboard_passed : les joueurs du top 10 du jour que ce score vient de dépasser (5 au plus, une fois par jeu et par jour) */
    public static function leaderboardPassed(string $game, int $userId, array $board, string $day): void
    {
        $me = $board[$userId] ?? null;
        if ($me === null) {
            return;
        }
        $who = Notices::handle($userId);
        $n = 0;
        foreach ($board as $row) {
            if ($row['user_id'] === $userId || $row['score'] >= $me['score'] || $row['rank'] > 10 || $n >= 5) {
                continue;
            }
            Notices::send($row['user_id'], 'leaderboard_passed', "🏆 {$who} t'a dépassé·e au classement", "Tu es maintenant {$row['rank']}e du jour. Prends ta revanche demain !",
                ['type' => 'url', 'id' => $game, 'url' => 'myclermont://games/' . rawurlencode($game)],
                ['actor_id' => $userId, 'actor' => $who, 'once_per' => ['leaderboard_passed:' . $game . ':' . $day, 86400]]);
            $n++;
        }
    }

    /** Comptes qui suivent un lieu (favoris de l'app et favoris typés) */
    private static function followers(array $place): array
    {
        $keys = array_values(array_unique(array_filter([
            'spot-' . ($place['app_id'] ?? ''), 'spot-' . $place['uid'], $place['slug'] ? 'spot-' . $place['slug'] : null,
            'place-' . $place['name'], $place['app_id'], $place['uid'],
        ], static fn ($k) => is_string($k) && $k !== '' && $k !== 'spot-')));
        $in = implode(',', array_map(static fn ($i) => ':k' . $i, array_keys($keys)));
        $params = array_combine(array_map(static fn ($i) => 'k' . $i, array_keys($keys)), $keys) + ['uid' => $place['uid']];

        return array_map('intval', array_column(Database::fetchAll(
            "SELECT user_id FROM app_favorites WHERE fav_key IN ({$in})
             UNION SELECT user_id FROM favorites WHERE type = 'place' AND target_id = :uid LIMIT 5000", $params), 'user_id'));
    }
}
