<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Favorites\FavoriteProvider;

/**
 * Rend les événements « favorisables » (le cœur des écrans Agenda).
 * Tags transmis à la gamification : la catégorie + les tags de l'événement
 * (ex. un événement tagué « gourmand » fait progresser le badge Gourmand).
 */
final class EventFavoriteProvider implements FavoriteProvider
{
    public function find(array $ids): array
    {
        // Spec v1 : l'identifiant d'un événement est son slug (« halloween-warhol-2026-10-31 ») ; les anciens favoris gardent l'uid
        $slugToUid = [];
        $slugs = array_values(array_filter($ids, static fn ($id) => !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/', (string) $id)));
        if ($slugs !== []) {
            $in = implode(', ', array_map(static fn ($i) => ':s' . $i, array_keys($slugs)));
            foreach (\App\Core\Database::fetchAll("SELECT uid, slug FROM events WHERE slug IN ({$in})", array_combine(array_map(static fn ($i) => 's' . $i, array_keys($slugs)), $slugs)) as $r) {
                $slugToUid[$r['uid']] = $r['slug'];
            }
        }
        $uids = array_merge(array_diff($ids, $slugs), array_keys($slugToUid));

        $result = [];
        foreach ((new EventRepository())->findManyByUid($uids) as $uid => $event) {
            $tags = array_filter(array_map('trim', explode(',', (string) $event['tags'])));
            $tags[] = $event['category_slug'];

            $result[$slugToUid[$uid] ?? $uid] = [
                'tags'    => array_values(array_unique($tags)),
                'summary' => EventPresenter::card($event),
            ];
        }

        return $result;
    }
}
