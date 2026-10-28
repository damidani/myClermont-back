<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Content\ContentSources;
use App\Core\Config;

/**
 * Format JSON des professionnels, calqué sur les écrans « Services » :
 *   carte : icône, nom, catégorie, ville, étoiles + note, tarif, résumé, cœur
 *   fiche : bandeau, horaires, tarif, secteur, catégorie, à propos, prestations, zone d'intervention, avis, devis
 */
final class ServicePresenter
{
    public static function card(array $s, ?bool $isFavorite = null): array
    {
        return [
            'id'       => $s['uid'],
            'name'     => $s['name'],
            'category' => ['slug' => $s['category_slug'], 'name' => $s['category_name'], 'icon' => $s['category_icon']],
            'city'     => $s['city'],
            'neighborhood' => $s['neighborhood_name'],
            'summary'  => $s['summary'],
            'logo_url' => $s['logo_url'],
            'price_label' => $s['price_label'],
            'rating'   => self::rating($s),
            'badge'    => $s['badge_label'],
            'source'   => ContentSources::present($s),
            'is_favorite' => $isFavorite,
        ];
    }

    public static function detail(array $s, array $reviews, ?bool $isFavorite, ?array $myQuote): array
    {
        $services = $s['services'] === null ? [] : (json_decode((string) $s['services'], true) ?: []);
        $contact = $s['status'] === 'published' ? ContentSources::contact($s) : ['mode' => 'none', 'url' => null, 'label' => 'Fiche archivée'];

        return self::card($s, $isFavorite) + [
            'cover_url'     => $s['cover_url'],
            'hours_label'   => $s['hours_label'],
            'description'   => $s['description'],
            'services'      => array_values(array_filter($services, 'is_string')),
            'service_area'  => $s['latitude'] === null ? null : [
                'latitude'  => (float) $s['latitude'],
                'longitude' => (float) $s['longitude'],
                'radius_km' => $s['service_radius_km'] === null ? null : (float) $s['service_radius_km'],
            ],
            'phone'         => $s['phone'],
            'website_url'   => $s['website_url'],
            'reviews'       => array_map(static fn (array $r) => [
                'id'         => $r['uid'],
                'rating'     => (int) $r['rating'],
                'comment'    => $r['comment'],
                'author'     => $r['username'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
            ], $reviews),
            'notice'        => $s['notice'],
            // Bouton « Demander un devis » : formulaire myClermont ou lien externe (fiche importée)
            'quote'         => ['mode' => $contact['mode'] === 'form' ? 'form' : $contact['mode'], 'url' => $contact['url'],
                                'label' => $contact['mode'] === 'external' ? 'Demander un devis sur ' . $s['source_name'] : 'Demander un devis'],
            'my_quote_request' => $myQuote,
            // Un avis n'est possible qu'après une demande de devis transmise (avis « vérifié »)
            'can_review'    => $myQuote !== null && $myQuote['status'] === 'sent',
            'share_url'     => str_replace('{id}', $s['uid'], (string) Config::get('services.share_url', 'https://myclermont.fr/services/{id}')),
        ];
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
