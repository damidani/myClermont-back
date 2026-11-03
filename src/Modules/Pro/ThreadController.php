<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Messagerie client ↔ commerçant (demande 4).
 *
 * Espace pro :  GET /pro/threads?box=inbox|unread|archived&q= ; GET|PATCH|DELETE /pro/threads/{id} ; POST /pro/threads/{id}/read ;
 *               POST /pro/threads/{id}/messages { text }
 * App (compte) : POST /places/{placeId}/messages { subject, message, source, sourceId } ; GET /me/threads ; POST /me/threads/{id}/messages { text }
 */
final class ThreadController
{
    private const SOURCES = ['fiche', 'bon_plan', 'evenement', 'emploi', 'devis', 'logement'];

    // --- Espace pro --------------------------------------------------------

    public function index(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = Pro::page($request);
        $where = ['t.business_id = :b', 't.deleted_at IS NULL'];
        $params = ['b' => Pro::business($request)['id']];
        $where[] = match ($q['box'] ?? 'inbox') { 'unread' => 't.unread_pro = 1 AND t.archived = 0', 'archived' => 't.archived = 1', default => 't.archived = 0' };
        if (is_string($q['q'] ?? null) && trim($q['q']) !== '') {
            $where[] = '(t.subject LIKE :q OR t.contact_name LIKE :q2 OR EXISTS (SELECT 1 FROM pro_thread_messages m WHERE m.thread_id = t.id AND m.body LIKE :q3))';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . addcslashes(trim($q['q']), '%_\\') . '%';
        }
        $rows = Database::fetchAll('SELECT t.* FROM pro_threads t WHERE ' . implode(' AND ', $where) . ' ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . ($limit + 1) . " OFFSET {$offset}", $params);

        return Response::success(array_map([self::class, 'present'], array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function show(Request $request): Response
    {
        return Response::success(self::present($this->find($request)));
    }

    public function read(Request $request): Response
    {
        Database::execute('UPDATE pro_threads SET unread_pro = 0 WHERE id = :id', ['id' => $this->find($request)['id']]);

        return Response::noContent();
    }

    public function update(Request $request): Response
    {
        $t = $this->find($request);
        $j = $request->json();
        $set = [];
        $params = ['id' => $t['id']];
        foreach (['unread' => 'unread_pro', 'archived' => 'archived'] as $k => $col) {
            if (array_key_exists($k, $j)) {
                if (!is_bool($j[$k])) {
                    throw new ValidationException([$k => 'Doit être vrai ou faux.']);
                }
                $set[] = "{$col} = :{$k}";
                $params[$k] = (int) $j[$k];
            }
        }
        if ($set !== []) {
            Database::execute('UPDATE pro_threads SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }

        return Response::success(self::present($this->find($request)));
    }

    public function destroy(Request $request): Response
    {
        Database::execute('UPDATE pro_threads SET deleted_at = :d WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $this->find($request)['id']]);

        return Response::noContent();
    }

    public function send(Request $request): Response
    {
        $t = $this->find($request);
        $text = self::text($request->json()['text'] ?? null);
        self::append((int) $t['id'], 'pro', $text, (int) Pro::member($request)['id']);
        Database::execute('UPDATE pro_threads SET unread_pro = 0, unread_client = 1, archived = 0 WHERE id = :id', ['id' => $t['id']]);
        $name = Pro::displayName(Pro::business($request));
        if ($t['user_id'] !== null) {
            // request_replied (4890352c) : boîte de l'app, push selon les préférences
            \App\Modules\Notifications\Notices::send((int) $t['user_id'], 'request_replied', "💬 {$name} t'a répondu", \App\Modules\Notifications\Notices::excerpt($text),
                ['type' => $t['source'] === 'logement' ? 'housing' : 'request', 'id' => $t['uid']]);
        }
        if ($t['contact_email'] !== '') {
            Pro::mail($t['contact_email'], "{$name} t'a répondu : {$t['subject']}", "{$name} a répondu à ton message :\n\n{$text}\n\nRéponds-lui depuis l'app My Clermont.");
        }

        return Response::success(self::present($this->find($request)));
    }

    // --- App ----------------------------------------------------------------

    /** POST /places/{placeId}/messages : écrire au commerçant depuis la fiche, un bon plan, un événement… */
    public function create(Request $request): Response
    {
        $place = \App\Modules\Places\MapController::findPublished((string) $request->param('id'))
            ?? throw new HttpException(404, 'not_found', 'Ce lieu n\'existe pas ou n\'est plus en ligne.');
        $business = Pro::businessOfPlace((int) $place['id'])
            ?? throw new HttpException(409, 'conflict', 'Ce lieu ne reçoit pas encore de messages.', ['reason' => 'no_merchant']);
        $j = $request->json();
        $subject = trim((string) ($j['subject'] ?? '')) ?: 'Question sur ' . $place['name'];
        if (mb_strlen($subject) > 160) {
            throw new ValidationException(['subject' => 'Objet : 160 caractères au plus.']);
        }
        $source = $j['source'] ?? 'fiche';
        if (!in_array($source, self::SOURCES, true)) {
            throw new ValidationException(['source' => 'Source inconnue.']);
        }
        $text = self::text($j['message'] ?? null, 'message');
        $user = Database::fetch('SELECT id, email, first_name, display_name, phone FROM users WHERE id = :id', ['id' => $request->attributes['user_id']]);
        $now = gmdate('Y-m-d H:i:s');
        $uid = Crypto::uuid4();
        $id = Database::insert(
            'INSERT INTO pro_threads (uid, business_id, user_id, subject, source, source_id, contact_name, contact_email, contact_phone, unread_pro, created_at, updated_at)
             VALUES (:u, :b, :usr, :s, :src, :sid, :n, :e, :p, 1, :c, :up)',
            ['u' => $uid, 'b' => $business['id'], 'usr' => $user['id'], 's' => $subject, 'src' => $source,
             'sid' => is_string($j['source_id'] ?? null) ? mb_substr($j['source_id'], 0, 120) : null,
             'n' => (string) ($user['first_name'] ?: $user['display_name']), 'e' => (string) $user['email'], 'p' => (string) $user['phone'], 'c' => $now, 'up' => $now],
        );
        self::append($id, 'client', $text, null);
        Pro::notify((int) $business['id'], 'message', 'Nouveau message : ' . $subject, mb_substr($text, 0, 200), '/messages?fil=' . $uid, 'new_message');

        return Response::success(self::presentForUser(Database::fetch('SELECT * FROM pro_threads WHERE id = :id', ['id' => $id])), 201);
    }

    /** GET /me/threads */
    public function mine(Request $request): Response
    {
        [$limit, $offset] = Pro::page($request);
        $rows = Database::fetchAll('SELECT * FROM pro_threads WHERE user_id = :u ORDER BY updated_at DESC, id DESC LIMIT ' . ($limit + 1) . " OFFSET {$offset}",
            ['u' => $request->attributes['user_id']]);

        return Response::success(array_map([self::class, 'presentForUser'], array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    /** POST /me/threads/{id}/messages */
    public function reply(Request $request): Response
    {
        $t = Database::fetch('SELECT * FROM pro_threads WHERE uid = :u AND user_id = :usr', ['u' => (string) $request->param('id'), 'usr' => $request->attributes['user_id']])
            ?? throw new HttpException(404, 'not_found', 'Conversation introuvable.');
        $text = self::text($request->json()['text'] ?? null);
        self::append((int) $t['id'], 'client', $text, null);
        Database::execute('UPDATE pro_threads SET unread_pro = 1, unread_client = 0, archived = 0, deleted_at = NULL WHERE id = :id', ['id' => $t['id']]);
        Pro::notify((int) $t['business_id'], 'message', 'Nouveau message : ' . $t['subject'], mb_substr($text, 0, 200), '/messages?fil=' . $t['uid'], 'new_message');

        return Response::success(self::presentForUser(Database::fetch('SELECT * FROM pro_threads WHERE id = :id', ['id' => $t['id']])));
    }

    // -----------------------------------------------------------------------

    public static function present(array $t): array
    {
        return [
            'id' => $t['uid'], 'subject' => $t['subject'], 'source' => $t['source'], 'source_id' => $t['source_id'],
            'contact_name' => $t['contact_name'], 'contact_email' => $t['contact_email'], 'contact_phone' => $t['contact_phone'],
            'unread' => (bool) $t['unread_pro'], 'archived' => (bool) $t['archived'], 'messages' => self::messages((int) $t['id']), 'updated_at' => Pro::iso($t['updated_at']),
        ];
    }

    /** Vue du client dans l'app : même forme, avec le nom de l'établissement. */
    public static function presentForUser(array $t): array
    {
        $b = Database::fetch('SELECT COALESCE(p.name, b.trade_name) AS trade_name, p.app_id, p.uid FROM pro_businesses b LEFT JOIN places p ON p.id = b.place_id WHERE b.id = :id', ['id' => $t['business_id']]);

        return [
            'id' => $t['uid'], 'subject' => $t['subject'], 'source' => $t['source'], 'source_id' => $t['source_id'],
            'merchant_name' => (string) ($b['trade_name'] ?? ''), 'place_id' => $b['app_id'] ?? $b['uid'] ?? null,
            'unread' => (bool) $t['unread_client'], 'messages' => self::messages((int) $t['id']), 'updated_at' => Pro::iso($t['updated_at']),
        ];
    }

    private static function messages(int $threadId): array
    {
        return array_map(static fn (array $m) => ['id' => $m['uid'], 'from' => $m['sender'], 'text' => $m['body'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($m['created_at'] . ' UTC'))],
            Database::fetchAll('SELECT uid, sender, body, created_at FROM pro_thread_messages WHERE thread_id = :t ORDER BY id', ['t' => $threadId]));
    }

    private static function append(int $threadId, string $sender, string $text, ?int $memberId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::insert('INSERT INTO pro_thread_messages (uid, thread_id, sender, member_id, body, created_at) VALUES (:u, :t, :s, :m, :b, :c)',
            ['u' => Crypto::uuid4(), 't' => $threadId, 's' => $sender, 'm' => $memberId, 'b' => $text, 'c' => gmdate('Y-m-d H:i:s.v')]);
        Database::execute('UPDATE pro_threads SET updated_at = :d WHERE id = :id', ['d' => $now, 'id' => $threadId]);
    }

    private static function text(mixed $v, string $field = 'text'): string
    {
        $text = is_string($v) ? trim($v) : '';
        if ($text === '' || mb_strlen($text) > 2000) {
            throw new ValidationException([$field => 'Le message doit faire entre 1 et 2000 caractères.']);
        }

        return $text;
    }

    private function find(Request $request): array
    {
        return Database::fetch('SELECT * FROM pro_threads WHERE uid = :u AND business_id = :b AND deleted_at IS NULL',
            ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Conversation introuvable.');
    }
}
