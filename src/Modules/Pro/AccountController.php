<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Compte du commerçant, établissement, équipe et formule (demandes 1 et 2).
 */
final class AccountController
{
    private const MODULES = ['bonsPlans', 'evenements', 'emplois', 'devis', 'logements'];
    private const ACTIVITIES = ['restaurant', 'cafe', 'fastFood', 'boulangerie', 'commerce', 'culture', 'sport', 'services', 'immobilier', 'hebergement'];

    // --- Moi ---------------------------------------------------------------

    public function me(Request $request): Response
    {
        $m = Pro::member($request);

        return Response::success(['merchant' => ProPresenter::merchant($m), 'business' => ProPresenter::business(Pro::business($request)), 'role' => $m['role']]);
    }

    public function updateMe(Request $request): Response
    {
        $j = $request->json();
        $errors = [];
        $set = [];
        foreach (['first_name' => 80, 'last_name' => 80, 'job_title' => 80, 'phone' => 30] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $set[$k] = $v;
            }
        }
        if (isset($set['first_name']) && $set['first_name'] === '') {
            $errors['first_name'] = 'Indique ton prénom.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->saveMember($request, $set);
    }

    public function updateEmail(Request $request): Response
    {
        $j = $request->json();
        $m = Pro::member($request);
        if (!is_string($j['password'] ?? null) || !password_verify($j['password'], (string) $m['password_hash'])) {
            throw new HttpException(403, 'forbidden', 'Mot de passe incorrect.', ['reason' => 'invalid_password']);
        }
        $email = mb_strtolower(trim((string) ($j['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ValidationException(['email' => 'Adresse e-mail invalide.']);
        }
        if ($email !== $m['email'] && Database::fetch('SELECT 1 FROM pro_members WHERE email = :e', ['e' => $email])) {
            throw new HttpException(409, 'conflict', 'Cet e-mail est déjà utilisé.', ['reason' => 'email_taken']);
        }

        return $this->saveMember($request, ['email' => $email]);
    }

    public function updateNotifications(Request $request): Response
    {
        $prefs = Pro::prefs(Pro::member($request)['notifications']);
        foreach ($request->json() as $k => $v) {
            if ($k === 'channel') {
                in_array($v, ['email', 'email_sms'], true) ? $prefs['channel'] = $v : throw new ValidationException(['channel' => 'Canal : email ou email_sms.']);
            } elseif (array_key_exists($k, $prefs)) {
                is_bool($v) ? $prefs[$k] = $v : throw new ValidationException([$k => 'Doit être vrai ou faux.']);
            }
        }

        return $this->saveMember($request, ['notifications' => json_encode($prefs)]);
    }

    /** RGPD : tout le compte en JSON. */
    public function export(Request $request): Response
    {
        $b = Pro::business($request);
        $id = $b['id'];
        $place = Pro::place($request);

        return Response::json([
            'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'merchant'   => \App\Core\Contract::camel(ProPresenter::merchant(Pro::member($request))),
            'business'   => \App\Core\Contract::camel(ProPresenter::business($b)),
            'team'       => \App\Core\Contract::camel(array_map([ProPresenter::class, 'member'], Database::fetchAll('SELECT * FROM pro_members WHERE business_id = :b', ['b' => $id]))),
            'place'      => \App\Core\Contract::camel(PlaceController::present($place)),
            'deals'      => \App\Core\Contract::camel(array_map([DealController::class, 'present'], Database::fetchAll('SELECT * FROM deals WHERE business_id = :b AND deleted_at IS NULL', ['b' => $id]))),
            'events'     => \App\Core\Contract::camel(array_map([EventController::class, 'present'], Database::fetchAll('SELECT * FROM events WHERE business_id = :b AND deleted_at IS NULL', ['b' => $id]))),
            'threads'    => \App\Core\Contract::camel(array_map([ThreadController::class, 'present'], Database::fetchAll('SELECT * FROM pro_threads WHERE business_id = :b AND deleted_at IS NULL', ['b' => $id]))),
            'notifications' => \App\Core\Contract::camel(array_map([ProPresenter::class, 'notification'], Database::fetchAll('SELECT * FROM pro_notifications WHERE business_id = :b ORDER BY id DESC LIMIT 500', ['b' => $id]))),
        ]);
    }

    /** Suppression du compte (propriétaire) : fiche retirée de la carte, contenus retirés, données personnelles effacées. */
    public function destroy(Request $request): Response
    {
        Pro::requireRole($request, 'proprietaire');
        $m = Pro::member($request);
        if (!is_string($request->json()['password'] ?? null) || !password_verify($request->json()['password'], (string) $m['password_hash'])) {
            throw new HttpException(403, 'forbidden', 'Mot de passe incorrect.', ['reason' => 'invalid_password']);
        }
        $b = Pro::business($request);
        $now = gmdate('Y-m-d H:i:s');
        Database::transaction(static function () use ($b, $now): void {
            Database::execute("UPDATE places SET status = 'archived', business_id = NULL, pro = NULL, pro_pending = NULL, pro_status = NULL, phone = NULL, updated_at = :d WHERE id = :p",
                ['d' => $now, 'p' => $b['place_id']]);
            Database::execute("UPDATE deals SET status = 'archived', deleted_at = :d WHERE business_id = :b", ['d' => $now, 'b' => $b['id']]);
            Database::execute("UPDATE events SET status = 'archived', deleted_at = :d WHERE business_id = :b AND status <> 'published'", ['d' => $now, 'b' => $b['id']]);
            Database::execute("UPDATE job_offers SET status = 'archived' WHERE business_id = :b", ['b' => $b['id']]);
            Database::execute("UPDATE service_providers SET status = 'archived', contact_email = NULL WHERE business_id = :b", ['b' => $b['id']]);
            Database::execute("UPDATE housing_listings SET status = 'archived', contact_email = NULL, contact_phone = NULL WHERE business_id = :b", ['b' => $b['id']]);
            Database::execute('DELETE s FROM pro_sessions s JOIN pro_members m ON m.id = s.member_id WHERE m.business_id = :b', ['b' => $b['id']]);
            Database::execute('DELETE r FROM pro_password_resets r JOIN pro_members m ON m.id = r.member_id WHERE m.business_id = :b', ['b' => $b['id']]);
            Database::execute('DELETE FROM pro_members WHERE business_id = :b', ['b' => $b['id']]);
            Database::execute('DELETE FROM pro_notifications WHERE business_id = :b', ['b' => $b['id']]);
            Database::execute("UPDATE pro_businesses SET deleted_at = :d, legal_name = '', siret = '', place_id = NULL WHERE id = :b", ['d' => $now, 'b' => $b['id']]);
        });

        return Response::noContent()->withHeader('Set-Cookie', 'mc_pro_rt=; Max-Age=0; Path=/api/v1/pro/auth; HttpOnly; Secure; SameSite=Strict');
    }

    public function notifications(Request $request): Response
    {
        [$limit, $offset] = Pro::page($request);
        $rows = Database::fetchAll('SELECT * FROM pro_notifications WHERE business_id = :b ORDER BY id DESC LIMIT ' . ($limit + 1) . " OFFSET {$offset}", ['b' => Pro::business($request)['id']]);

        return Response::success(array_map([ProPresenter::class, 'notification'], array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function readNotifications(Request $request): Response
    {
        $ids = $request->json()['ids'] ?? null;
        $params = ['b' => Pro::business($request)['id'], 'd' => gmdate('Y-m-d H:i:s')];
        $in = '';
        if (is_array($ids)) {
            $keys = [];
            foreach (array_values(array_filter($ids, 'is_string')) as $i => $id) {
                $keys[] = ':i' . $i;
                $params['i' . $i] = $id;
            }
            if ($keys === []) {
                return Response::noContent();
            }
            $in = ' AND uid IN (' . implode(', ', $keys) . ')';
        }
        Database::execute('UPDATE pro_notifications SET read_at = :d WHERE business_id = :b AND read_at IS NULL' . $in, $params);

        return Response::noContent();
    }

    // --- Établissement -----------------------------------------------------

    public function business(Request $request): Response
    {
        return Response::success(ProPresenter::business(Pro::business($request)));
    }

    /** POST /pro/business/verification/reply { message, attachmentIds? } : réponse à une demande de complément de l'équipe */
    public function verificationReply(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        if ($b['verification'] !== 'complement') {
            throw new HttpException(409, 'not_requested', 'L\'équipe ne t\'a demandé aucun complément.');
        }
        $j = $request->json();
        $message = is_string($j['message'] ?? null) ? trim($j['message']) : '';
        if (mb_strlen($message) < 10 || mb_strlen($message) > 1000) {
            throw new ValidationException(['message' => 'Ta réponse doit faire entre 10 et 1000 caractères.']);
        }
        $ids = array_slice(array_values(array_filter(is_array($j['attachment_ids'] ?? null) ? $j['attachment_ids'] : [], 'is_string')), 0, 3);
        $attachments = [];
        foreach ($ids as $id) {
            $u = Database::fetch("SELECT uid, url FROM uploads WHERE uid = :u AND purpose = 'verification' AND confirmed_at IS NOT NULL", ['u' => strtolower($id)]);
            if ($u === null) {
                throw new ValidationException(['attachmentIds' => 'Pièce jointe introuvable.']);
            }
            $attachments[] = ['id' => $u['uid'], 'url' => $u['url']];
        }
        $review = Verification::review($b);
        $member = Pro::member($request);
        Verification::log($review, 'merchant', trim($member['first_name'] . ' ' . $member['last_name']), 'complement_envoye', $message, null, $attachments);
        $review['request'] = null;
        Verification::save((int) $b['id'], $review, 'en_cours');
        Verification::mailTeam('Complément reçu : ' . $b['trade_name'], "{$b['trade_name']} a répondu :\n\n{$message}\n\n" . Verification::adminLink($b));

        return Response::success(ProPresenter::business(Database::fetch('SELECT * FROM pro_businesses WHERE id = :id', ['id' => $b['id']])));
    }

    public function updateBusiness(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        $j = $request->json();
        $errors = [];
        $set = [];
        foreach (['legal_name' => 160, 'naf_code' => 10] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $set[$k] = $v;
            }
        }
        if (array_key_exists('activity', $j)) {
            in_array($j['activity'], self::ACTIVITIES, true) ? $set['activity'] = $j['activity'] : $errors['activity'] = 'Activité inconnue.';
        }
        if (array_key_exists('siret', $j)) {
            $siret = preg_replace('/\s+/', '', (string) $j['siret']);
            if ($siret !== $b['siret']) {
                if (in_array($b['verification'], ['verifie', 'refuse'], true)) {
                    throw new HttpException(409, 'siret_locked', 'Le SIRET ne peut plus être modifié : contacte l\'équipe MyClermont.');
                }
                if (!preg_match('/^\d{14}$/', $siret)) {
                    throw new HttpException(422, 'siret_invalid', 'Le SIRET compte 14 chiffres.');
                }
                // Contrôle SIRENE et entrée au journal ; depuis a_verifier, la demande part à l'équipe
                $review = Verification::review($b);
                $review['sirene'] = Verification::sirene($siret);
                $member = Pro::member($request);
                Verification::log($review, 'merchant', trim($member['first_name'] . ' ' . $member['last_name']), 'siret_ajoute', $siret);
                Verification::log($review, 'system', 'Annuaire des entreprises', 'siret_controle', ($review['sirene']['found'] ?? false) ? (($review['sirene']['active'] ?? false) ? 'SIRET actif' : 'Établissement fermé') : 'SIRET introuvable');
                $next = $b['verification'] === 'a_verifier' ? 'en_cours' : $b['verification'];
                Verification::save((int) $b['id'], $review, $next);
                $set['siret'] = $siret;
                if (!isset($set['naf_code']) && !empty($review['sirene']['naf_code'])) {
                    $set['naf_code'] = $review['sirene']['naf_code'];
                }
                if ($b['verification'] === 'a_verifier') {
                    Verification::mailTeam('Nouvelle inscription à valider : ' . $b['trade_name'], "{$b['trade_name']} a ajouté son SIRET ({$siret}).\n\n" . Verification::adminLink($b));
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->saveBusiness($request, $set);
    }

    public function updateModules(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $modules = $request->json()['modules'] ?? null;
        if (!is_array($modules) || array_diff($modules, self::MODULES)) {
            throw new ValidationException(['modules' => 'Modules : ' . implode(', ', self::MODULES) . '.']);
        }

        return $this->saveBusiness($request, ['modules' => implode(',', array_values(array_unique($modules)))]);
    }

    // --- Équipe ------------------------------------------------------------

    public function team(Request $request): Response
    {
        return Response::success(array_map([ProPresenter::class, 'member'],
            Database::fetchAll("SELECT * FROM pro_members WHERE business_id = :b ORDER BY FIELD(role, 'proprietaire', 'gerant', 'equipier'), id", ['b' => Pro::business($request)['id']])));
    }

    public function invite(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        $j = $request->json();
        $email = mb_strtolower(trim((string) ($j['email'] ?? '')));
        $role = $j['role'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'Adresse e-mail invalide.']);
        }
        if (!in_array($role, ['gerant', 'equipier'], true)) {
            throw new ValidationException(['role' => 'Rôle : gerant ou equipier.']);
        }
        if (Database::fetch('SELECT 1 FROM pro_members WHERE email = :e', ['e' => $email])) {
            throw new HttpException(409, 'conflict', 'Cette personne a déjà un compte commerçant.', ['reason' => 'already_member']);
        }
        $count = (int) Database::fetch('SELECT COUNT(*) AS n FROM pro_members WHERE business_id = :b', ['b' => $b['id']])['n'];
        if ($count >= Pro::limits($b)['team']) {
            throw Pro::planLimit('team', 'Ta formule ne permet pas d\'ajouter un membre : passe Partenaire pour inviter jusqu\'à 5 personnes.');
        }
        [$first, $last] = array_pad(explode(' ', trim((string) ($j['name'] ?? '')), 2), 2, '');
        $token = Crypto::randomToken(32);
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert(
            "INSERT INTO pro_members (uid, business_id, email, first_name, last_name, role, status, invite_token_hash, invite_expires_at, invited_at, created_at, updated_at)
             VALUES (:u, :b, :e, :f, :l, :r, 'invite', :h, :x, :i, :c, :up)",
            ['u' => Crypto::uuid4(), 'b' => $b['id'], 'e' => $email, 'f' => mb_substr($first, 0, 80), 'l' => mb_substr($last, 0, 80), 'r' => $role,
             'h' => Crypto::hashToken($token), 'x' => gmdate('Y-m-d H:i:s', time() + (int) Config::get('pro.invite_ttl')), 'i' => $now, 'c' => $now, 'up' => $now],
        );
        self::sendInvite($email, $b['trade_name'], Pro::member($request)['first_name'], $token);

        return Response::success(ProPresenter::member(Database::fetch('SELECT * FROM pro_members WHERE id = :id', ['id' => $id])), 201);
    }

    public function resendInvite(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $m = $this->findMember($request);
        if ($m['status'] !== 'invite') {
            throw new HttpException(409, 'conflict', 'Cette personne a déjà accepté l\'invitation.', ['reason' => 'already_member']);
        }
        $token = Crypto::randomToken(32);
        Database::execute('UPDATE pro_members SET invite_token_hash = :h, invite_expires_at = :x, invited_at = :i WHERE id = :id',
            ['h' => Crypto::hashToken($token), 'x' => gmdate('Y-m-d H:i:s', time() + (int) Config::get('pro.invite_ttl')), 'i' => gmdate('Y-m-d H:i:s'), 'id' => $m['id']]);
        self::sendInvite($m['email'], Pro::business($request)['trade_name'], Pro::member($request)['first_name'], $token);

        return Response::noContent();
    }

    /** GET /pro/team/invitations/{token} (sans connexion) */
    public function invitation(Request $request): Response
    {
        $m = $this->invited((string) $request->param('token'));
        $b = Database::fetch('SELECT trade_name FROM pro_businesses WHERE id = :id', ['id' => $m['business_id']]);

        return Response::success(['business_name' => $b['trade_name'], 'role' => $m['role'], 'email' => $m['email']]);
    }

    public function acceptInvitation(Request $request): Response
    {
        $m = $this->invited((string) $request->param('token'));
        $j = $request->json();
        $first = trim((string) ($j['first_name'] ?? ''));
        if ($first === '' || mb_strlen($first) > 80 || mb_strlen((string) ($j['last_name'] ?? '')) > 80) {
            throw new ValidationException(['first_name' => 'Indique ton prénom.']);
        }
        $password = Pro::checkPassword($j['password'] ?? null);
        Database::execute("UPDATE pro_members SET status = 'actif', first_name = :f, last_name = :l, password_hash = :p, invite_token_hash = NULL, invite_expires_at = NULL, updated_at = :d WHERE id = :id",
            ['f' => $first, 'l' => trim((string) ($j['last_name'] ?? '')), 'p' => password_hash($password, PASSWORD_DEFAULT), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $m['id']]);

        return Response::noContent();
    }

    public function updateMember(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $m = $this->findMember($request);
        $role = $request->json()['role'] ?? null;
        if (!in_array($role, Pro::ROLES, true)) {
            throw new ValidationException(['role' => 'Rôle inconnu.']);
        }
        if ($m['role'] === 'proprietaire' || $role === 'proprietaire') {
            throw new HttpException(403, 'forbidden', 'Le rôle de propriétaire ne peut pas être changé.', ['reason' => 'role']);
        }
        Database::execute('UPDATE pro_members SET role = :r, updated_at = :d WHERE id = :id', ['r' => $role, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $m['id']]);

        return Response::success(ProPresenter::member(Database::fetch('SELECT * FROM pro_members WHERE id = :id', ['id' => $m['id']])));
    }

    public function removeMember(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $m = $this->findMember($request);
        if ($m['role'] === 'proprietaire') {
            throw new HttpException(403, 'forbidden', 'Le propriétaire ne peut pas être retiré.', ['reason' => 'role']);
        }
        Database::execute('DELETE FROM pro_sessions WHERE member_id = :m', ['m' => $m['id']]);
        Database::execute('DELETE FROM pro_members WHERE id = :m', ['m' => $m['id']]);

        return Response::noContent();
    }

    // --- Formule -----------------------------------------------------------

    public function partnership(Request $request): Response
    {
        $b = Pro::business($request);

        return Response::success(['plan' => $b['plan'], 'plan_since' => Pro::iso($b['plan_since']), 'limits' => ProPresenter::limits($b)]);
    }

    public function subscribe(Request $request): Response
    {
        Pro::requireRole($request, 'proprietaire');
        if (!Config::get('pro.subscribe_enabled')) {
            throw new HttpException(403, 'forbidden', 'Le passage Partenaire en ligne arrive bientôt : demande à être rappelé.', ['reason' => 'not_available']);
        }
        self::setPlan(Pro::business($request), 'partenaire');

        return $this->business(Pro::reload($request));
    }

    public function cancel(Request $request): Response
    {
        Pro::requireRole($request, 'proprietaire');
        self::setPlan(Pro::business($request), 'gratuit');

        return $this->business(Pro::reload($request));
    }

    public function contactRequest(Request $request): Response
    {
        $j = $request->json();
        $slot = mb_substr(trim((string) ($j['slot'] ?? '')), 0, 80);
        $message = mb_substr(trim((string) ($j['message'] ?? '')), 0, 1000);
        $b = Pro::business($request);
        $m = Pro::member($request);
        Database::insert('INSERT INTO pro_contact_requests (business_id, member_id, slot, message, created_at) VALUES (:b, :m, :s, :msg, :d)',
            ['b' => $b['id'], 'm' => $m['id'], 's' => $slot, 'msg' => $message, 'd' => gmdate('Y-m-d H:i:s')]);
        Pro::mail((string) Config::get('pro.team_email'), 'Demande de rappel Partenaire : ' . $b['trade_name'],
            "{$m['first_name']} {$m['last_name']} ({$m['email']}, {$m['phone']}) souhaite être rappelé·e.\nCréneau : {$slot}\n\n{$message}");

        return Response::noContent();
    }

    public function invoices(Request $request): Response
    {
        Pro::requireRole($request, 'proprietaire');
        $rows = Database::fetchAll('SELECT * FROM pro_invoices WHERE business_id = :b ORDER BY date DESC, id DESC', ['b' => Pro::business($request)['id']]);

        return Response::success(array_map(static fn (array $i) => ['id' => $i['uid'], 'number' => $i['number'], 'date' => $i['date'], 'label' => $i['label'],
            'amount_cents' => (int) $i['amount_cents'], 'status' => $i['status']], $rows));
    }

    public function invoicePdf(Request $request): Response
    {
        Pro::requireRole($request, 'proprietaire');
        $i = Database::fetch('SELECT * FROM pro_invoices WHERE uid = :u AND business_id = :b', ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']]);
        $path = $i && $i['pdf_path'] ? dirname(__DIR__, 3) . '/storage/invoices/' . basename($i['pdf_path']) : null;
        if ($path === null || !is_file($path)) {
            throw new HttpException(404, 'not_found', 'Facture introuvable.');
        }

        return Response::raw((string) file_get_contents($path), 'application/pdf')->withHeader('Content-Disposition', 'attachment; filename="' . $i['number'] . '.pdf"');
    }

    /**
     * Changement de formule. Retour au gratuit : le plus ancien bon plan actif reste en ligne, les autres passent en pause ;
     * Flash désactivé ; étoile retirée de la carte.
     */
    public static function setPlan(array $b, string $plan): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::execute('UPDATE pro_businesses SET plan = :p, plan_since = :d, updated_at = :d2 WHERE id = :id', ['p' => $plan, 'd' => $now, 'd2' => $now, 'id' => $b['id']]);
        Database::execute('UPDATE places SET is_partner = :x, partner_since = IF(:x2 = 1, COALESCE(partner_since, UTC_DATE()), NULL) WHERE id = :p',
            ['x' => (int) ($plan === 'partenaire'), 'x2' => (int) ($plan === 'partenaire'), 'p' => $b['place_id']]);
        if ($plan === 'gratuit') {
            $keep = Database::fetch("SELECT id FROM deals WHERE business_id = :b AND pro_status = 'actif' AND deleted_at IS NULL ORDER BY created_at, id LIMIT 1", ['b' => $b['id']]);
            Database::execute("UPDATE deals SET pro_status = 'en_pause', status = 'draft' WHERE business_id = :b AND pro_status = 'actif' AND id <> :k",
                ['b' => $b['id'], 'k' => $keep['id'] ?? 0]);
            Database::execute('UPDATE events SET is_flash = 0 WHERE business_id = :b', ['b' => $b['id']]);
        }
        \App\Admin\CacheInvalidator::afterWrite('places', ['id' => $b['place_id']], null);
    }

    // -----------------------------------------------------------------------

    private function saveMember(Request $request, array $set): Response
    {
        $m = Pro::member($request);
        if ($set !== []) {
            $cols = implode(', ', array_map(static fn ($k) => "{$k} = :{$k}", array_keys($set)));
            Database::execute("UPDATE pro_members SET {$cols}, updated_at = :updated WHERE id = :id", $set + ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $m['id']]);
        }

        return Response::success(ProPresenter::merchant(Database::fetch('SELECT * FROM pro_members WHERE id = :id', ['id' => $m['id']])));
    }

    private function saveBusiness(Request $request, array $set): Response
    {
        $b = Pro::business($request);
        if ($set !== []) {
            $cols = implode(', ', array_map(static fn ($k) => "{$k} = :{$k}", array_keys($set)));
            Database::execute("UPDATE pro_businesses SET {$cols}, updated_at = :updated WHERE id = :id", $set + ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $b['id']]);
        }

        return $this->business(Pro::reload($request));
    }

    private function findMember(Request $request): array
    {
        return Database::fetch('SELECT * FROM pro_members WHERE uid = :u AND business_id = :b', ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Membre introuvable.');
    }

    private function invited(string $token): array
    {
        $m = preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)
            ? Database::fetch("SELECT * FROM pro_members WHERE invite_token_hash = :h AND status = 'invite' AND invite_expires_at > UTC_TIMESTAMP()", ['h' => Crypto::hashToken($token)])
            : null;

        return $m ?? throw new HttpException(400, 'validation_failed', 'Cette invitation n\'est plus valable : demande-en une nouvelle.', ['reason' => 'invalid_token']);
    }

    private static function sendInvite(string $email, string $business, string $from, string $token): void
    {
        Pro::mail($email, "{$from} t'invite à gérer {$business} sur MyClermont Pro",
            "Bonjour,\n\n{$from} t'invite à rejoindre l'équipe de {$business} sur MyClermont Pro.\nPour accepter (lien valable 7 jours) :\n"
            . Config::get('pro.front_url') . '/invitation?token=' . $token);
    }
}
