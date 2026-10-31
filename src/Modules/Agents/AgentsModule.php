<?php

declare(strict_types=1);

namespace App\Modules\Agents;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Canal entre assistants : le Claude de l'app iOS dépose une demande (« il me faut telle route »), le Claude du back-end
 * la traite à sa prochaine session et répond dans le même fil ; l'app lit la réponse à sa session suivante.
 * Hors signature d'appareil : en-tête X-Agent-Token (AGENTS_TOKEN), comparé en temps constant.
 *
 *   POST  /agents/messages              { to, kind?, title, body, thread? }   → 201 message
 *   GET   /agents/messages?to=&status=&thread=&since=                       → { items }
 *   GET   /agents/messages/{id}                                              → message + messages du fil
 *   PATCH /agents/messages/{id}         { status }                           → message
 */
final class AgentsModule
{
    private const AGENTS = ['ios', 'android', 'backend', 'web', 'human'];

    public function create(Request $request): Response
    {
        $from = $this->agent($request);
        $j = $request->json();
        $to = $j['to'] ?? null;
        $kind = $j['kind'] ?? 'request';
        $title = is_string($j['title'] ?? null) ? trim($j['title']) : '';
        $body = is_string($j['body'] ?? null) ? trim($j['body']) : '';
        $errors = [];
        if (!in_array($to, self::AGENTS, true)) {
            $errors['to'] = 'Destinataire : ' . implode(', ', self::AGENTS) . '.';
        }
        if (!in_array($kind, ['request', 'reply', 'note', 'bug'], true)) {
            $errors['kind'] = 'Type : request, reply, note ou bug.';
        }
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Titre requis (200 caractères au plus).';
        }
        if ($body === '' || strlen($body) > 200000) {
            $errors['body'] = 'Contenu requis (200 Ko au plus).';
        }
        $thread = null;
        if (isset($j['thread'])) {
            $thread = Database::fetch('SELECT thread_uid FROM agent_messages WHERE uid = :u', ['u' => strtolower((string) $j['thread'])])['thread_uid'] ?? null;
            $thread ?? $errors['thread'] = 'Fil introuvable.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $uid = Crypto::uuid4();
        $now = gmdate('Y-m-d H:i:s');
        Database::insert(
            'INSERT INTO agent_messages (uid, thread_uid, sender, recipient, kind, title, body, created_at, updated_at) VALUES (:u, :t, :f, :to, :k, :ti, :b, :c, :up)',
            ['u' => $uid, 't' => $thread ?? $uid, 'f' => $from, 'to' => $to, 'k' => $kind, 'ti' => $title, 'b' => $body, 'c' => $now, 'up' => $now],
        );
        // Une réponse fait avancer la demande d'origine
        if ($thread !== null && $kind === 'reply' && in_array($j['status'] ?? null, ['in_progress', 'done', 'rejected'], true)) {
            Database::execute('UPDATE agent_messages SET status = :s, updated_at = :d WHERE uid = :t', ['s' => $j['status'], 'd' => $now, 't' => $thread]);
        }

        return Response::json(self::present($this->find($uid)), 201);
    }

    public function index(Request $request): Response
    {
        $this->agent($request);
        $q = $request->query();
        $where = ['1 = 1'];
        $params = [];
        foreach (['to' => 'recipient', 'from' => 'sender', 'status' => 'status', 'thread' => 'thread_uid', 'kind' => 'kind'] as $param => $col) {
            if (isset($q[$param]) && is_string($q[$param]) && $q[$param] !== '') {
                $where[] = "{$col} = :{$param}";
                $params[$param] = $q[$param];
            }
        }
        if (isset($q['since']) && ($t = strtotime((string) $q['since'])) !== false) {
            $where[] = 'updated_at > :since';
            $params['since'] = gmdate('Y-m-d H:i:s', $t);
        }
        $rows = Database::fetchAll('SELECT * FROM agent_messages WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200', $params);

        return Response::json(['items' => array_map([self::class, 'present'], $rows)]);
    }

    public function show(Request $request): Response
    {
        $this->agent($request);
        $m = $this->find((string) $request->param('id'));
        $thread = Database::fetchAll('SELECT * FROM agent_messages WHERE thread_uid = :t ORDER BY id', ['t' => $m['thread_uid']]);

        return Response::json(self::present($m) + ['messages' => array_map([self::class, 'present'], $thread)]);
    }

    public function update(Request $request): Response
    {
        $this->agent($request);
        $m = $this->find((string) $request->param('id'));
        $status = $request->json()['status'] ?? null;
        if (!in_array($status, ['open', 'in_progress', 'done', 'rejected'], true)) {
            throw new ValidationException(['status' => 'Statut : open, in_progress, done ou rejected.']);
        }
        Database::execute('UPDATE agent_messages SET status = :s, updated_at = :d WHERE id = :id', ['s' => $status, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $m['id']]);

        return Response::json(self::present($this->find($m['uid'])));
    }

    public static function present(array $m): array
    {
        $iso = static fn (string $d) => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($d . ' UTC'));

        return ['id' => $m['uid'], 'thread' => $m['thread_uid'], 'from' => $m['sender'], 'to' => $m['recipient'], 'kind' => $m['kind'],
                'title' => $m['title'], 'body' => $m['body'], 'status' => $m['status'], 'createdAt' => $iso($m['created_at']), 'updatedAt' => $iso($m['updated_at'])];
    }

    /** Agent appelant : X-Agent-Token = AGENTS_TOKEN, X-Agent-Name = ios | android | backend… */
    private function agent(Request $request): string
    {
        $expected = (string) Config::get('agents.token', '');
        if ($expected === '') {
            throw new HttpException(503, 'not_configured', 'Canal agents non configuré (AGENTS_TOKEN).');
        }
        if (!hash_equals($expected, (string) $request->header('X-Agent-Token', ''))) {
            throw HttpException::unauthorized('invalid_agent_token', 'Jeton agent invalide.');
        }
        $name = strtolower((string) $request->header('X-Agent-Name', ''));
        if (!in_array($name, self::AGENTS, true)) {
            throw new ValidationException(['X-Agent-Name' => 'En-tête X-Agent-Name : ' . implode(', ', self::AGENTS) . '.']);
        }

        return $name;
    }

    private function find(string $uid): array
    {
        return Database::fetch('SELECT * FROM agent_messages WHERE uid = :u', ['u' => strtolower($uid)])
            ?? throw new HttpException(404, 'not_found', 'Message introuvable.');
    }
}
