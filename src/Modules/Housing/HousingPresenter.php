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
    /**
     * Housing de la spec v1 : id, title, district, price, priceLabel, surface, rooms, bedrooms, type, furnished,
     * images[], features[], description, latitude, longitude (position arrondie à ~150 m : jamais l'adresse exacte).
     * price = loyer mensuel charges comprises (Location, Colocation) ou prix de vente (Achat), en euros.
     */
    public static function card(array $h, ?bool $isFavorite = null, array $images = []): array
    {
        $transactions = (array) Config::get('housing.transactions');
        $isRent = $h['transaction'] !== 'sale';
        $priceCents = (int) $h['price_cents'] + ($isRent && $h['charges_cents'] !== null && !$h['charges_included'] ? (int) $h['charges_cents'] : 0);

        return [
            'id'            => $h['uid'],
            'title'         => $h['title'],
            'district'      => $h['district_label'] ?? $h['neighborhood_name'],
            'price'         => $priceCents % 100 === 0 ? intdiv($priceCents, 100) : round($priceCents / 100, 2),
            'price_label'   => self::priceLabel($priceCents, $isRent),
            'surface'       => $h['surface_m2'] === null ? null : (float) $h['surface_m2'],
            'rooms'         => $h['rooms'] === null ? null : (int) $h['rooms'],
            'bedrooms'      => $h['bedrooms'] === null ? null : (int) $h['bedrooms'],
            'type'          => $transactions[$h['transaction']] ?? $h['transaction'],   // « Location », « Colocation », « Achat »
            'furnished'     => (bool) $h['furnished'],
            'images'        => $images !== [] ? $images : array_values(array_filter([$h['cover_url']])),
            'features'      => array_column(self::features($h['features']), 'label'),
            'description'   => $h['description'] ?? null,
            'latitude'      => $h['latitude'] === null ? null : round(round((float) $h['latitude'] / 0.0015) * 0.0015, 4),
            'longitude'     => $h['longitude'] === null ? null : round(round((float) $h['longitude'] / 0.002) * 0.002, 4),
            // En plus de la spec
            'city'          => $h['city'],
            'property_type' => $h['property_type'],
            'image_count'   => (int) ($h['image_count'] ?? count($images)),
            'contact'       => $h['status'] === 'published' ? ContentSources::contact($h) : ['mode' => 'none', 'url' => null, 'label' => 'Annonce expirée'],
            'source'        => ContentSources::present($h),
            'is_favorite'   => $isFavorite,
            'published_at'  => $h['published_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($h['published_at'] . ' UTC')),
        ];
    }

    public static function detail(array $h, array $images, ?bool $isFavorite = null): array
    {
        $isRent = $h['transaction'] !== 'sale';
        $ratio = (int) Config::get('housing.income_ratio', 3);

        return self::card($h, $isFavorite, $images) + [
            'image_caption'  => $h['image_caption'],
            'charges'        => $h['charges_cents'] === null ? null : [
                'cents'    => (int) $h['charges_cents'],
                'included' => $h['charges_included'] === null ? null : (bool) $h['charges_included'],
            ],
            'floor'          => $h['floor'] === null ? null : (int) $h['floor'],
            'energy_class'   => $h['energy_class'],
            'available_from' => $h['available_from'],
            'income_hint'    => $isRent ? [
                'ratio' => $ratio,
                'label' => '≈ ' . self::priceLabel((int) $h['price_cents'] * $ratio, true),
                'text'  => Config::get('housing.income_text'),
            ] : null,
            'notice'         => $h['notice'],
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
