<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

/**
 * Cartes AssistantCard de l'app, construites par le serveur à partir des résultats des outils
 * (jamais rédigées par le modèle) : { icon, tone, kicker, title, detail, badge, isPartner, link }.
 * tone remplace les couleurs : l'app garde sa palette (catégorie de lieu, type d'événement, module de Ma Ville).
 */
final class CardBuilder
{
    private const PLACE_ICONS = [
        'incontournable' => 'star.fill', 'evenement' => 'calendar', 'spot' => 'binoculars.fill', 'restaurant' => 'fork.knife',
        'fastFood' => 'takeoutbag.and.cup.and.straw.fill', 'cafe' => 'cup.and.saucer.fill', 'boulangerie' => 'birthday.cake.fill',
        'culture' => 'theatermasks.fill', 'nature' => 'leaf.fill', 'sport' => 'figure.run', 'carburant' => 'fuelpump.fill', 'lieu' => 'mappin.circle.fill',
    ];
    private const PLACE_LABELS = [
        'incontournable' => 'INCONTOURNABLE', 'spot' => 'SPOT NATURE', 'restaurant' => 'RESTAURANT', 'fastFood' => 'SUR LE POUCE', 'cafe' => 'CAFÉ',
        'boulangerie' => 'BOULANGERIE', 'culture' => 'CULTURE', 'nature' => 'NATURE', 'sport' => 'SPORT', 'carburant' => 'CARBURANT', 'lieu' => 'LIEU', 'evenement' => 'ÉVÉNEMENT',
    ];
    private const FUELS = ['gazole' => 'Gazole', 'e10' => 'SP95-E10', 'sp95' => 'SP95', 'sp98' => 'SP98', 'e85' => 'E85', 'gplc' => 'GPL'];

    /**
     * @param list<mixed> $refs références citées par le modèle (« event:… », « place:… »)
     * @param array<string, array> $seen résultats réellement renvoyés par les outils
     */
    public static function cards(array $refs, array $seen): array
    {
        $cards = [];
        foreach (array_unique(array_filter($refs, 'is_string')) as $ref) {
            if (!isset($seen[$ref]) || count($cards) >= 6) {
                continue; // référence inventée ou hors des résultats : ignorée
            }
            $card = self::card($seen[$ref]);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    private static function card(array $r): ?array
    {
        $base = ['badge' => null, 'is_partner' => false];

        return match ($r['type']) {
            'event' => $base + [
                'icon' => 'calendar', 'tone' => $r['kind'] ?? 'soiree', 'kicker' => self::when($r['starts_at']),
                'title' => $r['title'], 'detail' => $r['venue_name'], 'link' => ['type' => 'event', 'event_id' => $r['slug']],
            ],
            'place' => [
                'icon' => self::PLACE_ICONS[$r['spot']['category']] ?? 'mappin.circle.fill', 'tone' => $r['spot']['category'],
                'kicker' => (self::PLACE_LABELS[$r['spot']['category']] ?? 'LIEU') . ' · ' . self::distance($r['distance']),
                'title' => $r['spot']['name'], 'detail' => $r['spot']['subtitle'], 'badge' => $r['spot']['is_partner'] ? 'PARTENAIRE' : null,
                'is_partner' => $r['spot']['is_partner'], 'link' => ['type' => 'place', 'place' => $r['spot']],
            ],
            'fuel' => $base + [
                'icon' => 'fuelpump.fill', 'tone' => 'carburant',
                'kicker' => mb_strtoupper(self::FUELS[$r['fuel']] ?? $r['fuel']) . ' · ' . number_format((float) ($r['prices'][$r['fuel']]['price'] ?? 0), 3, ',', '') . ' €',
                'title' => $r['address'] ?: 'Station', 'detail' => trim($r['city'] . ' · ' . self::distance($r['distance']), ' ·'),
                'link' => ['type' => 'fuel_station', 'place' => [
                    'id' => 'fuel-' . $r['id'], 'name' => $r['address'] ?: 'Station', 'category' => 'carburant', 'latitude' => $r['latitude'], 'longitude' => $r['longitude'],
                    'subtitle' => $r['city'], 'phone' => null, 'website' => null, 'is_partner' => false, 'demo_name' => $r['address'], 'nature_kind' => null,
                ]],
            ],
            'deal' => $base + [
                'icon' => 'tag.fill', 'tone' => 'deals', 'kicker' => $r['exclusive_plus'] ? 'BON PLAN · MY CLERMONT+' : 'BON PLAN',
                'title' => $r['title'], 'detail' => $r['merchant'], 'badge' => $r['discount_label'], 'link' => ['type' => 'city', 'destination' => 'deals'],
            ],
            'job' => $base + [
                'icon' => 'briefcase.fill', 'tone' => 'jobs', 'kicker' => mb_strtoupper((string) $r['contract']) . ' · ' . $r['city'],
                'title' => $r['title'], 'detail' => $r['company_name'], 'link' => ['type' => 'city', 'destination' => 'jobs'],
            ],
            'housing' => $base + [
                'icon' => 'house.fill', 'tone' => 'housing',
                'kicker' => ['rent' => 'LOCATION', 'colocation' => 'COLOCATION', 'sale' => 'ACHAT'][$r['transaction']] ?? 'LOGEMENT',
                'title' => $r['title'], 'detail' => trim(($r['district_label'] ?? '') . ' · ' . number_format((int) $r['price_cents'] / 100, 0, ',', "\u{202F}") . ' €', ' ·'),
                'link' => ['type' => 'city', 'destination' => 'housing'],
            ],
            'service' => $base + [
                'icon' => 'wrench.and.screwdriver.fill', 'tone' => 'services', 'kicker' => mb_strtoupper((string) $r['category']),
                'title' => $r['name'], 'detail' => $r['price_label'], 'link' => ['type' => 'city', 'destination' => 'services'],
            ],
            default => null,
        };
    }

    /** « CE SOIR · 19 H 30 », « DEMAIN · 20 H », « SAM. 1 NOV. · 18 H », « EN COURS » */
    public static function when(string $utc, ?\DateTimeImmutable $now = null): string
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $now ??= new \DateTimeImmutable('now', $tz);
        $d = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $hour = (int) $d->format('i') === 0 ? $d->format('G') . ' H' : $d->format('G') . ' H ' . $d->format('i');
        if ($d <= $now) {
            return 'EN COURS';
        }
        $days = (int) $now->setTime(0, 0)->diff($d->setTime(0, 0))->format('%a');
        $day = match (true) {
            $days === 0 => (int) $d->format('G') >= 18 ? 'CE SOIR' : "AUJOURD'HUI",
            $days === 1 => 'DEMAIN',
            default => ['LUN.', 'MAR.', 'MER.', 'JEU.', 'VEN.', 'SAM.', 'DIM.'][(int) $d->format('N') - 1] . ' ' . $d->format('j') . ' '
                       . ['JANV.', 'FÉVR.', 'MARS', 'AVR.', 'MAI', 'JUIN', 'JUIL.', 'AOÛT', 'SEPT.', 'OCT.', 'NOV.', 'DÉC.'][(int) $d->format('n') - 1],
        };

        return $day . ' · ' . $hour;
    }

    private static function distance(int $m): string
    {
        return $m < 1000 ? 'à ' . (int) (round($m / 50) * 50) . ' m' : 'à ' . str_replace('.', ',', (string) round($m / 1000, 1)) . ' km';
    }
}
