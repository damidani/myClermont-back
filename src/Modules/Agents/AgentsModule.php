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
 *   GET   /agents/status                                                     → dernière connexion de chaque assistant + version du dossier
 *   GET   /agents/bundle                                                     → dossier app-integration en zip (sans les fichiers *.local.md)
 *
 * kind = report : compte rendu de session (ce qui a été fait, fichiers, build, questions) pour que l'autre côté reste au courant.
 * Chaque appel met à jour la présence ; les en-têtes facultatifs X-Agent-Version, X-Agent-Branch, X-Agent-Commit la complètent.
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
        if (!in_array($kind, ['request', 'reply', 'note', 'bug', 'report'], true)) {
            $errors['kind'] = 'Type : request, reply, note, bug ou report.';
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
        if ($kind === 'report') {
            Database::execute('UPDATE agent_presence SET last_report = :t WHERE agent = :a', ['t' => mb_substr($title, 0, 200), 'a' => $from]);
        }
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

    public function status(Request $request): Response
    {
        $this->agent($request);
        $iso = static fn (?string $d) => $d === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($d . ' UTC'));
        $items = [];
        foreach (Database::fetchAll('SELECT * FROM agent_presence ORDER BY last_seen_at DESC') as $p) {
            $open = Database::fetch("SELECT COUNT(*) AS n FROM agent_messages WHERE recipient = :a AND status IN ('open', 'in_progress')", ['a' => $p['agent']]);
            // Ce qui demande une action : demandes, bugs et réponses encore « open » (jamais les comptes rendus ni les notes)
            $actionable = Database::fetch("SELECT COUNT(*) AS n FROM agent_messages WHERE recipient = :a AND status = 'open' AND kind IN ('request', 'bug', 'reply')", ['a' => $p['agent']]);
            $items[] = ['agent' => $p['agent'], 'lastSeenAt' => $iso($p['last_seen_at']), 'appVersion' => $p['app_version'], 'branch' => $p['branch'],
                        'commit' => $p['commit_sha'], 'lastReport' => $p['last_report'], 'pendingForAgent' => (int) $open['n'], 'actionableForAgent' => (int) $actionable['n']];
        }

        return Response::json(['items' => $items, 'bundle' => ['version' => self::bundleVersion(), 'url' => '/api/v1/agents/bundle'],
                               'serverTime' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /** Dossier app-integration (consignes, routes, fixtures) en zip : l'app le récupère seule quand la version change. */
    public function bundle(Request $request): Response
    {
        $this->agent($request);
        $tmp = tempnam(sys_get_temp_dir(), 'bundle');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach (self::bundleFiles() as $rel => $abs) {
            $zip->addFile($abs, 'app-integration/' . $rel);
        }
        $zip->addFromString('app-integration/VERSION', self::bundleVersion() . "\n");
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return Response::raw($bytes, 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="app-integration-' . self::bundleVersion() . '.zip"')
            ->withHeader('X-Bundle-Version', self::bundleVersion())
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string, string> chemin relatif => chemin absolu (secrets *.local.md exclus) */
    private static function bundleFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/app-integration';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if ($f->isFile() && !str_ends_with($rel, '.local.md')) {
                $files[$rel] = $f->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** Empreinte du contenu : change dès qu'un fichier du dossier change */
    private static function bundleVersion(): string
    {
        $h = hash_init('sha256');
        foreach (self::bundleFiles() as $rel => $abs) {
            hash_update($h, $rel . "\0" . hash_file('sha256', $abs));
        }

        return substr(hash_final($h), 0, 12);
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
        $h = static fn (string $k, int $max) => ($v = trim((string) $request->header($k, ''))) === '' ? null : mb_substr($v, 0, $max);
        Database::execute(
            'INSERT INTO agent_presence (agent, last_seen_at, last_ip, app_version, branch, commit_sha) VALUES (:a, :d, :ip, :v, :b, :c)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), last_ip = VALUES(last_ip),
                 app_version = COALESCE(VALUES(app_version), app_version), branch = COALESCE(VALUES(branch), branch), commit_sha = COALESCE(VALUES(commit_sha), commit_sha)',
            ['a' => $name, 'd' => gmdate('Y-m-d H:i:s'), 'ip' => $request->ip, 'v' => $h('X-Agent-Version', 40), 'b' => $h('X-Agent-Branch', 120), 'c' => $h('X-Agent-Commit', 64)],
        );

        return $name;
    }

    private function find(string $uid): array
    {
        return Database::fetch('SELECT * FROM agent_messages WHERE uid = :u', ['u' => strtolower($uid)])
            ?? throw new HttpException(404, 'not_found', 'Message introuvable.');
    }
}
