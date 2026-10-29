<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Exceptions\HttpException;
use App\Security\Crypto;

/**
 * Messagerie entre utilisateurs.
 *
 * Choix de performance :
 *  - le nombre de non-lus est stocké par participant (badge de l'onglet Chat = 1 SUM sur un index)
 *  - la boîte de réception est triée par last_activity_at (index dédié)
 *  - pas de connexion permanente : l'app interroge ?after=<dernier id> toutes les quelques secondes quand une
 *    conversation est ouverte, et reçoit une notification push sinon
 */
final class MessagingService
{
    /** Ouvre (ou retrouve) la conversation entre deux personnes à propos d'un élément. */
    public function open(string $contextType, string $contextId, int $initiatorId, string $initiatorRole, int $recipientId, string $recipientRole): int
    {
        if ($initiatorId === $recipientId) {
            throw new HttpException(409, 'cannot_message_self', 'Tu ne peux pas t\'envoyer de message.');
        }
        if ($this->isBlockedBetween($initiatorId, $recipientId)) {
            throw HttpException::forbidden('user_blocked', 'Impossible de contacter cette personne.');
        }

        $pairKey = $contextType . ':' . $contextId . ':' . min($initiatorId, $recipientId) . ':' . max($initiatorId, $recipientId);
        $now = gmdate('Y-m-d H:i:s');

        return Database::transaction(function () use ($contextType, $contextId, $pairKey, $initiatorId, $initiatorRole, $recipientId, $recipientRole, $now): int {
            Database::execute(
                'INSERT IGNORE INTO conversations (uid, context_type, context_id, pair_key, created_at) VALUES (:uid, :t, :c, :k, :now)',
                ['uid' => Crypto::uuid4(), 't' => $contextType, 'c' => $contextId, 'k' => $pairKey, 'now' => $now],
            );
            $id = (int) Database::fetch('SELECT id FROM conversations WHERE pair_key = :k', ['k' => $pairKey])['id'];
            foreach ([[$initiatorId, $initiatorRole], [$recipientId, $recipientRole]] as [$userId, $role]) {
                Database::execute(
                    'INSERT IGNORE INTO conversation_participants (conversation_id, user_id, role, last_activity_at) VALUES (:c, :u, :r, :now)',
                    ['c' => $id, 'u' => $userId, 'r' => $role, 'now' => $now],
                );
            }

            return $id;
        });
    }

    /**
     * Envoie un message. Avec client_id, un même envoi rejoué (réseau instable) ne crée pas de doublon.
     *
     * @return array ligne du message
     */
    public function send(int $conversationId, int $senderId, string $body, ?string $clientId): array
    {
        $participants = $this->participants($conversationId);
        if (!isset($participants[$senderId])) {
            throw new HttpException(404, 'conversation_not_found', 'Conversation introuvable.');
        }
        $others = array_values(array_diff(array_keys($participants), [$senderId]));
        foreach ($others as $otherId) {
            if ($this->isBlockedBetween($senderId, $otherId)) {
                throw HttpException::forbidden('user_blocked', 'Impossible d\'envoyer un message à cette personne.');
            }
        }

        $body = self::clean($body);
        $max = (int) Config::get('messaging.message_max', 2000);
        if ($body === '' || mb_strlen($body) > $max) {
            throw new \App\Exceptions\ValidationException(['body' => "Message vide ou trop long ({$max} caractères max)."]);
        }

        if ($clientId !== null && ($existing = Database::fetch(
            'SELECT * FROM messages WHERE conversation_id = :c AND sender_id = :s AND client_id = :cid',
            ['c' => $conversationId, 's' => $senderId, 'cid' => $clientId],
        )) !== null) {
            return $existing;
        }

        $message = Database::transaction(function () use ($conversationId, $senderId, $body, $clientId, $others): array {
            $now = gmdate('Y-m-d H:i:s');
            $uid = Crypto::uuid4();
            $id = Database::insert(
                'INSERT INTO messages (uid, conversation_id, sender_id, client_id, body, has_link, created_at) VALUES (:uid, :c, :s, :cid, :b, :l, :d)',
                ['uid' => $uid, 'c' => $conversationId, 's' => $senderId, 'cid' => $clientId, 'b' => $body,
                 'l' => self::containsLink($body) ? 1 : 0, 'd' => gmdate('Y-m-d H:i:s') . sprintf('.%03d', (int) (microtime(true) * 1000) % 1000)],
            );
            Database::execute('UPDATE conversations SET last_message_id = :m, last_message_at = :d WHERE id = :c', ['m' => $id, 'd' => $now, 'c' => $conversationId]);
            Database::execute(
                'UPDATE conversation_participants SET last_read_message_id = :m, unread_count = 0, archived_at = NULL, last_activity_at = :d
                 WHERE conversation_id = :c AND user_id = :u',
                ['m' => $id, 'd' => $now, 'c' => $conversationId, 'u' => $senderId],
            );
            foreach ($others as $otherId) {
                Database::execute(
                    'UPDATE conversation_participants SET unread_count = unread_count + 1, archived_at = NULL, last_activity_at = :d
                     WHERE conversation_id = :c AND user_id = :u',
                    ['d' => $now, 'c' => $conversationId, 'u' => $otherId],
                );
            }

            return Database::fetch('SELECT * FROM messages WHERE id = :id', ['id' => $id]);
        });

        $this->queuePush($conversationId, $senderId, $others, $body);
        Events::dispatch('messaging.message_sent', ['conversation_id' => $conversationId, 'sender_id' => $senderId, 'recipient_ids' => $others]);

        return $message;
    }

    /** Marque comme lu jusqu'au message donné (ou jusqu'au dernier). */
    public function markRead(int $conversationId, int $userId, ?int $upToMessageId = null): int
    {
        $last = $upToMessageId ?? (int) (Database::fetch('SELECT last_message_id FROM conversations WHERE id = :c', ['c' => $conversationId])['last_message_id'] ?? 0);
        Database::execute(
            'UPDATE conversation_participants p
             SET p.last_read_message_id = GREATEST(p.last_read_message_id, :m),
                 p.unread_count = (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = p.conversation_id
                                   AND m.id > GREATEST(p.last_read_message_id, :m2) AND (m.sender_id IS NULL OR m.sender_id <> p.user_id))
             WHERE p.conversation_id = :c AND p.user_id = :u',
            ['m' => $last, 'm2' => $last, 'c' => $conversationId, 'u' => $userId],
        );

        return $this->unreadTotal($userId);
    }

    public function unreadTotal(int $userId): int
    {
        return (int) (Database::fetch(
            'SELECT COALESCE(SUM(unread_count), 0) AS n FROM conversation_participants WHERE user_id = :u AND archived_at IS NULL',
            ['u' => $userId],
        )['n'] ?? 0);
    }

    /** Conversation vue par un participant (null s'il n'en fait pas partie). */
    public function find(string $uid, int $userId): ?array
    {
        return Database::fetch(
            'SELECT c.id, c.uid, c.context_type, c.context_id, c.last_message_id, c.created_at,
                    p.role AS my_role, p.unread_count, p.last_read_message_id, p.archived_at, p.last_activity_at
             FROM conversations c JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = :u
             WHERE c.uid = :c',
            ['u' => $userId, 'c' => strtolower($uid)],
        );
    }

    /** Boîte de réception, de la plus récente à la plus ancienne (curseur = last_activity_at|id). */
    public function inbox(int $userId, int $limit, ?array $before, bool $archived = false): array
    {
        $where = 'p.user_id = :u AND p.archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL') . ' AND c.last_message_id IS NOT NULL';
        $params = ['u' => $userId, 'limit' => $limit];
        if ($before !== null) {
            $where .= ' AND (p.last_activity_at < :b OR (p.last_activity_at = :b2 AND c.id < :bid))';
            $params += ['b' => $before[0], 'b2' => $before[0], 'bid' => $before[1]];
        }

        return Database::fetchAll(
            "SELECT c.id, c.uid, c.context_type, c.context_id, p.role AS my_role, p.unread_count, p.last_activity_at,
                    m.body AS last_body, m.sender_id AS last_sender_id, m.created_at AS last_created_at,
                    o.user_id AS other_id, u.uid AS other_uid, u.username AS other_username
             FROM conversation_participants p
             JOIN conversations c ON c.id = p.conversation_id
             LEFT JOIN messages m ON m.id = c.last_message_id
             JOIN conversation_participants o ON o.conversation_id = c.id AND o.user_id <> p.user_id
             JOIN users u ON u.id = o.user_id
             WHERE {$where}
             ORDER BY p.last_activity_at DESC, c.id DESC LIMIT :limit",
            $params,
        );
    }

    /**
     * Messages d'une conversation.
     *  - sans curseur : les plus récents
     *  - before=<id> : page précédente (remonter l'historique)
     *  - after=<id>  : nouveaux messages depuis le dernier reçu (interrogation périodique)
     * Toujours renvoyés du plus ancien au plus récent.
     */
    public function messages(int $conversationId, int $limit, ?int $before, ?int $after): array
    {
        if ($after !== null) {
            return Database::fetchAll(
                'SELECT * FROM messages WHERE conversation_id = :c AND id > :a ORDER BY id ASC LIMIT :limit',
                ['c' => $conversationId, 'a' => $after, 'limit' => $limit],
            );
        }
        $rows = Database::fetchAll(
            'SELECT * FROM messages WHERE conversation_id = :c AND id < :b ORDER BY id DESC LIMIT :limit',
            ['c' => $conversationId, 'b' => $before ?? PHP_INT_MAX, 'limit' => $limit],
        );

        return array_reverse($rows);
    }

    /** @return array<int, array{user_id: int, role: string}> */
    public function participants(int $conversationId): array
    {
        return array_column(
            Database::fetchAll('SELECT user_id, role FROM conversation_participants WHERE conversation_id = :c', ['c' => $conversationId]),
            null,
            'user_id',
        );
    }

    public function otherParticipant(int $conversationId, int $userId): ?array
    {
        return Database::fetch(
            'SELECT u.id, u.uid, u.username FROM conversation_participants p JOIN users u ON u.id = p.user_id
             WHERE p.conversation_id = :c AND p.user_id <> :u LIMIT 1',
            ['c' => $conversationId, 'u' => $userId],
        );
    }

    public function archive(int $conversationId, int $userId): void
    {
        Database::execute(
            'UPDATE conversation_participants SET archived_at = :d, unread_count = 0 WHERE conversation_id = :c AND user_id = :u',
            ['d' => gmdate('Y-m-d H:i:s'), 'c' => $conversationId, 'u' => $userId],
        );
    }

    public function block(int $blockerId, int $blockedId): void
    {
        Database::execute('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id, created_at) VALUES (:a, :b, :d)', ['a' => $blockerId, 'b' => $blockedId, 'd' => gmdate('Y-m-d H:i:s')]);
    }

    public function unblock(int $blockerId, int $blockedId): void
    {
        Database::execute('DELETE FROM user_blocks WHERE blocker_id = :a AND blocked_id = :b', ['a' => $blockerId, 'b' => $blockedId]);
    }

    public function hasBlocked(int $blockerId, int $blockedId): bool
    {
        return Database::fetch('SELECT 1 FROM user_blocks WHERE blocker_id = :a AND blocked_id = :b', ['a' => $blockerId, 'b' => $blockedId]) !== null;
    }

    public function isBlockedBetween(int $a, int $b): bool
    {
        return Database::fetch(
            'SELECT 1 FROM user_blocks WHERE (blocker_id = :a AND blocked_id = :b) OR (blocker_id = :b2 AND blocked_id = :a2) LIMIT 1',
            ['a' => $a, 'b' => $b, 'b2' => $b, 'a2' => $a],
        ) !== null;
    }

    public function report(int $conversationId, int $userId, string $reason, ?string $details): void
    {
        Database::execute(
            'INSERT IGNORE INTO conversation_reports (conversation_id, user_id, reason, details, created_at) VALUES (:c, :u, :r, :d, :now)',
            ['c' => $conversationId, 'u' => $userId, 'r' => $reason, 'd' => $details, 'now' => gmdate('Y-m-d H:i:s')],
        );
    }

    /**
     * Une seule notification en attente par conversation et par destinataire : si plusieurs messages arrivent
     * avant l'envoi, la notification existante est mise à jour au lieu d'en empiler plusieurs.
     */
    private function queuePush(int $conversationId, int $senderId, array $recipientIds, string $body): void
    {
        $sender = Database::fetch('SELECT username, display_name FROM users WHERE id = :u', ['u' => $senderId]);
        $conversation = Database::fetch('SELECT uid FROM conversations WHERE id = :c', ['c' => $conversationId]);
        $preview = mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body;
        $data = json_encode(['type' => 'message', 'conversation_id' => $conversation['uid'], 'deeplink' => 'myclermont://conversation/' . $conversation['uid']]);

        foreach ($recipientIds as $userId) {
            $key = 'conv:' . $conversation['uid'];
            $updated = Database::execute(
                "UPDATE push_outbox SET body = :b, created_at = :d WHERE user_id = :u AND collapse_key = :k AND status = 'pending'",
                ['b' => $preview, 'd' => gmdate('Y-m-d H:i:s'), 'u' => $userId, 'k' => $key],
            );
            if ($updated === 0) {
                Database::execute(
                    "INSERT INTO push_outbox (user_id, topic, title, body, data, collapse_key, thread_id, created_at) VALUES (:u, 'classifieds.messages', :t, :b, :data, :k, :k2, :d)",
                    ['u' => $userId, 't' => (string) ($sender['display_name'] ?? $sender['username'] ?? 'Nouveau message'), 'b' => $preview, 'data' => $data, 'k' => $key, 'k2' => $key, 'd' => gmdate('Y-m-d H:i:s')],
                );
            }
        }
    }

    /** Supprime les caractères de contrôle invisibles (sauf retours à la ligne) et les espaces superflus. */
    public static function clean(string $body): string
    {
        $body = (string) preg_replace('/[^\P{C}\n]/u', '', str_replace("\r\n", "\n", $body));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $body));
    }

    public static function containsLink(string $body): bool
    {
        return (bool) preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|fr|net|org|io|ly|me|link|shop|xyz|info)\b)~i', $body);
    }
}
