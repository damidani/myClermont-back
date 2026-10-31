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
    /** Type interne → libellé de l'app (brief iOS §12.1) */
    private const KINDS = ['text' => 'Texte', 'poll' => 'Sondage', 'photos' => 'Photos + texte', 'photo' => 'Photo + description', 'video' => 'Vidéo'];
    /** Type interne → valeur de la spec v1 (gardée dans specKind) */
    private const SPEC_KINDS = ['text' => 'text', 'poll' => 'poll', 'photos' => 'photos', 'photo' => 'photoCaption', 'video' => 'video'];

    /**
     * Post de l'app iOS (brief §12.1) : author = prénom, neighborhood (chaîne), date, kind (libellé), text, mediaFiles[] (URL),
     * pollOptions[] / votes[] / myVote / pollQuestion à plat, likes, liked, comments[] (les 20 derniers).
     * Gardés en plus : authorInfo { id, displayName, avatar }, createdAt, commentsCount, mediaItems, isMine, shareUrl.
     */
    public static function post(array $p, array $related, ?int $viewerId): array
    {
        $id = (int) $p['id'];
        $media = $related['media'][$id] ?? [];
        $poll = $p['type'] === 'poll' ? self::poll($related['options'][$id] ?? [], $related['votes'][$id] ?? null, $viewerId !== null && (int) $p['user_id'] === $viewerId) : null;
        $date = gmdate('Y-m-d\TH:i:s\Z', strtotime($p['created_at'] . ' UTC'));

        return [
            'id'             => $p['uid'],
            'author'         => self::authorName($p),
            'neighborhood'   => (string) ($p['neighborhood_name'] ?? ''),
            'date'           => $date,
            'kind'           => self::KINDS[$p['type']] ?? 'Texte',
            // Photo : la légende ; sondage : la description facultative (la question est dans pollQuestion)
            'text'           => (string) (in_array($p['type'], ['photo', 'poll'], true) && $p['media_caption'] !== null ? $p['media_caption'] : ($p['body'] ?? '')),
            'media_files'    => array_values(array_filter(array_column($media, 'url'), static fn ($u) => is_string($u) && str_starts_with($u, 'https://'))),
            'poll_options'   => $poll['options'] ?? [],
            'votes'          => $poll['votes'] ?? [],
            'my_vote'        => $poll['my_vote'] ?? null,
            'poll_question'  => $p['type'] === 'poll' ? (string) ($p['body'] ?? '') : null,
            'likes'          => (int) $p['likes_count'],
            'liked'          => $viewerId !== null && in_array($id, $related['liked'], true),
            'comments'       => array_map(static fn ($c) => self::comment($c, $viewerId), $related['comments'][$id] ?? []),
            // En plus (spec v1, autres clients)
            'author_info'    => self::specAuthor($p),
            'created_at'     => $date,
            'spec_kind'      => self::SPEC_KINDS[$p['type']] ?? $p['type'],
            'media'          => array_column($media, 'url'),
            'poll'           => $poll,
            'comments_count' => (int) $p['comments_count'],
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

    /** Commentaire de l'app (brief §12.1) : { id, author (prénom), message, date } ; text / createdAt / authorInfo en plus. */
    public static function comment(array $c, ?int $viewerId): array
    {
        $date = gmdate('Y-m-d\TH:i:s\Z', strtotime($c['created_at'] . ' UTC'));

        return [
            'id'          => $c['uid'],
            'author'      => self::authorName($c),
            'message'     => (string) $c['body'],
            'date'        => $date,
            'author_info' => self::specAuthor($c),
            'text'        => (string) $c['body'],
            'created_at'  => $date,
            'is_mine'     => $viewerId !== null && (int) $c['user_id'] === $viewerId,
        ];
    }

    /** Prénom affiché ; « Compte supprimé » pour un compte effacé */
    private static function authorName(array $row): string
    {
        if (($row['author_status'] ?? 'active') === 'deleted') {
            return 'Compte supprimé';
        }

        return (string) ($row['author_first_name'] ?? null ?: $row['author_display_name'] ?? null ?: $row['author_username'] ?? '');
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
