<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Content\ContentSources;
use App\Core\Config;

/**
 * Format JSON des annonces, calqué sur les écrans :
 *   carte  : photo, LOCATION / MEUBLÉ, « 690 € / mois », titre, « Centre-ville · Clermont-Ferrand »,
 *            44 m² · 2 p. · 1 ch., 3 premiers atouts, cœur
 *   fiche  : + photos, description, tous les équipements, revenus demandés, mention, bouton Contacter
 */
final class HousingPresenter
{
    public static function card(array $h, ?bool $isFavorite = null): array
    {
        $transactions = (array) Config::get('housing.transactions');
        $types = (array) Config::get('housing.property_types');

        return [
            'id'            => $h['uid'],
            'transaction'   => ['code' => $h['transaction'], 'label' => $transactions[$h['transaction']] ?? $h['transaction']],
            'property_type' => ['code' => $h['property_type'], 'label' => $types[$h['property_type']] ?? $h['property_type']],
            'title'         => $h['title'],
            'price'         => [
                'cents'  => (int) $h['price_cents'],
                'period' => $h['transaction'] === 'sale' ? null : 'month',
                'label'  => self::priceLabel((int) $h['price_cents'], $h['transaction'] !== 'sale'),
            ],
            'location' => [
                'district'     => $h['district_label'] ?? $h['neighborhood_name'],
                'city'         => $h['city'],
                'label'        => implode(' · ', array_filter([$h['district_label'] ?? $h['neighborhood_name'], $h['city']])),
                'neighborhood' => $h['neighborhood_id'] === null ? null : ['id' => (int) $h['neighborhood_id'], 'name' => $h['neighborhood_name']],
            ],
            'surface_m2'  => $h['surface_m2'] === null ? null : (float) $h['surface_m2'],
            'rooms'       => $h['rooms'] === null ? null : (int) $h['rooms'],
            'bedrooms'    => $h['bedrooms'] === null ? null : (int) $h['bedrooms'],
            'furnished'   => (bool) $h['furnished'],
            'features'    => self::features($h['features']),
            'cover_url'   => $h['cover_url'],
            'image_count' => (int) $h['image_count'],
            'source'      => ContentSources::present($h),
            'is_favorite' => $isFavorite,
            'published_at' => $h['published_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($h['published_at'] . ' UTC')),
        ];
    }

    public static function detail(array $h, array $images, ?bool $isFavorite = null): array
    {
        $isRent = $h['transaction'] !== 'sale';
        $ratio = (int) Config::get('housing.income_ratio', 3);

        return self::card($h, $isFavorite) + [
            'images'         => $images,
            'image_caption'  => $h['image_caption'],
            'description'    => $h['description'],
            'charges'        => $h['charges_cents'] === null ? null : [
                'cents'    => (int) $h['charges_cents'],
                'included' => $h['charges_included'] === null ? null : (bool) $h['charges_included'],
            ],
            'floor'          => $h['floor'] === null ? null : (int) $h['floor'],
            'energy_class'   => $h['energy_class'],
            'available_from' => $h['available_from'],
            'coordinates'    => $h['latitude'] === null ? null : ['latitude' => (float) $h['latitude'], 'longitude' => (float) $h['longitude'], 'approximate' => true],
            // Encart « Revenus souvent demandés » (absent pour une vente)
            'income_hint'    => $isRent ? [
                'ratio'         => $ratio,
                'monthly_cents' => (int) $h['price_cents'] * $ratio,
                'label'         => '≈ ' . self::priceLabel((int) $h['price_cents'] * $ratio, true),
                'text'          => Config::get('housing.income_text'),
            ] : null,
            'notice'         => $h['notice'],
            'is_active'      => $h['status'] === 'published',
            // Bouton « Contacter » : formulaire myClermont ou lien vers l'annonce d'origine
            'contact'        => $h['status'] === 'published' ? ContentSources::contact($h) : ['mode' => 'none', 'url' => null, 'label' => 'Annonce expirée'],
            'share_url'      => str_replace('{id}', $h['uid'], (string) Config::get('housing.share_url')),
        ];
    }

    public static function pin(array $p): array
    {
        return [
            'id'          => $p['uid'],
            'transaction' => $p['transaction'],
            'latitude'    => (float) $p['latitude'],
            'longitude'   => (float) $p['longitude'],
            'price_label' => self::priceLabel((int) $p['price_cents'], $p['transaction'] !== 'sale'),
        ];
    }

    /** 69000, true → « 690 € / mois » ; 24500000, false → « 245 000 € » */
    public static function priceLabel(int $cents, bool $monthly): string
    {
        $euros = $cents % 100 === 0
            ? number_format(intdiv($cents, 100), 0, ',', "\u{202F}")
            : number_format($cents / 100, 2, ',', "\u{202F}");

        // Espaces insécables : « 2 070 € / mois » ne se coupe jamais entre le nombre et « € »
        return $euros . "\u{00A0}€" . ($monthly ? ' / mois' : '');
    }

    /** @return list<array{code: string, label: string, icon: string}> */
    private static function features(?string $codes): array
    {
        $catalog = (array) Config::get('housing.features');
        $out = [];
        foreach (array_filter(explode(',', (string) $codes)) as $code) {
            if (isset($catalog[$code])) {
                $out[] = ['code' => $code, 'label' => $catalog[$code][0], 'icon' => $catalog[$code][1]];
            }
        }

        return $out;
    }
}
