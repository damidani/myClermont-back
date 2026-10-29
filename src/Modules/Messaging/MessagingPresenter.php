<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Modules\Community\PostPresenter;

final class MessagingPresenter
{
    /** Ligne de la boîte de réception. */
    public static function conversation(array $c, array $contexts, int $viewerId): array
    {
        $context = $contexts[$c['context_type']][$c['context_id']] ?? null;
        $preview = $c['last_body'] === null ? null : (mb_strlen($c['last_body']) > 100 ? mb_substr($c['last_body'], 0, 97) . '…' : $c['last_body']);

        return [
            'id'      => $c['uid'],
            'context' => [
                'type'      => $c['context_type'],
                'id'        => $c['context_id'],
                'title'     => $context['title'] ?? 'Élément supprimé',
                'subtitle'  => $context['subtitle'] ?? null,
                'image_url' => $context['image_url'] ?? null,
                'icon'      => $context['icon'] ?? null,
                'color'     => $context['color'] ?? null,
                'status'    => $context['status'] ?? 'deleted',
            ],
            'other'   => self::user($c['other_uid'], $c['other_username']),
            'my_role' => $c['my_role'],
            'last_message' => $preview === null ? null : [
                'body'       => $preview,
                'is_mine'    => (int) $c['last_sender_id'] === $viewerId,
                'created_at' => self::date($c['last_created_at']),
            ],
            'unread_count' => (int) $c['unread_count'],
            'updated_at'   => self::date($c['last_activity_at']),
        ];
    }

    public static function message(array $m, int $viewerId, array $usersById = []): array
    {
        return [
            'id'         => (int) $m['id'],
            'uid'        => $m['uid'],
            'client_id'  => $m['client_id'],
            'type'       => $m['sender_id'] === null ? 'system' : 'text',
            'body'       => $m['body'],
            'is_mine'    => $m['sender_id'] !== null && (int) $m['sender_id'] === $viewerId,
            'sender_id'  => $m['sender_id'] === null ? null : ($usersById[(int) $m['sender_id']] ?? null),
            // Lien détecté : l'app affiche « Méfie-toi des liens de paiement »
            'has_link'   => (bool) $m['has_link'],
            'created_at' => self::date($m['created_at']),
        ];
    }

    public static function user(string $uid, string $username): array
    {
        $author = PostPresenter::author($uid, $username, null, false);

        return ['id' => $uid, 'username' => $username, 'initials' => $author['initials'], 'color_index' => $author['color_index']];
    }

    /** Dates avec millisecondes : l'ordre des messages envoyés dans la même seconde est conservé à l'affichage. */
    public static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $value, new \DateTimeZone('UTC'))
            ?: new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

        return $d->format('Y-m-d\TH:i:s\Z'); // sans millisecondes : .iso8601 de Swift les refuse
    }
}
