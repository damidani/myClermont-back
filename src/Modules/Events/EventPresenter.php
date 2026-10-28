<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Content\ContentSources;
use App\Core\Config;

/**
 * Format JSON des événements, calqué sur les écrans de l'app :
 *   carte de liste : image, catégorie, date, titre, lieu, horaire · durée, tarif, cœur
 *   fiche          : + à propos, programme, accès (carte), partage, mention, « Je participe »
 *
 * Le compte à rebours et les étiquettes « DEMAIN » / « DANS 2 JOURS » sont calculés par l'app
 * à partir de starts_at (et de l'en-tête X-Server-Time pour corriger l'horloge du téléphone).
 */
final class EventPresenter
{
    public static function card(array $e, array $flags = []): array
    {
        $tz = new \DateTimeZone((string) Config::get('events.timezone', 'Europe/Paris'));
        $start = (new \DateTimeImmutable($e['starts_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        $end = (new \DateTimeImmutable($e['ends_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        $now = time();

        return [
            'id'       => $e['uid'],
            'slug'     => $e['slug'],
            'title'    => $e['title'],
            'category' => [
                'slug'  => $e['category_slug'],
                'name'  => $e['category_name'],
                'icon'  => $e['category_icon'],
                'color' => $e['category_color'],
            ],
            'image' => $e['image_url'] === null ? null : [
                'url'     => $e['image_url'],
                'caption' => $e['image_caption'],
            ],
            'starts_at'        => $start->format(DATE_ATOM),   // 2026-10-28T19:00:00+01:00
            'ends_at'          => $end->format(DATE_ATOM),
            'duration_minutes' => intdiv($end->getTimestamp() - $start->getTimestamp(), 60),
            'state'            => match (true) {
                $e['status'] === 'cancelled'      => 'cancelled',
                $end->getTimestamp() < $now       => 'past',
                $start->getTimestamp() <= $now    => 'ongoing',
                default                           => 'upcoming',
            },
            'venue' => [
                'name'      => $e['venue_name'],
                'city'      => $e['city'],
                'address'   => $e['address'],
                'latitude'  => $e['latitude'] === null ? null : (float) $e['latitude'],
                'longitude' => $e['longitude'] === null ? null : (float) $e['longitude'],
            ],
            'price' => [
                'label'     => $e['price_label'],
                'min_cents' => $e['price_min_cents'] === null ? null : (int) $e['price_min_cents'],
                'is_free'   => $e['price_min_cents'] !== null && (int) $e['price_min_cents'] === 0,
            ],
            // Provenance : null = publié par myClermont ; sinon partenaire ou import (mention à afficher)
            'source'             => ContentSources::present($e),
            'external_url'       => $e['external_url'] ?? null,
            'is_featured'        => (bool) $e['is_featured'],
            'participants_count' => (int) $e['participants_count'],
            // null quand l'utilisateur n'est pas connecté
            'is_favorite'        => $flags['is_favorite'] ?? null,
            'is_participating'   => $flags['is_participating'] ?? null,
        ];
    }

    public static function detail(array $e, array $schedule, array $flags = []): array
    {
        $capacity = $e['capacity'] === null ? null : (int) $e['capacity'];

        return self::card($e, $flags) + [
            'description' => $e['description'],
            'programme'   => $schedule,
            'notice'      => $e['notice'],
            'ticket_url'  => $e['ticket_url'],
            'capacity'    => $capacity,
            'is_full'     => $capacity !== null && (int) $e['participants_count'] >= $capacity,
            'share_url'   => str_replace('{slug}', $e['slug'], (string) Config::get('events.share_url')),
            'calendar_url' => '/v1/events/' . $e['uid'] . '/calendar.ics',
        ];
    }
}
