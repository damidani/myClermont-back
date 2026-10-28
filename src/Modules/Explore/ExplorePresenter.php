<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Core\Config;

final class ExplorePresenter
{
    /** « Bonsoir, Alex » — calculé à l'heure de Clermont, pas à celle du téléphone. */
    public static function greeting(?string $username, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone((string) Config::get('explore.timezone', 'Europe/Paris')));
        $hour = (int) $now->format('G');
        $word = $hour >= 5 && $hour < 18 ? 'Bonjour' : 'Bonsoir';
        $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        $months = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return [
            'greeting'   => $username !== null ? "{$word}, {$username}" : $word,
            'date_label' => $days[(int) $now->format('w')] . ' ' . $now->format('j') . ' ' . $months[(int) $now->format('n')], // « mer. 28 oct. »
            'local_time' => $now->format(DATE_ATOM),
        ];
    }

    public static function story(array $s, ?array $viewed): array
    {
        return [
            'id'        => $s['uid'],
            'title'     => $s['title'],
            'subtitle'  => $s['subtitle'],
            'cover_url' => $s['cover_url'],
            'icon'      => $s['icon'],
            // null = non connecté (l'app gère alors le « vu » localement)
            'seen'      => $viewed === null ? null : isset($viewed[(int) $s['id']]) && $viewed[(int) $s['id']] >= $s['updated_at'],
        ];
    }

    public static function slide(array $s): array
    {
        return [
            'media_type'  => $s['media_type'],
            'media_url'   => $s['media_url'],
            'duration_ms' => (int) $s['duration_ms'],
            'title'       => $s['title'],
            'body'        => $s['body'],
            'caption'     => $s['caption'],
            'cta'         => ($link = Link::from($s['link_type'], $s['link_id'], $s['link_url'])) === null ? null : [
                'label' => $s['cta_label'] ?? 'Découvrir',
                'link'  => $link,
            ],
        ];
    }

    public static function highlight(array $h): array
    {
        return [
            'id'        => $h['uid'],
            'badge'     => $h['badge_label'],
            'tag'       => $h['tag_label'],
            'title'     => $h['title'],
            'subtitle'  => $h['subtitle'],
            'image_url' => $h['image_url'],
            'location'  => $h['location_label'],
            'link'      => Link::from($h['link_type'], $h['link_id'], $h['link_url']),
        ];
    }

    /** Carte d'offre (le code n'est jamais inclus ici). */
    public static function offer(array $o, ?bool $isFavorite = null): array
    {
        $stockLeft = $o['max_claims'] === null ? null : max(0, (int) $o['max_claims'] - (int) $o['claims_count']);

        return [
            'id'          => $o['uid'],
            'partner'     => $o['partner_name'],
            'title'       => $o['title'],
            'subtitle'    => $o['subtitle'],
            'discount'    => $o['discount_label'],
            'icon'        => $o['icon'],
            'image_url'   => $o['image_url'],
            'section'     => $o['section_slug'] === null ? null : ['slug' => $o['section_slug'], 'name' => $o['section_name']],
            'badge'       => $o['badge_label'],
            'ends_at'     => $o['ends_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($o['ends_at'] . ' UTC')),
            'stock_left'  => $stockLeft,
            'is_premium_only' => (bool) $o['premium_only'],
            'is_favorite' => $isFavorite,
        ];
    }

    public static function offerDetail(array $o, ?bool $isFavorite, ?array $myClaim): array
    {
        return self::offer($o, $isFavorite) + [
            'description'     => $o['description'],
            'conditions'      => $o['conditions'],
            'address'         => $o['address'],
            'coordinates'     => $o['latitude'] === null ? null : ['latitude' => (float) $o['latitude'], 'longitude' => (float) $o['longitude']],
            'redemption_mode' => $o['redemption_mode'],
            'members_only'    => (bool) $o['members_only'],
            'notice'          => $o['notice'],
            'is_active'       => $o['status'] === 'published' && ($o['ends_at'] === null || strtotime($o['ends_at'] . ' UTC') > time()),
            // Après « Profiter de l'offre » : code / écran à présenter
            'my_claim'        => $myClaim,
        ];
    }

    /** Ligne de « À découvrir ». */
    public static function item(array $i, ?bool $isFavorite = null): array
    {
        return [
            'id'        => $i['uid'],
            'section'   => ['slug' => $i['section_slug'], 'name' => $i['section_name']],   // « BONS PLANS », « AGENDA »…
            'title'     => $i['title'],
            'subtitle'  => $i['subtitle'],
            'icon'      => $i['icon'] ?? $i['section_icon'],
            'image_url' => $i['image_url'],
            'link'      => Link::from($i['link_type'], $i['link_id'], $i['link_url']),
            'is_favorite' => $isFavorite,
        ];
    }
}
