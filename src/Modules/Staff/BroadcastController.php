<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Admin\Audit;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Pro\Pro;
use App\Security\Crypto;

/**
 * Espace d'envoi de l'équipe (c06e670d), dans /admin du dashboard :
 *   GET /admin/broadcasts                         → { items: Broadcast[], nextCursor }
 *   POST /admin/broadcasts/audience               { audience } → { total, push, email }
 *   POST /admin/broadcasts                        { title, body, channels: [push, email], emailSubject?, emailBody?, audience, target? } → Broadcast (brouillon)
 *   PATCH /admin/broadcasts/{id}                  → Broadcast (brouillon seulement)
 *   POST /admin/broadcasts/{id}/send              { sendAt? } → programmé (envoyé par le cron broadcasts:send dans la minute)
 *   POST /admin/broadcasts/{id}/test              → envoi aux comptes staff seulement
 *   POST /admin/broadcasts/{id}/cancel
 *   GET | PUT /admin/settings                     → { notifications, mail }
 *   PATCH /admin/users/{id}/staff                 { isStaff, staffTitle? } → badge staff d'un compte de l'app
 */
final class BroadcastController
{
    public function index(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = [max(1, min(100, (int) ($q['limit'] ?? 30))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
        $rows = Database::fetchAll('SELECT * FROM broadcasts ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset);

        return Response::success(['items' => array_map([self::class, 'present'], array_slice($rows, 0, $limit)), 'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function audience(Request $request): Response
    {
        return Response::success(Broadcasts::preview(Broadcasts::audience($request->json()['audience'] ?? [])));
    }

    public function create(Request $request): Response
    {
        $d = self::validate($request->json(), null);
        $uid = Crypto::uuid4();
        Database::insert('INSERT INTO broadcasts (uid, title, body, email_subject, email_body, channels, audience, target, created_by, created_at)
                          VALUES (:u, :t, :b, :es, :eb, :c, :a, :tg, :by, :d)',
            ['u' => $uid, 't' => $d['title'], 'b' => $d['body'], 'es' => $d['email_subject'], 'eb' => $d['email_body'], 'c' => $d['channels'],
             'a' => $d['audience'], 'tg' => $d['target'], 'by' => (string) $request->attributes['staff']['name'], 'd' => gmdate('Y-m-d H:i:s')]);

        return Response::success(self::present($this->find($uid)), 201);
    }

    public function update(Request $request): Response
    {
        $b = $this->find((string) $request->param('id'));
        if ($b['status'] !== 'brouillon') {
            throw new HttpException(409, 'conflict', 'Cet envoi est déjà programmé ou parti.');
        }
        $d = self::validate($request->json(), $b);
        Database::execute('UPDATE broadcasts SET title = :t, body = :b, email_subject = :es, email_body = :eb, channels = :c, audience = :a, target = :tg WHERE id = :id',
            ['t' => $d['title'], 'b' => $d['body'], 'es' => $d['email_subject'], 'eb' => $d['email_body'], 'c' => $d['channels'], 'a' => $d['audience'], 'tg' => $d['target'], 'id' => $b['id']]);

        return Response::success(self::present($this->find($b['uid'])));
    }

    public function send(Request $request): Response
    {
        $b = $this->find((string) $request->param('id'));
        if ($b['status'] !== 'brouillon') {
            throw new HttpException(409, 'conflict', 'Cet envoi est déjà programmé ou parti.');
        }
        $at = $request->json()['send_at'] ?? null;
        $ts = is_string($at) ? strtotime($at) : time();
        if ($ts === false) {
            throw new ValidationException(['sendAt' => 'Date invalide.']);
        }
        Database::execute("UPDATE broadcasts SET status = 'programme', send_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s', max(time(), $ts)), 'id' => $b['id']]);
        Audit::log((int) $request->attributes['staff']['id'], 'broadcast.send', $request->ip, 'broadcasts', $b['uid'], ['audience' => Broadcasts::preview(json_decode($b['audience'], true) ?: [])]);

        return Response::success(self::present($this->find($b['uid'])));
    }

    public function test(Request $request): Response
    {
        $b = $this->find((string) $request->param('id'));
        $audience = ['staff_only' => true];
        [$n, $p, $m] = Broadcasts::send(['audience' => json_encode($audience), 'status' => 'test'] + $b + ['id' => 0]);

        return Response::success(['recipients' => $n, 'pushed' => $p, 'emailed' => $m]);
    }

    public function cancel(Request $request): Response
    {
        $b = $this->find((string) $request->param('id'));
        if (!in_array($b['status'], ['brouillon', 'programme'], true)) {
            throw new HttpException(409, 'conflict', 'Cet envoi est déjà parti.');
        }
        Database::execute("UPDATE broadcasts SET status = 'annule' WHERE id = :id", ['id' => $b['id']]);

        return Response::success(self::present($this->find($b['uid'])));
    }

    public function settings(Request $request): Response
    {
        return Response::success(['notifications' => Settings::get('notifications'), 'mail' => Settings::get('mail')]);
    }

    public function updateSettings(Request $request): Response
    {
        $j = $request->json();
        $by = (string) $request->attributes['staff']['name'];
        $n = is_array($j['notifications'] ?? null) ? $j['notifications'] : [];
        if (isset($n['daily_push_cap']) && (!is_int($n['daily_push_cap']) || $n['daily_push_cap'] < 0 || $n['daily_push_cap'] > 50)) {
            throw new ValidationException(['notifications.dailyPushCap' => 'Entre 0 et 50.']);
        }
        $m = is_array($j['mail'] ?? null) ? $j['mail'] : [];
        if (isset($m['reply_to']) && $m['reply_to'] !== null && !filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['mail.replyTo' => 'Adresse e-mail invalide.']);
        }
        Audit::log((int) $request->attributes['staff']['id'], 'settings.update', $request->ip, 'app_settings', null, $j);

        return Response::success(['notifications' => $n !== [] ? Settings::put('notifications', $n, $by) : Settings::get('notifications'),
                                  'mail' => $m !== [] ? Settings::put('mail', $m, $by) : Settings::get('mail')]);
    }

    public function staff(Request $request): Response
    {
        $j = $request->json();
        if (!is_bool($j['is_staff'] ?? null)) {
            throw new ValidationException(['isStaff' => 'Booléen attendu.']);
        }
        $title = is_string($j['staff_title'] ?? null) && trim($j['staff_title']) !== '' ? mb_substr(trim($j['staff_title']), 0, 60) : null;
        $id = rawurldecode((string) $request->param('id'));
        $u = Database::fetch('SELECT id, uid, handle FROM users WHERE uid = :u OR handle = :h', ['u' => $id, 'h' => ltrim($id, '@')]) ?? throw new HttpException(404, 'not_found', 'Compte introuvable.');
        Database::execute('UPDATE users SET is_staff = :s, staff_title = :t WHERE id = :id', ['s' => $j['is_staff'] ? 1 : 0, 't' => $j['is_staff'] ? $title : null, 'id' => $u['id']]);
        Audit::log((int) $request->attributes['staff']['id'], 'user.staff', $request->ip, 'users', $u['uid'], ['is_staff' => $j['is_staff']]);

        return Response::success(['id' => $u['uid'], 'handle' => $u['handle'], 'is_staff' => $j['is_staff'], 'staff_title' => $j['is_staff'] ? ($title ?? 'Équipe MyClermont') : null]);
    }

    private function find(string $uid): array
    {
        return Database::fetch('SELECT * FROM broadcasts WHERE uid = :u', ['u' => $uid]) ?? throw new HttpException(404, 'not_found', 'Envoi introuvable.');
    }

    private static function validate(array $j, ?array $cur): array
    {
        $errors = [];
        $title = trim((string) ($j['title'] ?? $cur['title'] ?? ''));
        $body = trim((string) ($j['body'] ?? $cur['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Titre obligatoire, 120 caractères au plus.';
        }
        if ($body === '' || mb_strlen($body) > 500) {
            $errors['body'] = 'Texte obligatoire, 500 caractères au plus.';
        }
        $channels = array_values(array_intersect((array) ($j['channels'] ?? ($cur ? explode(',', $cur['channels']) : [])), ['push', 'email']));
        if ($channels === []) {
            $errors['channels'] = 'Choisis push, e-mail ou les deux.';
        }
        $target = $j['target'] ?? ($cur && $cur['target'] ? json_decode($cur['target'], true) : null);
        if ($target !== null && (!is_array($target) || !is_string($target['type'] ?? null))) {
            $errors['target'] = 'Cible invalide : { type, id? } comme AppNotice.target.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $audience = array_key_exists('audience', $j) ? Broadcasts::audience($j['audience']) : (json_decode((string) ($cur['audience'] ?? '{}'), true) ?: []);

        return ['title' => $title, 'body' => $body, 'channels' => implode(',', $channels), 'audience' => json_encode((object) $audience),
                'email_subject' => isset($j['email_subject']) ? mb_substr(trim((string) $j['email_subject']), 0, 160) : ($cur['email_subject'] ?? null),
                'email_body' => isset($j['email_body']) ? mb_substr((string) $j['email_body'], 0, 20000) : ($cur['email_body'] ?? null),
                'target' => $target === null ? null : json_encode($target, JSON_UNESCAPED_UNICODE)];
    }

    private static function present(array $b): array
    {
        return ['id' => $b['uid'], 'title' => $b['title'], 'body' => $b['body'], 'channels' => explode(',', $b['channels']), 'email_subject' => $b['email_subject'],
                'email_body' => $b['email_body'], 'audience' => json_decode($b['audience'], true) ?: (object) [], 'target' => $b['target'] ? json_decode($b['target'], true) : null,
                'status' => $b['status'], 'send_at' => Pro::iso($b['send_at']), 'sent_at' => Pro::iso($b['sent_at']),
                'stats' => ['recipients' => (int) $b['recipients'], 'pushed' => (int) $b['pushed'], 'emailed' => (int) $b['emailed']],
                'created_by' => $b['created_by'], 'created_at' => Pro::iso($b['created_at'])];
    }
}
