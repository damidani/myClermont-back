<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Content\ContentSources;
use App\Core\Config;
use App\Support\AppSchemas;

/**
 * Format JSON des professionnels, calqué sur les écrans « Services » :
 *   carte : icône, nom, catégorie, ville, étoiles + note, tarif, résumé, cœur
 *   fiche : bandeau, horaires, tarif, secteur, catégorie, à propos, prestations, zone d'intervention, avis, devis
 */
final class ServicePresenter
{
    /**
     * Service de la spec v1 : id, name, category, district, icon, description, specialties[], hours, price, rating, ratingCount, latitude, longitude.
     */
    public static function card(array $s, ?bool $isFavorite = null, array $extra = []): array
    {
        $rating = self::rating($s);
        $specialties = $s['services'] ?? null;
        $specialties = $specialties === null ? [] : (json_decode((string) $specialties, true) ?: []);
        $contact = ($s['status'] ?? 'published') === 'published' ? ContentSources::contact($s) : ['mode' => 'none', 'url' => null, 'label' => 'Fiche archivée'];

        return [
            'id'           => $s['uid'],
            'name'         => (string) $s['name'],
            // Brief iOS §11.4 : libellé (« Maison & travaux »), icône SF Symbols, textes jamais null, note 0 sans avis
            'category'     => (string) $s['category_name'],
            'district'     => (string) ($s['neighborhood_name'] ?? $s['city'] ?? ''),
            'icon'         => in_array($s['category_icon'], AppSchemas::ICONS, true) ? $s['category_icon'] : 'wrench.adjustable.fill',
            'description'  => (string) ($s['description'] ?? $s['summary'] ?? ''),
            'specialties'  => array_values(array_filter($specialties, 'is_string')),
            'hours'        => (string) ($s['hours_label'] ?? ''),
            'price'        => (string) ($s['price_label'] ?? ''),
            'rating'       => (float) ($rating['value'] ?? 0),
            'rating_count' => $rating['count'] ?? 0,
            // Prestataire sans adresse précise : centre de la métropole (zone d'intervention)
            'latitude'     => isset($s['latitude']) ? (float) $s['latitude'] : 45.7772,
            'longitude'    => isset($s['longitude']) ? (float) $s['longitude'] : 3.0870,
            // En plus de la spec
            'category_slug'  => $s['category_slug'],
            'category_name'  => $s['category_name'],
            'summary'        => $s['summary'],
            'logo_url'       => $s['logo_url'],
            'rating_is_verified' => $rating['is_verified'] ?? null,   // false = note indicative de la source
            'badge'          => $s['badge_label'],
            'contact'        => ['mode' => $contact['mode'], 'url' => $contact['url']],   // external = demande sur le site d'origine
            'source'         => ContentSources::present($s),
            'is_favorite'    => $isFavorite,
        ] + $extra;
    }

    public static function detail(array $s, array $reviews, ?bool $isFavorite, ?array $myQuote): array
    {
        return self::card($s, $isFavorite, [
            'cover_url'        => $s['cover_url'],
            'service_radius_km' => $s['service_radius_km'] === null ? null : (float) $s['service_radius_km'],
            'phone'            => $s['phone'],
            'website_url'      => $s['website_url'],
            'reviews'          => array_map(static fn (array $r) => [
                'id'         => $r['uid'],
                'rating'     => (int) $r['rating'],
                'text'       => $r['comment'],
                'author'     => ['display_name' => $r['display_name'] ?? $r['username']],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
            ], $reviews),
            'notice'           => $s['notice'],
            'my_request'       => $myQuote,
            'can_review'       => $myQuote !== null && $myQuote['status'] === 'sent',
            'share_url'        => str_replace('{id}', $s['uid'], (string) Config::get('services.share_url', 'https://myclermont.app/services/{id}')),
        ]);
    }

    /**
     * Note affichée : moyenne des avis vérifiés si elle existe, sinon note indicative (démo, import) clairement signalée.
     */
    public static function rating(array $s): ?array
    {
        if ((int) $s['rating_count'] > 0) {
            return [
                'value'       => (float) $s['rating_avg'],
                'count'       => (int) $s['rating_count'],
                'is_verified' => true,
                'label'       => (int) $s['rating_count'] . ' avis',
            ];
        }
        if ($s['display_rating'] !== null) {
            return [
                'value'       => (float) $s['display_rating'],
                'count'       => 0,
                'is_verified' => false,
                'label'       => $s['display_rating_label'] ?? 'note indicative',
            ];
        }

        return null;
    }
}
