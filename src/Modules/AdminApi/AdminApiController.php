<?php

declare(strict_types=1);

namespace App\Modules\AdminApi;

use App\Admin\Audit;
use App\Admin\CacheInvalidator;
use App\Admin\Schema;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Messaging\Push\Push;
use App\Modules\Places\ReviewController;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * Routes d'administration de l'API (spec v1 › Back-office) sous /admin, rôles moderator et admin.
 * Le back-office web (/admin dans un navigateur) reste l'outil principal ; ces routes servent l'espace partenaire et les outils internes.
 *
 *   GET|POST /admin/{resource}            GET|PATCH|DELETE /admin/{resource}/{id}
 *        resources : places, place_photos, events, deals, jobs, housing, services, reviews
 *   GET  /admin/reports                    file de modération (signalements, avis et propositions en attente)
 *   POST /admin/reports/{id}/resolve       { resolution: removed | dismissed | user_banned }
 *   POST /admin/users/{id}/ban             PATCH /admin/users/{id}/role { role }       (admin)
 *   POST /admin/push                       { topic, title, body, deeplink? }            (admin)
 */
final class AdminApiController
{
    public const RESOURCES = [
        'places' => 'places', 'place_photos' => 'place_photos', 'events' => 'events', 'deals' => 'deals',
        'jobs' => 'job_offers', 'housing' => 'housing_listings', 'services' => 'service_providers', 'reviews' => 'place_reviews',
    ];

    public function index(Request $request): Response
    {
        [$table, $schema] = $this->resource($request);
        $q = $request->query();
        $limit = min(100, max(1, (int) ($q['limit'] ?? 30)));
        $offset = isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0;
        $where = isset($schema['columns']['deleted_at']) ? ['deleted_at IS NULL'] : [];
        $params = [];
        if (isset($q['status'], $schema['columns']['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $q['status'];
        }
        $search = array_values(array_intersect(['title', 'name', 'company_name', 'merchant', 'venue_name', 'url'], array_keys($schema['columns'])));
        if (!empty($q['q']) && $search !== []) {
            $where[] = '(' . implode(' OR ', array_map(static fn ($c) => "`{$c}` LIKE :q", $search)) . ')';
            $params['q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        $rows = Database::fetchAll(
            "SELECT * FROM `{$table}`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            $params,
        );

        return Response::success(array_map(fn ($r) => $this->clean($r), array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function show(Request $request): Response
    {
        [$table, $schema] = $this->resource($request);

        return Response::success($this->clean($this->find($table, $schema, (string) $request->param('id'))));
    }

    public function store(Request $request): Response
    {
        [$table, $schema] = $this->resource($request);
        $data = $this->input($request->json(), $schema);
        $now = gmdate('Y-m-d H:i:s');
        foreach (['created_at', 'updated_at'] as $c) {
            if (isset($schema['columns'][$c])) {
                $data[$c] = $now;
            }
        }
        if (isset($schema['columns']['uid'])) {
            $data['uid'] = Crypto::uuid4();
        }
        $id = $this->write(fn () => Database::insert(
            "INSERT INTO `{$table}` (" . implode(', ', array_map(static fn ($c) => "`{$c}`", array_keys($data))) . ') VALUES ('
            . implode(', ', array_map(static fn ($c) => ":{$c}", array_keys($data))) . ')',
            $data,
        ));
        $row = Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $id]);
        $this->after($request, $table, 'create', null, $row);

        return Response::success($this->clean($row), 201);
    }

    public function update(Request $request): Response
    {
        [$table, $schema] = $this->resource($request);
        $before = $this->find($table, $schema, (string) $request->param('id'));
        $data = $this->input($request->json(), $schema);
        if (isset($schema['columns']['updated_at'])) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        if (isset($data['status'], $schema['columns']['published_at']) && $data['status'] === 'published' && empty($before['published_at'])) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($data !== []) {
            $this->write(fn () => Database::execute(
                "UPDATE `{$table}` SET " . implode(', ', array_map(static fn ($c) => "`{$c}` = :{$c}", array_keys($data))) . ' WHERE id = :__id',
                $data + ['__id' => $before['id']],
            ));
        }
        $after = Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $before['id']]);
        if ($table === 'place_reviews') {
            ReviewController::recompute((int) $after['place_id']);
        }
        $this->after($request, $table, 'update', $before, $after);

        return Response::success($this->clean($after));
    }

    public function destroy(Request $request): Response
    {
        [$table, $schema] = $this->resource($request);
        $before = $this->find($table, $schema, (string) $request->param('id'));
        // Suppression douce quand la table le permet (l'objet reste cohérent dans les favoris)
        isset($schema['columns']['deleted_at'])
            ? Database::execute("UPDATE `{$table}` SET deleted_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $before['id']])
            : Database::execute("DELETE FROM `{$table}` WHERE id = :id", ['id' => $before['id']]);
        if ($table === 'place_reviews') {
            ReviewController::recompute((int) $before['place_id']);
        }
        $this->after($request, $table, 'delete', $before, null);

        return Response::noContent();
    }

    /** File de modération : à traiter sous 24 heures. */
    public function reports(Request $request): Response
    {
        $reports = Database::fetchAll(
            'SELECT r.uid, r.target_type, r.target_id, r.reason, r.details, r.created_at, u.uid AS reporter, COUNT(*) OVER (PARTITION BY r.target_type, r.target_id) AS reports_on_target
             FROM reports r JOIN users u ON u.id = r.user_id WHERE r.resolved_at IS NULL ORDER BY r.created_at LIMIT 200',
        );

        $iso = static fn (array $rows) => array_map(static function (array $r): array {
            foreach (['created_at', 'starts_at'] as $c) {
                if (isset($r[$c])) {
                    $r[$c] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r[$c] . ' UTC'));
                }
            }
            return $r;
        }, $rows);

        return Response::success([
            'reports'          => $iso($reports),
            'pending_events'   => $iso(Database::fetchAll("SELECT slug AS id, title, venue_name AS venue, starts_at, created_at FROM events WHERE status = 'pending' AND deleted_at IS NULL ORDER BY created_at LIMIT 100")),
            'pending_reviews'  => $iso(Database::fetchAll("SELECT uid AS id, rating, comment AS text, created_at FROM place_reviews WHERE status = 'pending' ORDER BY id LIMIT 100")),
        ]);
    }

    public function resolve(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['resolution' => 'required|in:removed,dismissed,user_banned']);
        $report = Database::fetch('SELECT * FROM reports WHERE uid = :u', ['u' => strtolower((string) $request->param('id'))])
            ?? throw new HttpException(404, 'not_found', 'Signalement introuvable.');

        if ($data['resolution'] === 'removed') {
            $this->hideTarget($report['target_type'], $report['target_id']);
        }
        if ($data['resolution'] === 'user_banned') {
            $this->hideTarget($report['target_type'], $report['target_id']);
            if ($authorId = $this->authorOf($report['target_type'], $report['target_id'])) {
                $this->banUser($authorId);
            }
        }
        // Tous les signalements du même contenu sont clos ensemble
        Database::execute(
            'UPDATE reports SET resolution = :r, resolved_by = :by, resolved_at = :d WHERE target_type = :t AND target_id = :id AND resolved_at IS NULL',
            ['r' => $data['resolution'], 'by' => $request->attributes['user_id'], 'd' => gmdate('Y-m-d H:i:s'), 't' => $report['target_type'], 'id' => $report['target_id']],
        );
        Audit::log(null, 'api.report_resolved', $request->ip, 'reports', $report['uid'], ['resolution' => [null, $data['resolution']], 'by' => [null, $request->attributes['user_id']]]);

        return Response::noContent();
    }

    public function ban(Request $request): Response
    {
        $user = $this->user((string) $request->param('id'));
        if ($user['role'] === 'admin') {
            throw HttpException::forbidden('forbidden', 'Impossible de bannir un administrateur.');
        }
        $this->banUser((int) $user['id']);
        Audit::log(null, 'api.user_banned', $request->ip, 'users', (string) $user['id'], ['by' => [null, $request->attributes['user_id']]]);

        return Response::noContent();
    }

    public function role(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['role' => 'required|in:user,partner,moderator,admin']);
        $user = $this->user((string) $request->param('id'));
        if ((int) $user['id'] === (int) $request->attributes['user_id']) {
            throw HttpException::forbidden('forbidden', 'Tu ne peux pas changer ton propre rôle.');
        }
        Database::execute('UPDATE users SET role = :r, updated_at = :d WHERE id = :id', ['r' => $data['role'], 'd' => gmdate('Y-m-d H:i:s'), 'id' => $user['id']]);
        // Le rôle est dans le jeton d'accès : on ferme les sessions pour qu'il soit pris en compte tout de suite
        Database::execute('UPDATE refresh_tokens SET revoked_at = :d WHERE user_id = :u AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'u' => $user['id']]);
        Audit::log(null, 'api.user_role', $request->ip, 'users', (string) $user['id'], ['role' => [$user['role'], $data['role']], 'by' => [null, $request->attributes['user_id']]]);

        return Response::success(['id' => $user['uid'], 'role' => $data['role']]);
    }

    /** Notification à tous les appareils qui suivent un thème. */
    public function push(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'topic' => 'required|in:' . implode(',', (array) Config::get('push.topics')),
            'title' => 'required|string|min:2|max:120', 'body' => 'required|string|min:2|max:250', 'deeplink' => 'nullable|string|max:255',
        ]);
        if (!empty($data['deeplink']) && !str_starts_with($data['deeplink'], 'myclermont://')) {
            throw new ValidationException(['deeplink' => 'Le lien doit commencer par myclermont://.']);
        }
        $campaign = bin2hex(random_bytes(6));
        $n = 0;
        foreach (Database::fetchAll(
            "SELECT id FROM devices WHERE status = 'active' AND push_token IS NOT NULL AND (topics IS NULL OR FIND_IN_SET(:t, topics))", ['t' => $data['topic']],
        ) as $d) {
            $n += (int) Push::toDevice((int) $d['id'], $data['topic'], $data['title'], $data['body'], ['deeplink' => $data['deeplink'] ?? null, 'once_key' => "admin:{$campaign}:{$d['id']}"]);
        }
        Audit::log(null, 'api.push', $request->ip, 'push', $campaign, ['topic' => [null, $data['topic']], 'title' => [null, $data['title']], 'devices' => [null, $n]]);

        return Response::success(['queued' => $n]);
    }

    private function hideTarget(string $type, string $id): void
    {
        match ($type) {
            'post'       => Database::execute("UPDATE community_posts SET status = 'hidden' WHERE uid = :id", ['id' => $id]),
            'comment'    => Database::execute("UPDATE community_comments SET status = 'deleted' WHERE uid = :id", ['id' => $id]),
            'classified' => Database::execute("UPDATE classifieds SET status = 'hidden' WHERE uid = :id", ['id' => $id]),
            'event'      => Database::execute("UPDATE events SET status = 'rejected' WHERE slug = :id", ['id' => $id]),
            'review'     => (function () use ($id): void {
                $r = Database::fetch('SELECT place_id FROM place_reviews WHERE uid = :id', ['id' => $id]);
                Database::execute("UPDATE place_reviews SET status = 'hidden' WHERE uid = :id", ['id' => $id]);
                if ($r) {
                    ReviewController::recompute((int) $r['place_id']);
                }
            })(),
            'user'       => ($u = Database::fetch('SELECT id FROM users WHERE uid = :id', ['id' => $id])) ? $this->banUser((int) $u['id']) : null,
            default      => null,
        };
    }

    private function authorOf(string $type, string $id): ?int
    {
        $sql = match ($type) {
            'post' => 'SELECT user_id FROM community_posts WHERE uid = :id', 'comment' => 'SELECT user_id FROM community_comments WHERE uid = :id',
            'review' => 'SELECT user_id FROM place_reviews WHERE uid = :id', 'classified' => 'SELECT user_id FROM classifieds WHERE uid = :id',
            'event' => 'SELECT author_user_id AS user_id FROM events WHERE slug = :id', 'message' => 'SELECT sender_id AS user_id FROM messages WHERE uid = :id',
            'user' => 'SELECT id AS user_id FROM users WHERE uid = :id', default => null,
        };
        $row = $sql === null ? null : Database::fetch($sql, ['id' => $id]);

        return isset($row['user_id']) ? (int) $row['user_id'] : null;
    }

    private function banUser(int $userId): void
    {
        Database::execute("UPDATE users SET status = 'banned', updated_at = :d WHERE id = :id AND role <> 'admin'", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
        Database::execute('UPDATE refresh_tokens SET revoked_at = :d WHERE user_id = :u AND revoked_at IS NULL', ['d' => gmdate('Y-m-d H:i:s'), 'u' => $userId]);
    }

    private function user(string $uid): array
    {
        $u = preg_match('/^[0-9a-f-]{36}$/i', $uid) ? Database::fetch('SELECT id, uid, role FROM users WHERE uid = :u', ['u' => strtolower($uid)]) : null;

        return $u ?? throw new HttpException(404, 'user_not_found', 'Utilisateur introuvable.');
    }

    /** @return array{0: string, 1: array} */
    private function resource(Request $request): array
    {
        $table = self::RESOURCES[(string) $request->param('resource')] ?? throw new HttpException(404, 'not_found', 'Ressource inconnue.');
        $schema = Schema::table($table) ?? throw new \RuntimeException("Table absente : {$table}");

        return [$table, $schema];
    }

    private function find(string $table, array $schema, string $id): array
    {
        $column = isset($schema['columns']['uid']) && !ctype_digit($id) ? 'uid' : ($table === 'events' && !ctype_digit($id) ? 'slug' : 'id');
        $row = Database::fetch("SELECT * FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id]);

        return $row ?? throw new HttpException(404, 'not_found', 'Élément introuvable.');
    }

    /** Champs JSON (snake_case ou camelCase) limités aux colonnes de la table ; identifiants et colonnes techniques exclus. */
    private function input(array $json, array $schema): array
    {
        $blocked = array_merge(['id', 'uid', 'created_at', 'updated_at', 'deleted_at'], (array) Config::get('admin.hidden_columns', []));
        $data = [];
        foreach ($json as $k => $v) {
            if (!isset($schema['columns'][$k]) || in_array($k, $blocked, true)) {
                throw new ValidationException([$k => 'Champ inconnu ou non modifiable.']);
            }
            $data[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($v) ? (int) $v : $v);
        }

        return $data;
    }

    /** Erreurs de base (valeur hors ENUM, clé étrangère, doublon) → 400 / 409 lisibles. */
    private function write(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            $msg = $e->errorInfo[2] ?? $e->getMessage();
            if (str_contains($msg, 'Duplicate')) {
                throw new HttpException(409, 'conflict', 'Cet élément existe déjà.');
            }
            throw new HttpException(400, 'validation_failed', 'Valeur refusée : ' . mb_substr($msg, 0, 160));
        }
    }

    private function after(Request $request, string $table, string $action, ?array $before, ?array $after): void
    {
        CacheInvalidator::afterWrite($table, $before, $after);
        if ($table === 'places' || $table === 'place_photos') {
            (new \App\Modules\Places\PlaceRepository())->forgetPins();
        }
        $changes = [];
        foreach (array_keys(($after ?? []) + ($before ?? [])) as $c) {
            if (($before[$c] ?? null) !== ($after[$c] ?? null) && !in_array($c, (array) Config::get('admin.hidden_columns', []), true)) {
                $changes[$c] = [$before[$c] ?? null, $after[$c] ?? null];
            }
        }
        $changes['by_user'] = [null, $request->attributes['user_id']];
        Audit::log(null, 'api.' . $action, $request->ip, $table, (string) (($after ?? $before)['id'] ?? ''), $changes);
    }

    private function clean(array $row): array
    {
        return array_diff_key($row, array_flip((array) Config::get('admin.hidden_columns', [])));
    }
}
