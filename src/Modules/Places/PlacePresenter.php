<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Content\ContentSources;
use App\Core\Config;

/**
 * Format JSON des lieux, calqué sur la fiche :
 * bandeau (badge, nom, accroche), ouvert/fermé, note, Favori / Y aller / Partager, description,
 * En chiffres, À ne pas manquer, Repères, Infos pratiques, Sur la carte, Le conseil My Clermont, Avis, Sources.
 */
final class PlacePresenter
{
    public static function card(array $p, ?int $distance = null, ?bool $isFavorite = null): array
    {
        return [
            'id'          => $p['uid'],
            'name'        => $p['name'],
            'tagline'     => $p['tagline'],
            'category'    => ['slug' => $p['category_slug'], 'name' => $p['category_name'], 'icon' => $p['category_icon'], 'color' => $p['category_color']],
            'cover_url'   => $p['cover_url'],
            'latitude'    => (float) $p['latitude'],
            'longitude'   => (float) $p['longitude'],
            'address'     => $p['address'],
            'distance'    => $distance === null ? null : ['meters' => $distance, 'label' => Geo::label($distance)],
            'open_status' => OpeningHours::status($p['opening_hours'], self::now()) + ['hours_label' => $p['hours_label']],
            'price_label' => $p['price_label'],
            'rating'      => self::rating($p),
            'is_partner'  => (bool) $p['is_partner'],
            'is_must_see' => (bool) $p['is_must_see'],
            'is_favorite' => $isFavorite,
        ];
    }

    public static function detail(array $p, array $reviews, ?bool $isFavorite, ?array $myReview, ?int $distance): array
    {
        $json = static fn (?string $v) => $v === null ? [] : (json_decode($v, true) ?: []);

        return self::card($p, $distance, $isFavorite) + [
            'badge'       => $p['badge_label'],
            'description' => $p['description'],
            'facts'       => $json($p['facts']),        // « En chiffres » [{icon, value, label}]
            'highlights'  => $json($p['highlights']),   // « À ne pas manquer » [{icon, title, text}]
            'timeline'    => $json($p['timeline']),     // « Repères » [{year, text}]
            'practical'   => array_values(array_filter([
                $p['hours_label'] ? ['icon' => 'clock', 'label' => 'Horaires', 'value' => $p['hours_label']] : null,
                $p['address'] ? ['icon' => 'pin', 'label' => 'Adresse', 'value' => trim($p['address'] . ', ' . trim(($p['postal_code'] ?? '') . ' ' . $p['city']))] : null,
                $p['access_label'] ? ['icon' => 'tram', 'label' => 'Accès', 'value' => $p['access_label']] : null,
                $p['parking_label'] ? ['icon' => 'parking', 'label' => 'Stationnement', 'value' => $p['parking_label']] : null,
                $p['price_label'] ? ['icon' => 'euro', 'label' => 'Tarif', 'value' => $p['price_label']] : null,
                $p['phone'] ? ['icon' => 'phone', 'label' => 'Téléphone', 'value' => $p['phone']] : null,
            ])),
            'phone'       => $p['phone'],
            'website_url' => $p['website_url'],
            'tip'         => $p['tip'],                 // « Le conseil My Clermont »
            'reviews'     => array_map(static fn (array $r) => [
                'id' => $r['uid'], 'rating' => (int) $r['rating'], 'comment' => $r['comment'], 'author' => $r['username'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
            ], $reviews),
            'my_review'   => $myReview === null ? null : ['rating' => (int) $myReview['rating'], 'comment' => $myReview['comment']],
            'external_reviews' => null,                 // « Avis Google · Non connectés » : prévu, pas branché
            'sources'     => $p['sources_label'],
            'source'      => ContentSources::present($p),
            // « Y aller avec le GPS My Clermont » : l'app ouvre Mobilité avec cette destination
            'directions'  => ['latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude'], 'label' => $p['name']],
            'share_url'   => str_replace('{id}', $p['uid'], (string) Config::get('places.share_url')),
        ];
    }

    /** Note My Clermont (avis) prioritaire, sinon note indicative signalée comme telle. */
    public static function rating(array $p): ?array
    {
        if ((int) $p['rating_count'] > 0) {
            return ['value' => (float) $p['rating_avg'], 'count' => (int) $p['rating_count'], 'is_verified' => true, 'label' => $p['rating_count'] . ' avis'];
        }
        if ($p['display_rating'] !== null) {
            return ['value' => (float) $p['display_rating'], 'count' => 0, 'is_verified' => false, 'label' => $p['display_rating_label'] ?? 'note indicative'];
        }

        return null;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone((string) Config::get('places.timezone', 'Europe/Paris')));
    }
}
