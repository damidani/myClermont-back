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
        $result = [];
        foreach ((new EventRepository())->findManyByUid($ids) as $uid => $event) {
            $tags = array_filter(array_map('trim', explode(',', (string) $event['tags'])));
            $tags[] = $event['category_slug'];

            $result[$uid] = [
                'tags'    => array_values(array_unique($tags)),
                'summary' => EventPresenter::card($event),
            ];
        }

        return $result;
    }
}
