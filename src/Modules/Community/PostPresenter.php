<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Config;

/**
 * Format JSON des publications, calqué sur le fil « Communauté » :
 * avatar + pseudo + quartier + date, pastille de format, texte, médias / sondage, likes, commentaires, partage.
 * « il y a 4 secondes » est calculé par l'app à partir de created_at.
 */
final class PostPresenter
{
    /** Clé de format de la spec v1 (l'app passe des libellés français aux clés anglaises). */
    private const KINDS = ['text' => 'text', 'poll' => 'poll', 'photos' => 'photos', 'photo' => 'photoCaption', 'video' => 'video'];

    /**
     * Post de la spec v1 : id, author { id, displayName, avatar }, neighborhood, createdAt, kind, text, media[],
     * poll { options[], votes[], myVote }, likes, liked, commentsCount. Jamais d'e-mail.
     */
    public static function post(array $p, array $related, ?int $viewerId): array
    {
        $id = (int) $p['id'];
        $media = $related['media'][$id] ?? [];

        return [
            'id'             => $p['uid'],
            'author'         => self::specAuthor($p),
            'neighborhood'   => $p['neighborhood_name'],
            'created_at'     => gmdate('Y-m-d\TH:i:s\Z', strtotime($p['created_at'] . ' UTC')),
            'kind'           => self::KINDS[$p['type']] ?? $p['type'],
            'text'           => $p['type'] === 'photo' && $p['media_caption'] !== null ? $p['media_caption'] : $p['body'],
            'media'          => array_column($media, 'url'),
            'poll'           => $p['type'] === 'poll' ? self::poll($related['options'][$id] ?? [], $related['votes'][$id] ?? null, $viewerId !== null && (int) $p['user_id'] === $viewerId) : null,
            'likes'          => (int) $p['likes_count'],
            'liked'          => $viewerId !== null && in_array($id, $related['liked'], true),
            'comments_count' => (int) $p['comments_count'],
            // En plus de la spec
            'media_items'    => $media,
            'is_mine'        => $viewerId !== null && (int) $p['user_id'] === $viewerId,
            'share_url'      => str_replace('{id}', $p['uid'], (string) Config::get('community.share_url')),
        ];
    }

    /**
     * Sondage : options[] (libellés), votes[] (nombre de voix, même ordre), myVote (index de mon choix ou null).
     *
     * @param list<array> $options
     */
    public static function poll(array $options, ?int $votedOptionId, bool $isAuthor): array
    {
        $myVote = null;
        foreach (array_values($options) as $i => $o) {
            if ((int) $o['id'] === $votedOptionId) {
                $myVote = $i;
            }
        }

        return [
            'options'     => array_column($options, 'label'),
            'votes'       => array_map(static fn ($o) => (int) $o['votes_count'], array_values($options)),
            'my_vote'     => $myVote,
            'option_ids'  => array_map(static fn ($o) => (int) $o['id'], array_values($options)),
        ];
    }

    public static function comment(array $c, ?int $viewerId): array
    {
        return [
            'id'         => $c['uid'],
            'author'     => self::specAuthor($c),
            'text'       => $c['body'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($c['created_at'] . ' UTC')),
            'is_mine'    => $viewerId !== null && (int) $c['user_id'] === $viewerId,
        ];
    }

    private static function specAuthor(array $row): array
    {
        return \App\Modules\Account\Repositories\UserRepository::presentAuthor([
            'uid' => $row['author_uid'], 'display_name' => $row['author_display_name'] ?? null, 'username' => $row['author_username'],
            'avatar_kind' => $row['author_avatar_kind'] ?? 'symbol', 'avatar_value' => $row['author_avatar_value'] ?? null, 'status' => $row['author_status'] ?? 'active',
        ]);
    }

    /** Initiales calculées côté serveur pour un avatar identique sur iOS et Android (« AC », « J »). */
    public static function author(string $uid, string $username, ?string $neighborhood, bool $isMe): array
    {
        $parts = preg_split('/[\s._-]+/', trim($username)) ?: [];
        $initials = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(array_filter($parts), 0, 2))));

        return [
            'id'           => $uid,
            'username'     => $username,
            'initials'     => $initials !== '' ? $initials : '?',
            // Couleur d'avatar stable dérivée de l'identifiant (palette à définir côté app : index 0 à 7)
            'color_index'  => hexdec(substr(md5($uid), 0, 2)) % 8,
            'neighborhood' => $neighborhood,
            'is_me'        => $isMe,
        ];
    }
}
