<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Database;
use App\Modules\Pro\OfferedPlans;
use App\Modules\Staff\Broadcasts;
use App\Security\Crypto;

/**
 * Écrans du back-office demandés le 04/11 (rôle admin) :
 *   /admin/broadcasts  notifications push / e-mails à une audience filtrée (aperçu du nombre, test à l'équipe, envoi ou programmation)
 *   /admin/merchants   offrir (ou retirer) la formule Partenaire à un commerçant
 *   /admin/plus        offrir (ou retirer) My Clermont+ à un utilisateur de l'app
 */
final class AdminTools
{
    public function __construct(private readonly AdminRequest $req)
    {
    }

    // ---------------------------------------------------------------- Notifications

    public function broadcasts(?array $form = null, ?array $preview = null): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $form ??= ['title' => '', 'body' => '', 'email_subject' => '', 'email_body' => '', 'channels' => ['push'], 'target_type' => '', 'target_id' => '', 'send_at' => '', 'audience' => []];
        $preview ??= Broadcasts::preview($form['audience']);

        return [200, [], AdminController::page('Notifications', Html::render('broadcasts', [
            'form' => $form, 'preview' => $preview, 'csrf' => $this->req->session['csrf'],
            'neighborhoods' => Database::fetchAll('SELECT id, name FROM neighborhoods ORDER BY name'),
            'cities' => array_column(Database::fetchAll("SELECT city, COUNT(*) n FROM users WHERE status = 'active' AND city IS NOT NULL AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 30"), 'city'),
            'history' => Database::fetchAll('SELECT * FROM broadcasts ORDER BY id DESC LIMIT 30'),
        ]), $this->req)];
    }

    /** POST /admin/broadcasts {action: preview | test | send | cancel} */
    public function broadcastAction(): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $action = $this->req->input('action');
        if ($action === 'cancel') {
            $n = Database::execute("UPDATE broadcasts SET status = 'annule' WHERE uid = :u AND status IN ('brouillon','programme')", ['u' => $this->req->input('id')]);
            $this->flash($n ? 'success' : 'error', $n ? 'Envoi annulé.' : 'Cet envoi est déjà parti.');
            return AdminController::redirect('/broadcasts');
        }

        $form = $this->form();
        $preview = Broadcasts::preview($form['audience']);
        if ($action === 'preview') {
            return $this->broadcasts($form, $preview);
        }
        $errors = [];
        if ($form['title'] === '' || mb_strlen($form['title']) > 120) {
            $errors[] = 'Titre obligatoire (120 caractères au plus).';
        }
        if ($form['body'] === '' || mb_strlen($form['body']) > 500) {
            $errors[] = 'Texte obligatoire (500 caractères au plus).';
        }
        if ($form['channels'] === []) {
            $errors[] = 'Choisis au moins un canal : notification ou e-mail.';
        }
        $sendAt = null;
        if ($form['send_at'] !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $form['send_at'], new \DateTimeZone('Europe/Paris'));
            $dt === false ? $errors[] = 'Date de programmation invalide.' : $sendAt = $dt->getTimestamp();
        }
        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->broadcasts($form, $preview);
        }

        $target = $form['target_type'] !== '' ? ['type' => $form['target_type']] + ($form['target_id'] !== '' ? ['id' => $form['target_id']] : []) : null;
        $row = ['title' => $form['title'], 'body' => $form['body'], 'email_subject' => $form['email_subject'] ?: null, 'email_body' => $form['email_body'] ?: null,
                'channels' => implode(',', $form['channels']), 'target' => $target === null ? null : json_encode($target, JSON_UNESCAPED_UNICODE)];

        if ($action === 'test') {
            [$n, $p, $m] = Broadcasts::send(['id' => 0, 'audience' => json_encode(['staff_only' => true])] + $row);
            $this->flash('success', "Test envoyé à l'équipe (comptes marqués « staff ») : {$n} destinataire(s), {$p} notification(s), {$m} e-mail(s).");
            return $this->broadcasts($form, $preview);
        }
        if ($action !== 'send') {
            return AdminController::redirect('/broadcasts');
        }
        if ($preview['total'] === 0) {
            $this->flash('error', 'Aucun destinataire ne correspond à ces filtres.');
            return $this->broadcasts($form, $preview);
        }
        $uid = Crypto::uuid4();
        Database::insert("INSERT INTO broadcasts (uid, title, body, email_subject, email_body, channels, audience, target, status, send_at, created_by, created_at)
                          VALUES (:u, :t, :b, :es, :eb, :c, :a, :tg, 'programme', :sa, :by, UTC_TIMESTAMP())",
            ['u' => $uid, 't' => $row['title'], 'b' => $row['body'], 'es' => $row['email_subject'], 'eb' => $row['email_body'], 'c' => $row['channels'],
             'a' => json_encode((object) $form['audience'], JSON_UNESCAPED_UNICODE), 'tg' => $row['target'],
             'sa' => gmdate('Y-m-d H:i:s', max(time(), $sendAt ?? time())), 'by' => (string) $this->req->admin['name']]);
        Audit::log((int) $this->req->admin['id'], 'broadcast.send', $this->req->ip, 'broadcasts', $uid, ['audience' => $form['audience'], 'preview' => $preview]);
        $this->flash('success', ($sendAt !== null && $sendAt > time() ? 'Envoi programmé' : 'Envoi lancé (dans la minute)') . " : {$preview['total']} destinataire(s).");

        return AdminController::redirect('/broadcasts');
    }

    /** Formulaire → audience au format de Broadcasts::where */
    private function form(): array
    {
        $p = $this->req->post;
        $list = static fn (string $k) => array_values(array_filter(array_map('trim', is_array($p[$k] ?? null) ? $p[$k] : explode(',', (string) ($p[$k] ?? ''))), static fn ($v) => $v !== ''));
        $int = static fn (string $k) => isset($p[$k]) && ctype_digit((string) $p[$k]) && (int) $p[$k] > 0 ? (int) $p[$k] : null;
        $a = array_filter([
            'platforms' => array_values(array_intersect($list('platforms'), ['ios', 'android'])),
            'cities' => $list('cities'), 'postal_codes' => $list('postal_codes'),
            'neighborhoods' => array_map('intval', $list('neighborhoods')),
            'genders' => $list('genders'),
            'favorite_categories' => $list('favorite_categories'),
            'active_within_days' => $int('active_within_days'), 'inactive_for_days' => $int('inactive_for_days'),
            'created_within_days' => $int('created_within_days'), 'min_points' => $int('min_points'),
            'staff_only' => !empty($p['staff_only']) ? true : null,
            'push_only' => !empty($p['push_only']) ? true : null,
            'community_active_days' => $int('community_active_days'),
        ], static fn ($v) => $v !== null && $v !== []);
        if (in_array($p['plus'] ?? '', ['1', '0'], true)) {
            $a['plus'] = $p['plus'] === '1';
        }

        return [
            'title' => trim((string) ($p['title'] ?? '')), 'body' => trim((string) ($p['body'] ?? '')),
            'email_subject' => mb_substr(trim((string) ($p['email_subject'] ?? '')), 0, 160), 'email_body' => mb_substr((string) ($p['email_body'] ?? ''), 0, 20000),
            'channels' => array_values(array_intersect($list('channels'), ['push', 'email'])),
            'target_type' => in_array($p['target_type'] ?? '', ['place', 'event', 'deal', 'post', 'url'], true) ? $p['target_type'] : '',
            'target_id' => mb_substr(trim((string) ($p['target_id'] ?? '')), 0, 300),
            'send_at' => trim((string) ($p['send_at'] ?? '')),
            'audience' => $a,
        ];
    }

    // ---------------------------------------------------------------- Formule Partenaire offerte

    public function merchants(): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $q = trim($this->req->input('q'));
        $plan = $this->req->input('plan');
        $where = 'b.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (b.trade_name LIKE :q OR b.legal_name LIKE :q2 OR b.siret LIKE :q3 OR EXISTS (SELECT 1 FROM pro_members m WHERE m.business_id = b.id AND m.email LIKE :q4))';
            $params += ['q' => "%{$q}%", 'q2' => "%{$q}%", 'q3' => "%{$q}%", 'q4' => "%{$q}%"];
        }
        if (in_array($plan, ['gratuit', 'partenaire'], true)) {
            $where .= ' AND b.plan = :p';
            $params['p'] = $plan;
        }
        $rows = Database::fetchAll("SELECT b.*, (SELECT m.email FROM pro_members m WHERE m.business_id = b.id AND m.role = 'proprietaire' ORDER BY m.id LIMIT 1) AS owner_email
                                    FROM pro_businesses b WHERE {$where} ORDER BY b.plan = 'partenaire' DESC, b.trade_name LIMIT 200", $params);

        return [200, [], AdminController::page('Commerçants', Html::render('merchants', ['rows' => $rows, 'q' => $q, 'plan' => $plan, 'csrf' => $this->req->session['csrf']]), $this->req)];
    }

    /** POST /admin/merchants {id, action: offer | revoke, months, note} */
    public function merchantAction(): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $b = Database::fetch('SELECT * FROM pro_businesses WHERE uid = :u AND deleted_at IS NULL', ['u' => $this->req->input('id')]);
        if ($b === null) {
            $this->flash('error', 'Commerçant introuvable.');
            return AdminController::redirect('/merchants');
        }
        try {
            if ($this->req->input('action') === 'revoke') {
                OfferedPlans::revoke($b);
                $this->flash('success', "{$b['trade_name']} repasse en formule gratuite.");
            } else {
                $months = (int) $this->req->input('months', '1');
                OfferedPlans::offer($b, $months, (string) $this->req->admin['name'], $this->req->input('note'));
                $this->flash('success', "Formule Partenaire offerte à {$b['trade_name']} (" . OfferedPlans::DURATIONS[$months] . ').');
            }
            Audit::log((int) $this->req->admin['id'], 'merchant.plan.' . $this->req->input('action', 'offer'), $this->req->ip, 'pro_businesses', $b['uid'],
                ['months' => $this->req->input('months'), 'note' => $this->req->input('note')]);
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }

        return AdminController::redirect('/merchants', array_filter(['q' => $this->req->input('q')]));
    }

    // ---------------------------------------------------------------- My Clermont+ offert

    public function plus(): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $q = trim($this->req->input('q'));
        $filter = $this->req->input('filter');
        $where = "u.status = 'active'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.handle LIKE :q OR u.email LIKE :q2 OR u.first_name LIKE :q3 OR u.uid = :q4)';
            $params += ['q' => '%' . ltrim($q, '@') . '%', 'q2' => "%{$q}%", 'q3' => "%{$q}%", 'q4' => $q];
        }
        if ($filter === 'gift') {
            $where .= ' AND u.plus_gift_until > UTC_TIMESTAMP()';
        } elseif ($filter === 'member') {
            $where .= ' AND u.premium_until > UTC_TIMESTAMP()';
        }
        $rows = $q === '' && $filter === '' ? [] : Database::fetchAll(
            "SELECT u.id, u.uid, u.handle, u.email, u.first_name, u.city, u.created_at, u.premium_until, u.premium_source, u.plus_gift_until, u.plus_gift_by, u.plus_gift_note
             FROM users u WHERE {$where} ORDER BY u.premium_until > UTC_TIMESTAMP() DESC, u.id DESC LIMIT 100", $params);
        $stats = Database::fetch("SELECT SUM(premium_until > UTC_TIMESTAMP()) AS members, SUM(plus_gift_until > UTC_TIMESTAMP()) AS gifts FROM users WHERE status = 'active'");

        return [200, [], AdminController::page('My Clermont+', Html::render('plus', ['rows' => $rows, 'q' => $q, 'filter' => $filter, 'stats' => $stats, 'csrf' => $this->req->session['csrf']]), $this->req)];
    }

    /** POST /admin/plus {id, action: offer | revoke, months, note} */
    public function plusAction(): array
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        $u = Database::fetch("SELECT * FROM users WHERE uid = :u AND status = 'active'", ['u' => $this->req->input('id')]);
        $back = AdminController::redirect('/plus', array_filter(['q' => $this->req->input('q'), 'filter' => $this->req->input('filter')]));
        if ($u === null) {
            $this->flash('error', 'Utilisateur introuvable.');
            return $back;
        }
        $name = $u['handle'] ? '@' . $u['handle'] : (string) $u['email'];
        try {
            if ($this->req->input('action') === 'revoke') {
                \App\Modules\Premium\PlusGifts::revoke($u);
                $this->flash('success', "My Clermont+ offert retiré à {$name} (un abonnement App Store éventuel reste actif).");
            } else {
                $months = (int) $this->req->input('months', '1');
                \App\Modules\Premium\PlusGifts::offer($u, $months, (string) $this->req->admin['name'], $this->req->input('note'));
                $this->flash('success', "My Clermont+ offert à {$name} (" . \App\Modules\Premium\PlusGifts::DURATIONS[$months] . ') : il reçoit une notification.');
            }
            Audit::log((int) $this->req->admin['id'], 'user.plus.' . $this->req->input('action', 'offer'), $this->req->ip, 'users', $u['uid'],
                ['months' => $this->req->input('months'), 'note' => $this->req->input('note')]);
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $back;
    }

    private function allowed(): bool
    {
        return ($this->req->admin['role'] ?? '') === 'admin';
    }

    private function forbidden(): array
    {
        return [403, [], AdminController::page('Accès refusé', '<div class="card"><p>Réservé au rôle admin.</p></div>', $this->req)];
    }

    private function flash(string $type, string $message): void
    {
        $this->req->session['data']['flash'][] = [$type, $message];
        AdminAuth::saveData($this->req->session);
    }
}
