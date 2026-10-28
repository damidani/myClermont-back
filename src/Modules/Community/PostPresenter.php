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
    public static function post(array $p, array $related, ?int $viewerId): array
    {
        $types = (array) Config::get('community.types');
        $id = (int) $p['id'];

        return [
            'id'     => $p['uid'],
            'type'   => ['code' => $p['type'], 'label' => $types[$p['type']][0] ?? $p['type'], 'icon' => $types[$p['type']][1] ?? null],
            'author' => self::author($p['author_uid'], $p['author_username'], $p['neighborhood_name'], $viewerId !== null && (int) $p['user_id'] === $viewerId),
            'body'   => $p['body'],
            'badge'  => $p['badge_label'],
            'media'  => $related['media'][$id] ?? [],
            'media_caption' => $p['media_caption'],
            'poll'   => $p['type'] === 'poll' ? self::poll($related['options'][$id] ?? [], $related['votes'][$id] ?? null, $viewerId !== null && (int) $p['user_id'] === $viewerId) : null,
            'likes_count'    => (int) $p['likes_count'],
            'comments_count' => (int) $p['comments_count'],
            'is_liked'   => $viewerId === null ? null : in_array($id, $related['liked'], true),
            'share_url'  => str_replace('{id}', $p['uid'], (string) Config::get('community.share_url')),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($p['created_at'] . ' UTC')),
        ];
    }

    /**
     * Résultats visibles seulement après avoir voté (« Vote pour découvrir les résultats ») ou pour l'auteur.
     *
     * @param list<array> $options
     */
    public static function poll(array $options, ?int $votedOptionId, bool $isAuthor): array
    {
        $total = array_sum(array_map(static fn ($o) => (int) $o['votes_count'], $options));
        $showResults = $votedOptionId !== null || $isAuthor;

        return [
            'options' => array_map(static fn (array $o) => [
                'id'      => (int) $o['id'],
                'label'   => $o['label'],
                'votes'   => $showResults ? (int) $o['votes_count'] : null,
                'percent' => $showResults ? ($total > 0 ? (int) round((int) $o['votes_count'] * 100 / $total) : 0) : null,
            ], $options),
            'total_votes'      => $showResults ? $total : null,
            'voted_option_id'  => $votedOptionId,
            'results_visible'  => $showResults,
        ];
    }

    public static function comment(array $c, ?int $viewerId): array
    {
        return [
            'id'         => $c['uid'],
            'author'     => self::author($c['author_uid'], $c['author_username'], null, $viewerId !== null && (int) $c['user_id'] === $viewerId),
            'body'       => $c['body'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($c['created_at'] . ' UTC')),
        ];
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
