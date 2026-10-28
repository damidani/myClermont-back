<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Support\Validator;

/**
 * Onglet « Chat » : boîte de réception, conversation, envoi, lecture, blocage, signalement.
 * Toutes les routes exigent un compte.
 */
final class MessagingController
{
    private MessagingService $messaging;

    public function __construct()
    {
        $this->messaging = new MessagingService();
    }

    /** GET /v1/conversations?archived=1&cursor=… */
    public function inbox(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $q = $request->query();
        $limit = max(1, min(50, (int) ($q['limit'] ?? 30)));
        $before = null;
        if (isset($q['cursor'])) {
            $decoded = (string) base64_decode(strtr((string) $q['cursor'], '-_', '+/'), true);
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $m)) {
                throw new ValidationException(['cursor' => 'Curseur invalide.']);
            }
            $before = [$m[1], (int) $m[2]];
        }

        $rows = $this->messaging->inbox($userId, $limit, $before, ($q['archived'] ?? '') === '1');
        $idsByType = [];
        foreach ($rows as $row) {
            $idsByType[$row['context_type']][] = $row['context_id'];
        }
        $contexts = MessagingContexts::resolve($idsByType);
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $c) => MessagingPresenter::conversation($c, $contexts, $userId), $rows),
            200,
            [
                'unread_total' => $this->messaging->unreadTotal($userId),
                'next_cursor'  => count($rows) === $limit && $last ? rtrim(strtr(base64_encode($last['last_activity_at'] . '|' . $last['id']), '+/', '-_'), '=') : null,
            ],
        );
    }

    /** GET /v1/conversations/unread-count — badge de l'onglet Chat (requête très légère) */
    public function unreadCount(Request $request): Response
    {
        return Response::success(['unread_total' => $this->messaging->unreadTotal($request->attributes['user_id'])]);
    }

    /** GET /v1/conversations/{id} — en-tête de la conversation (annonce concernée, interlocuteur) */
    public function show(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $conversation = $this->findOrFail($request, $userId);
        $other = $this->messaging->otherParticipant((int) $conversation['id'], $userId);
        $contexts = MessagingContexts::resolve([$conversation['context_type'] => [$conversation['context_id']]]);

        return Response::success([
            'id'          => $conversation['uid'],
            'context'     => MessagingPresenter::conversation($conversation + [
                'last_body' => null, 'last_sender_id' => null, 'last_created_at' => null,
                'other_uid' => $other['uid'] ?? '', 'other_username' => $other['username'] ?? 'Compte supprimé',
            ], $contexts, $userId)['context'],
            'other'       => $other === null ? null : MessagingPresenter::user($other['uid'], $other['username']) + [
                'blocked_by_me' => $this->messaging->hasBlocked($userId, (int) $other['id']),
            ],
            'my_role'      => $conversation['my_role'],
            'can_reply'    => $other !== null && !$this->messaging->isBlockedBetween($userId, (int) $other['id']),
            'poll_interval' => (int) Config::get('messaging.poll_interval', 5),
        ]);
    }

    /**
     * GET /v1/conversations/{id}/messages
     *   ?before=<id>  historique (remonter)   ?after=<id>  nouveaux messages (interrogation périodique)
     */
    public function messages(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $conversation = $this->findOrFail($request, $userId);
        $q = $request->query();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 50)));
        $before = isset($q['before']) && ctype_digit((string) $q['before']) ? (int) $q['before'] : null;
        $after = isset($q['after']) && ctype_digit((string) $q['after']) ? (int) $q['after'] : null;

        $rows = $this->messaging->messages((int) $conversation['id'], $limit, $before, $after);
        $users = $this->userUids(array_filter(array_column($rows, 'sender_id')));
        $other = Database::fetch(
            'SELECT last_read_message_id FROM conversation_participants WHERE conversation_id = :c AND user_id <> :u LIMIT 1',
            ['c' => $conversation['id'], 'u' => $userId],
        );

        return Response::success(
            array_map(static fn (array $m) => MessagingPresenter::message($m, $userId, $users), $rows),
            200,
            [
                // Plus ancien id chargé : pour « charger les messages précédents » (before)
                'oldest_id'  => $rows ? (int) $rows[0]['id'] : null,
                // Plus récent id chargé : pour l'interrogation suivante (after)
                'newest_id'  => $rows ? (int) end($rows)['id'] : $after,
                'has_more'   => $after === null && count($rows) === $limit,
                // « Vu » : dernier message lu par l'interlocuteur
                'other_last_read_id' => (int) ($other['last_read_message_id'] ?? 0),
            ],
        );
    }

    /** POST /v1/conversations/{id}/messages {body, client_id} */
    public function send(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $conversation = $this->findOrFail($request, $userId);
        $data = Validator::validate($request->json(), [
            'body'      => 'required|string|max:' . Config::get('messaging.message_max', 2000),
            'client_id' => 'nullable|string|max:64',
        ]);

        $message = $this->messaging->send((int) $conversation['id'], $userId, $data['body'], $data['client_id'] ?? null);

        return Response::success(MessagingPresenter::message($message, $userId, $this->userUids([$userId])), 201);
    }

    /** POST /v1/conversations/{id}/read {message_id?} */
    public function read(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $conversation = $this->findOrFail($request, $userId);
        $messageId = $request->input('message_id');

        return Response::success(['unread_total' => $this->messaging->markRead((int) $conversation['id'], $userId, is_int($messageId) ? $messageId : null)]);
    }

    /** DELETE /v1/conversations/{id} — masque la conversation (elle réapparaît au prochain message) */
    public function archive(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $this->messaging->archive((int) $this->findOrFail($request, $userId)['id'], $userId);

        return Response::noContent();
    }

    /** POST /v1/conversations/{id}/report {reason, details?, block?} */
    public function report(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $conversation = $this->findOrFail($request, $userId);
        $data = Validator::validate($request->json(), [
            'reason'  => 'required|in:scam,harassment,spam,other',
            'details' => 'nullable|string|max:500',
            'block'   => 'nullable|boolean',
        ]);
        $this->messaging->report((int) $conversation['id'], $userId, $data['reason'], $data['details'] ?? null);
        \App\Core\Logger::security('Conversation signalée', ['conversation' => $conversation['uid'], 'reason' => $data['reason']]);

        if (!empty($data['block']) && ($other = $this->messaging->otherParticipant((int) $conversation['id'], $userId)) !== null) {
            $this->messaging->block($userId, (int) $other['id']);
        }

        return Response::success(['reported' => true, 'message' => 'Merci, notre équipe va examiner cette conversation.']);
    }

    /** PUT / DELETE /v1/users/{id}/block — bloquer / débloquer un utilisateur (id public) */
    public function block(Request $request): Response
    {
        $target = $this->userByUid((string) $request->param('id'));
        $this->messaging->block($request->attributes['user_id'], (int) $target['id']);

        return Response::success(['id' => $target['uid'], 'blocked' => true]);
    }

    public function unblock(Request $request): Response
    {
        $target = $this->userByUid((string) $request->param('id'));
        $this->messaging->unblock($request->attributes['user_id'], (int) $target['id']);

        return Response::success(['id' => $target['uid'], 'blocked' => false]);
    }

    private function userByUid(string $uid): array
    {
        $user = preg_match('/^[0-9a-f-]{36}$/i', $uid) ? Database::fetch('SELECT id, uid FROM users WHERE uid = :u', ['u' => strtolower($uid)]) : null;
        if ($user === null) {
            throw new HttpException(404, 'user_not_found', 'Utilisateur introuvable.');
        }

        return $user;
    }

    /** @return array<int, string> id interne => uid public */
    private function userUids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $in[] = ":u{$i}";
            $params["u{$i}"] = $id;
        }

        return array_column(Database::fetchAll('SELECT id, uid FROM users WHERE id IN (' . implode(', ', $in) . ')', $params), 'uid', 'id');
    }

    private function findOrFail(Request $request, int $userId): array
    {
        $id = (string) $request->param('id');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($c = $this->messaging->find($id, $userId)) === null) {
            // Même réponse que la conversation n'existe pas ou n'appartienne pas à l'utilisateur
            throw new HttpException(404, 'conversation_not_found', 'Conversation introuvable.');
        }

        return $c;
    }
}
