<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Modules\Messaging\Push\Push;

/**
 * Notifications de thèmes (cron toutes les 5 min : php bin/console push:topics), spec v1 :
 *   events.weekly     vendredi 17 h : 3 sorties du week-end (1 par semaine)
 *   events.favorites  2 h avant un événement mis en favori (1 par événement)
 *   deals.new         nouveau bon plan publié (1 par jour au plus)
 */
final class TopicScheduler
{
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        return ['weekly' => $this->weekly($now), 'favorites' => $this->favorites(), 'deals' => $this->deals($now)];
    }

    private function weekly(\DateTimeImmutable $now): int
    {
        if ($now->format('N') !== '5' || (int) $now->format('G') !== 17) {
            return 0;
        }
        $saturday = $now->modify('saturday this week')->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $events = Database::fetchAll(
            "SELECT slug, title FROM events WHERE status = 'published' AND deleted_at IS NULL AND starts_at < :end AND ends_at >= :start
             ORDER BY is_featured DESC, participants_count DESC, starts_at LIMIT 3",
            ['start' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'end' => $saturday->modify('+2 days')->format('Y-m-d H:i:s')],
        );
        if ($events === []) {
            return 0;
        }
        $body = implode(' · ', array_column($events, 'title'));
        $week = $now->format('o-W');
        $n = 0;
        // Tous les appareils qui suivent le thème (avec ou sans compte), une fois par semaine
        foreach (Database::fetchAll("SELECT id FROM devices WHERE status = 'active' AND push_token IS NOT NULL AND (topics IS NULL OR FIND_IN_SET('events.weekly', topics))") as $d) {
            $n += (int) Push::toDevice((int) $d['id'], 'events.weekly', 'Ce week-end à Clermont', $body, [
                'deeplink' => 'myclermont://agenda', 'once_key' => "weekly:{$week}:d{$d['id']}",
            ]);
        }

        return $n;
    }

    private function favorites(): int
    {
        $rows = Database::fetchAll(
            "SELECT f.user_id, e.slug, e.title, e.venue_name FROM favorites f JOIN events e ON f.type = 'event' AND (e.slug = f.target_id OR e.uid = f.target_id)
             WHERE e.status = 'published' AND e.starts_at BETWEEN :from AND :to",
            ['from' => gmdate('Y-m-d H:i:s', time() + 110 * 60), 'to' => gmdate('Y-m-d H:i:s', time() + 125 * 60)],
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += (int) Push::toUser((int) $r['user_id'], 'events.favorites', $r['title'], 'Ça commence dans 2 h · ' . $r['venue_name'], [
                'deeplink' => 'myclermont://event/' . $r['slug'], 'once_key' => "fav:{$r['slug']}:u{$r['user_id']}", 'thread_id' => 'event:' . $r['slug'],
            ]);
        }

        return $n;
    }

    private function deals(\DateTimeImmutable $now): int
    {
        $deal = Database::fetch(
            "SELECT uid, title, merchant FROM deals WHERE status = 'published' AND deleted_at IS NULL AND created_at >= :since ORDER BY id DESC LIMIT 1",
            ['since' => gmdate('Y-m-d H:i:s', time() - 3600)],
        );
        if ($deal === null) {
            return 0;
        }
        $day = $now->format('Y-m-d');
        $n = 0;
        foreach (Database::fetchAll("SELECT id FROM devices WHERE status = 'active' AND push_token IS NOT NULL AND FIND_IN_SET('deals.new', topics)") as $d) {
            $n += (int) Push::toDevice((int) $d['id'], 'deals.new', 'Nouveau bon plan', $deal['merchant'] . ' : ' . $deal['title'], [
                'deeplink' => 'myclermont://deal/' . $deal['uid'], 'once_key' => "deals:{$day}:d{$d['id']}",
            ]);
        }

        return $n;
    }
}
