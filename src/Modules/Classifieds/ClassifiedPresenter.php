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
    /** États de la spec v1 (libellés de l'app) ↔ codes internes */
    public const CONDITION_LABELS = ['new' => 'Comme neuf', 'very_good' => 'Très bon état', 'good' => 'Bon état', 'fair' => 'État correct', 'for_parts' => 'État correct'];
    public const CONDITION_CODES = ['Comme neuf' => 'new', 'Très bon état' => 'very_good', 'Bon état' => 'good', 'État correct' => 'fair'];

    /**
     * Ad de la spec v1 : id, title, category, price (euros entiers, 0 = don), priceLabel (« Gratuit »), district, description,
     * condition, seller { id, displayName, avatar }, images[], createdAt.
     */
    public static function card(array $a, ?bool $isFavorite = null, ?int $viewerId = null, ?array $images = null): array
    {
        $cover = $a['cover'] === null ? null : (str_starts_with($a['cover'], 'http') ? $a['cover'] : MediaStore::url($a['cover']));
        $cents = (int) $a['price_cents'];

        return [
            'id'          => $a['uid'],
            'title'       => $a['title'],
            'category'    => $a['category_slug'],
            'price'       => $a['is_free'] ? 0 : intdiv($cents, 100),
            'price_label' => $a['is_free'] || $cents === 0 ? 'Gratuit' : self::priceLabel($cents),
            'district'    => $a['location_label'],
            'description' => $a['description'],
            'condition'   => self::CONDITION_LABELS[$a['item_condition']] ?? $a['item_condition'],
            'seller'      => $a['seller_uid'] === null ? null : \App\Modules\Account\Repositories\UserRepository::presentAuthor([
                'uid' => $a['seller_uid'], 'display_name' => $a['seller_display_name'] ?? null, 'username' => $a['seller_username'],
                'avatar_kind' => $a['seller_avatar_kind'] ?? 'symbol', 'avatar_value' => $a['seller_avatar_value'] ?? null, 'status' => $a['seller_status'] ?? 'active',
            ]),
            'images'      => $images ?? array_values(array_filter([$cover])),
            'created_at'  => gmdate('Y-m-d\TH:i:s\Z', strtotime(($a['created_at'] ?? $a['published_at']) . ' UTC')),
            // En plus de la spec
            'category_name' => $a['category_name'],
            'category_icon' => $a['category_icon'],
            'category_color' => $a['category_color'],
            'status'      => $a['status'],          // active | reserved | sold | expired
            'badge'       => $a['badge_label'],
            'source'      => ContentSources::present($a),
            'is_mine'     => $viewerId !== null && $a['user_id'] !== null && (int) $a['user_id'] === $viewerId,
            'is_favorite' => $isFavorite,
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

        $card = self::card($a, $isFavorite, $viewerId, array_values(array_filter(array_map(static fn ($p) => is_array($p) ? ($p['url'] ?? null) : $p, $photos))));
        if ($card['seller'] !== null) {
            $card['seller'] += [
                'member_since'    => gmdate('Y-m-d', strtotime($a['seller_since'] . ' UTC')),
                'active_listings' => $sellerStats['active'] ?? 0,
                'sold_listings'   => $sellerStats['sold'] ?? 0,
            ];
        }

        return $card + [
            'photos'      => $photos,
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
