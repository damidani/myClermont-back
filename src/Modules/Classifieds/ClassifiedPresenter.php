<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Content\ContentSources;
use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Modules\Community\PostPresenter;

/**
 * Format JSON des petites annonces, calqué sur les écrans :
 *   carte : visuel (photo, ou icône + couleur de catégorie), prix / « Gratuit », titre, lieu, état, cœur
 *   fiche : + catégorie, date, description, vendeur, conseils de sécurité, partage, Contacter
 */
final class ClassifiedPresenter
{
    public static function card(array $a, ?bool $isFavorite = null, ?int $viewerId = null): array
    {
        $conditions = (array) Config::get('classifieds.conditions');
        $cover = $a['cover'] === null ? null : (str_starts_with($a['cover'], 'http') ? $a['cover'] : MediaStore::url($a['cover']));

        return [
            'id'       => $a['uid'],
            'title'    => $a['title'],
            'category' => ['slug' => $a['category_slug'], 'name' => $a['category_name'], 'icon' => $a['category_icon'], 'color' => $a['category_color']],
            'price'    => [
                'cents'   => (int) $a['price_cents'],
                'is_free' => (bool) $a['is_free'],
                'label'   => $a['is_free'] ? 'Gratuit' : self::priceLabel((int) $a['price_cents']),
            ],
            'condition' => ['code' => $a['item_condition'], 'label' => $conditions[$a['item_condition']] ?? $a['item_condition']],
            'location'  => $a['location_label'],
            // Pas de photo : l'app affiche l'icône de catégorie sur fond de sa couleur (comme sur les maquettes)
            'cover_url' => $cover,
            'status'    => $a['status'],          // active | reserved | sold | expired
            'badge'     => $a['badge_label'],
            'source'    => ContentSources::present($a),
            'is_mine'   => $viewerId !== null && $a['user_id'] !== null && (int) $a['user_id'] === $viewerId,
            'is_favorite'  => $isFavorite,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($a['published_at'] . ' UTC')),
        ];
    }

    public static function detail(array $a, array $photos, ?bool $isFavorite, ?int $viewerId, ?array $sellerStats, ?string $conversationId): array
    {
        $isMine = $viewerId !== null && $a['user_id'] !== null && (int) $a['user_id'] === $viewerId;
        $available = in_array($a['status'], ['active', 'reserved'], true) && strtotime($a['expires_at'] . ' UTC') > time();

        $contact = match (true) {
            $isMine                                      => ['mode' => 'none', 'url' => null, 'label' => 'Ton annonce'],
            !$available                                  => ['mode' => 'none', 'url' => null, 'label' => $a['status'] === 'sold' ? 'Vendu' : 'Annonce expirée'],
            $a['user_id'] === null                       => ContentSources::contact($a)['mode'] === 'external'
                ? ['mode' => 'external', 'url' => $a['external_url'], 'label' => 'Contacter sur ' . $a['source_name']]
                : ['mode' => 'none', 'url' => null, 'label' => 'Contact indisponible'],
            default                                      => ['mode' => 'message', 'url' => null, 'label' => 'Contacter'],
        };

        return self::card($a, $isFavorite, $viewerId) + [
            'photos'      => $photos,
            'description' => $a['description'],
            'seller'      => $a['seller_uid'] === null ? null : PostPresenter::author($a['seller_uid'], $a['seller_username'], null, $isMine) + [
                'member_since'    => gmdate('Y-m-d', strtotime($a['seller_since'] . ' UTC')),
                'active_listings' => $sellerStats['active'] ?? 0,
                'sold_listings'   => $sellerStats['sold'] ?? 0,
            ],
            'safety_tips' => Config::get('classifieds.safety_tips'),
            'notice'      => $a['notice'],
            // Bouton « Contacter » : message = messagerie myClermont ; external = site d'origine ; none = indisponible
            'contact'     => $contact,
            // Conversation déjà ouverte avec le vendeur → l'app l'ouvre directement
            'conversation_id' => $conversationId,
            'expires_at'  => gmdate('Y-m-d\TH:i:s\Z', strtotime($a['expires_at'] . ' UTC')),
            'share_url'   => str_replace('{id}', $a['uid'], (string) Config::get('classifieds.share_url')),
        ];
    }

    /** 9000 → « 90 € » ; 1250 → « 12,50 € » */
    public static function priceLabel(int $cents): string
    {
        $amount = $cents % 100 === 0
            ? number_format(intdiv($cents, 100), 0, ',', "\u{202F}")
            : number_format($cents / 100, 2, ',', "\u{202F}");

        return $amount . "\u{00A0}€";
    }
}
