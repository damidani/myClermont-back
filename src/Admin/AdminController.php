<?php

declare(strict_types=1);

namespace App\Admin;

use App\Cache\Cache;
use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Pages du back-office. Chaque action retourne [statut, en-têtes, corps HTML].
 */
final class AdminController
{
    private const LOGIN_COOKIE = 'mc_admin_login';

    public function __construct(private readonly AdminRequest $req)
    {
    }

    // ---------------------------------------------------------------- Connexion

    public function loginForm(?string $error = null, int $status = 200): array
    {
        if (AdminAuth::session($this->req->cookies[AdminAuth::COOKIE] ?? null) !== null) {
            return self::redirect('/');
        }
        $csrf = bin2hex(random_bytes(16));
        $this->loginCookie($csrf);

        return [$status, [], self::page('Connexion', Html::render('login', ['csrf' => $csrf, 'error' => $error, 'email' => $this->req->input('email')]), $this->req, bare: true)];
    }

    public function login(): array
    {
        if (!$this->loginCsrfValid()) {
            return $this->loginForm('Le formulaire a expiré, recommencez.', 419);
        }
        $result = AdminAuth::attempt($this->req->input('email'), $this->req->input('password'), $this->req->ip);

        return match ($result['status']) {
            'ok'       => $this->openSession($result['admin']),
            '2fa'      => $this->totpForm($result['admin']),
            'locked'   => $this->loginForm('Trop de tentatives. Réessayez dans 15 minutes.', 429),
            'disabled' => $this->loginForm('Ce compte est désactivé.', 403),
            default    => $this->loginForm('Email ou mot de passe incorrect.', 401),
        };
    }

    public function loginTotp(): array
    {
        $pending = $this->req->input('pending');
        $adminId = preg_match('/^[a-f0-9]{64}$/', $pending) ? Cache::store()->get('admin:2fa:' . $pending) : null;
        if (!$this->loginCsrfValid() || !is_int($adminId)) {
            return $this->loginForm('Délai dépassé, reconnectez-vous.', 419);
        }
        $admin = Database::fetch("SELECT * FROM admin_users WHERE id = :id AND status = 'active'", ['id' => $adminId]);
        if ($admin === null) {
            return $this->loginForm('Ce compte est désactivé.', 403);
        }
        if (!AdminAuth::verifyTotp($admin, $this->req->input('code'), $this->req->ip)) {
            return $this->totpForm($admin, 'Code incorrect ou déjà utilisé.', $pending);
        }
        Cache::store()->delete('admin:2fa:' . $pending);

        return $this->openSession($admin);
    }

    public function logout(): array
    {
        AdminAuth::logout($this->req->session);
        AdminAuth::cookie('', -1);
        Audit::log($this->req->admin['id'], 'logout', $this->req->ip);

        return self::redirect('/login');
    }

    private function totpForm(array $admin, ?string $error = null, ?string $pending = null): array
    {
        if ($pending === null) {
            $pending = bin2hex(random_bytes(32));
            Cache::store()->set('admin:2fa:' . $pending, (int) $admin['id'], 300);
        }
        $csrf = bin2hex(random_bytes(16));
        $this->loginCookie($csrf);

        return [$error ? 401 : 200, [], self::page('Double authentification', Html::render('login_2fa', ['csrf' => $csrf, 'pending' => $pending, 'error' => $error]), $this->req, bare: true)];
    }

    private function openSession(array $admin): array
    {
        AdminAuth::cookie(AdminAuth::login($admin, $this->req->ip, $this->req->userAgent), (int) Config::get('admin.session_ttl', 28800));
        setcookie(self::LOGIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/admin']);

        return self::redirect('/');
    }

    /** Avant connexion, pas de session : CSRF par double envoi (cookie SameSite=Strict + champ caché). */
    private function loginCookie(string $csrf): void
    {
        headers_sent() || setcookie(self::LOGIN_COOKIE, $csrf, ['expires' => time() + 1800, 'path' => '/admin', 'secure' => Config::get('app.env') !== 'local', 'httponly' => true, 'samesite' => 'Strict']);
    }

    private function loginCsrfValid(): bool
    {
        $cookie = (string) ($this->req->cookies[self::LOGIN_COOKIE] ?? '');

        return $cookie !== '' && hash_equals($cookie, $this->req->input('_csrf'));
    }

    // ---------------------------------------------------------------- Tableau de bord

    public function dashboard(): array
    {
        $role = $this->req->admin['role'];
        $can = static fn (string $t) => ($r = Resource::get($t)) !== null && $r->allows($role);
        $count = static function (string $sql, array $p = []): ?int {
            try {
                return (int) Database::fetch($sql, $p)['n'];
            } catch (\PDOException) {
                return null; // module non installé
            }
        };
        $week = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $now = gmdate('Y-m-d H:i:s');

        // Ce que l'app affiche en ce moment, par rubrique
        $stats = [];
        foreach ([
            ['events', '🎉', 'Événements à venir', "SELECT COUNT(*) n FROM events WHERE status = 'published' AND deleted_at IS NULL AND ends_at >= UTC_TIMESTAMP()"],
            ['places', '📍', 'Lieux sur la carte', "SELECT COUNT(*) n FROM places WHERE status = 'published' AND deleted_at IS NULL"],
            ['deals', '🏷️', 'Bons plans en cours', "SELECT COUNT(*) n FROM deals WHERE status = 'published' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"],
            ['job_offers', '💼', 'Offres d\'emploi', "SELECT COUNT(*) n FROM job_offers WHERE status = 'published'"],
            ['housing_listings', '🏠', 'Logements', "SELECT COUNT(*) n FROM housing_listings WHERE status = 'published'"],
            ['service_providers', '🛠️', 'Professionnels', "SELECT COUNT(*) n FROM service_providers WHERE status = 'published'"],
            ['community_posts', '💬', 'Publications', "SELECT COUNT(*) n FROM community_posts WHERE status = 'visible'"],
            ['users', '👤', 'Comptes', "SELECT COUNT(*) n FROM users WHERE status = 'active'"],
            ['users', '🆕', 'Inscrits sur 7 jours', 'SELECT COUNT(*) n FROM users WHERE created_at > :d'],
        ] as [$table, $icon, $label, $sql]) {
            if ($can($table)) {
                $stats[] = [$icon . ' ' . $label, $count($sql, str_contains($sql, ':d') ? ['d' => $week] : []), $table];
            }
        }

        // Raccourcis de création
        $quick = [];
        foreach ([['events', 'Un événement'], ['deals', 'Un bon plan'], ['places', 'Un lieu'], ['job_offers', 'Une offre d\'emploi'], ['housing_listings', 'Un logement']] as [$table, $label]) {
            if ($can($table) && Resource::get($table)?->canCreate()) {
                $quick[] = [$label, $table];
            }
        }

        $todo = [];
        $pending = Moderation::count((string) $role);
        foreach ([
            ['housing_contact_requests', 'Emails de contact logement en échec', "SELECT COUNT(*) n FROM housing_contact_requests WHERE status = 'failed'", ['status' => 'failed']],
            ['job_applications', 'Candidatures en échec d\'envoi', "SELECT COUNT(*) n FROM job_applications WHERE status = 'failed'", ['status' => 'failed']],
            ['service_quote_requests', 'Demandes de devis en échec d\'envoi', "SELECT COUNT(*) n FROM service_quote_requests WHERE status = 'failed'", ['status' => 'failed']],
            ['events', 'Événements en brouillon', "SELECT COUNT(*) n FROM events WHERE status = 'draft' AND deleted_at IS NULL", ['status' => 'draft']],
            ['agent_messages', 'Demandes du Claude de l\'app à traiter', "SELECT COUNT(*) n FROM agent_messages WHERE recipient = 'backend' AND status IN ('open', 'in_progress') AND kind IN ('request', 'bug')", ['status' => 'open']],
        ] as [$table, $label, $sql, $filter]) {
            if ($can($table) && ($n = $count($sql)) > 0) {
                $todo[] = [$label, $n, $table, $filter];
            }
        }

        // Services externes : configurés ou non (les fonctions de l'app correspondantes répondent « indisponible » sinon)
        $services = $role === 'admin' ? [
            ['Info trafic (TomTom)', Config::get('mobility.tomtom_key') !== ''],
            ['Clermont IA (Mistral)', Config::get('assistant.api_key') !== ''],
            ['Notifications iOS (APNs)', (string) Config::get('push.apns.key_id', '') !== ''],
            ['Notifications Android (Firebase)', (string) Config::get('push.fcm.service_account', '') !== ''],
            ['Connexion Google', (array) Config::get('auth.google.client_ids', []) !== []],
            ['Envoi d\'e-mails (SMTP)', is_file('/etc/msmtprc') || is_file((string) ini_get('sendmail_path'))],
        ] : [];
        $agents = $can('agent_presence') ? Database::fetchAll('SELECT agent, last_seen_at, app_version, last_report FROM agent_presence ORDER BY last_seen_at DESC') : [];

        $imports = $can('import_runs') ? Database::fetchAll(
            'SELECT r.target, r.status, r.started_at, r.created, r.updated, r.errors, s.name FROM import_runs r JOIN content_sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 6',
        ) : [];
        $audit = $role === 'admin' ? Database::fetchAll(
            'SELECT l.action, l.resource, l.record_key, l.created_at, a.name FROM admin_audit_log l LEFT JOIN admin_users a ON a.id = l.admin_id ORDER BY l.id DESC LIMIT 10',
        ) : [];

        return [200, [], self::page('Tableau de bord', Html::render('dashboard', compact('stats', 'todo', 'imports', 'audit', 'role', 'quick', 'pending', 'services', 'agents') + ['csrf' => $this->req->session['csrf']]), $this->req)];
    }

    /** GET /admin/moderation — tout ce qui attend une décision, avec un bouton par décision */
    public function moderation(): array
    {
        $queue = Moderation::queue((string) $this->req->admin['role']);

        return [200, [], self::page('À modérer', Html::render('moderation', ['queue' => $queue, 'csrf' => $this->req->session['csrf']]), $this->req)];
    }

    /** POST /admin/moderation {type, id, action} */
    public function moderate(): array
    {
        try {
            $this->flash('success', Moderation::decide((string) $this->req->input('type'), (string) $this->req->input('id'), (string) $this->req->input('action'),
                $this->req->admin, $this->req->ip));
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }

        return self::redirect('/moderation');
    }

    public function clearCache(): array
    {
        if ($this->req->admin['role'] !== 'admin') {
            return $this->forbidden();
        }
        CacheInvalidator::all();
        Audit::log($this->req->admin['id'], 'cache_clear', $this->req->ip);
        $this->flash('success', 'Caches vidés : l\'app affiche les données à jour.');

        return self::redirect('/');
    }

    // ---------------------------------------------------------------- Mon compte

    public function account(): array
    {
        $admin = Database::fetch('SELECT id, email, name, role, totp_secret IS NOT NULL AS has_2fa FROM admin_users WHERE id = :id', ['id' => $this->req->admin['id']]);
        $setup = $this->req->session['data']['totp_setup'] ?? null;
        $sessions = Database::fetchAll('SELECT id_hash, ip, user_agent, created_at, last_seen_at FROM admin_sessions WHERE admin_id = :a ORDER BY last_seen_at DESC', ['a' => $admin['id']]);

        return [200, [], self::page('Mon compte', Html::render('account', [
            'admin' => $admin, 'setup' => $setup, 'setupUri' => $setup ? Totp::uri($setup, $admin['email']) : null,
            'sessions' => $sessions, 'current' => $this->req->session['id_hash'], 'required' => (bool) Config::get('admin.require_2fa'),
        ]), $this->req)];
    }

    public function changePassword(): array
    {
        $row = Database::fetch('SELECT password_hash FROM admin_users WHERE id = :id', ['id' => $this->req->admin['id']]);
        $new = $this->req->input('new_password');
        if (!password_verify($this->req->input('current_password'), $row['password_hash'])) {
            $this->flash('error', 'Mot de passe actuel incorrect.');
        } elseif (mb_strlen($new) < 12 || $new !== $this->req->input('confirm_password')) {
            $this->flash('error', 'Le nouveau mot de passe doit faire 12 caractères minimum et être confirmé à l\'identique.');
        } else {
            Database::execute('UPDATE admin_users SET password_hash = :h, updated_at = :d WHERE id = :id',
                ['h' => password_hash($new, PASSWORD_ARGON2ID), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $this->req->admin['id']]);
            // Les autres sessions (peut-être volées) sont fermées
            Database::execute('DELETE FROM admin_sessions WHERE admin_id = :a AND id_hash <> :h', ['a' => $this->req->admin['id'], 'h' => $this->req->session['id_hash']]);
            Audit::log($this->req->admin['id'], 'password_changed', $this->req->ip);
            $this->flash('success', 'Mot de passe modifié. Vos autres sessions ont été fermées.');
        }

        return self::redirect('/account');
    }

    /** Activer (2 étapes : secret affiché puis code de confirmation) ou désactiver la double authentification. */
    public function twoFactor(): array
    {
        $id = $this->req->admin['id'];
        $action = $this->req->input('action');
        if ($action === 'start') {
            $this->req->session['data']['totp_setup'] = Totp::generateSecret();
            AdminAuth::saveData($this->req->session);
        } elseif ($action === 'confirm' && ($secret = $this->req->session['data']['totp_setup'] ?? null) !== null) {
            if (Totp::verify($secret, $this->req->input('code')) === null) {
                $this->flash('error', 'Code incorrect : vérifiez l\'heure du téléphone et réessayez.');
            } else {
                Database::execute('UPDATE admin_users SET totp_secret = :s, updated_at = :d WHERE id = :id', ['s' => Crypto::encrypt($secret), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
                unset($this->req->session['data']['totp_setup']);
                Audit::log($id, '2fa_enabled', $this->req->ip);
                $this->flash('success', 'Double authentification activée.');
            }
        } elseif ($action === 'disable') {
            $admin = Database::fetch('SELECT * FROM admin_users WHERE id = :id', ['id' => $id]);
            if (Config::get('admin.require_2fa')) {
                $this->flash('error', 'La double authentification est obligatoire sur ce back-office.');
            } elseif ($admin['totp_secret'] === null || !AdminAuth::verifyTotp($admin, $this->req->input('code'), $this->req->ip)) {
                $this->flash('error', 'Code incorrect.');
            } else {
                Database::execute('UPDATE admin_users SET totp_secret = NULL, updated_at = :d WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
                Audit::log($id, '2fa_disabled', $this->req->ip);
                $this->flash('success', 'Double authentification désactivée.');
            }
        }

        return self::redirect('/account');
    }

    public function revokeSessions(): array
    {
        Database::execute('DELETE FROM admin_sessions WHERE admin_id = :a AND id_hash <> :h', ['a' => $this->req->admin['id'], 'h' => $this->req->session['id_hash']]);
        $this->flash('success', 'Toutes vos autres sessions ont été fermées.');

        return self::redirect('/account');
    }

    // ---------------------------------------------------------------- Envoi d'image

    /** Image envoyée depuis un formulaire → public/media/admin/AAAA/MM/… ; renvoie son URL (JSON). */
    public function upload(): array
    {
        $json = static fn (int $s, array $d) => [$s, ['Content-Type' => 'application/json'], json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        if ($this->req->admin['role'] === 'moderator') {
            return $json(403, ['error' => 'Action non autorisée.']);
        }
        $file = $this->req->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $json(400, ['error' => 'Envoi échoué.']);
        }
        if ($file['size'] > (int) Config::get('admin.max_image_size', 8388608)) {
            return $json(413, ['error' => 'Image trop lourde (8 Mo maximum).']);
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        // Le type est lu dans les octets du fichier, jamais dans son nom ni dans l'en-tête envoyé
        $mime = MediaStore::detect($bytes);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' ? 'webp' : null,
        };
        if ($ext === null) {
            return $json(415, ['error' => 'Formats acceptés : JPEG, PNG, WebP.']);
        }
        $bytes = match ($ext) {
            'jpg'   => MediaStore::stripJpegMetadata($bytes),
            'png'   => MediaStore::stripPngMetadata($bytes),
            default => $bytes,
        };
        $rel = Config::get('admin.upload_dir', 'admin') . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $path = MediaStore::root() . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $bytes, LOCK_EX);
        Audit::log($this->req->admin['id'], 'upload', $this->req->ip, null, $rel);

        return $json(201, ['url' => MediaStore::url($rel)]);
    }

    // ---------------------------------------------------------------- Ressources (CRUD)

    public function resource(string $table, string $action): array
    {
        $r = Resource::get($table);
        if ($r === null) {
            return [404, [], self::page('Introuvable', '<div class="card"><p>Ressource inconnue.</p></div>', $this->req)];
        }
        if (!$r->allows($this->req->admin['role'])) {
            return $this->forbidden();
        }
        $crud = new Crud($r);
        $post = $this->req->method === 'POST';
        if ($post && $r->readonly()) {
            return $this->forbidden();
        }

        return match ($action) {
            'list'       => $this->index($r, $crud),
            'export.csv' => $this->export($r, $crud),
            'new'        => $r->canCreate() ? ($post ? $this->store($r, $crud) : $this->form($r, $crud, null, [], [])) : $this->forbidden(),
            'edit'       => $post ? $this->save($r, $crud) : $this->edit($r, $crud),
            'delete'     => $post ? $this->destroy($r, $crud) : self::redirect('/r/' . $table),
            default      => $this->forbidden(),
        };
    }

    private function index(Resource $r, Crud $crud): array
    {
        $filters = array_filter(is_array($this->req->query['f'] ?? null) ? $this->req->query['f'] : [], 'is_string');
        $sort = $this->req->input('sort') ?: null;
        $dir = $this->req->input('dir', 'desc');
        $result = $crud->paginate($this->req->input('q'), $filters, $sort, $dir, (int) $this->req->input('page', '1'));
        $columns = $r->listColumns();

        return [200, [], self::page($r->label, Html::render('list', [
            'r' => $r, 'result' => $result, 'columns' => $columns, 'labels' => $crud->relationLabels($result['rows'], $columns),
            'filterOptions' => $crud->filterOptions(), 'filters' => $filters, 'q' => $this->req->input('q'), 'sort' => $sort, 'dir' => $dir,
            'csrf' => $this->req->session['csrf'],
        ]), $this->req)];
    }

    private function export(Resource $r, Crud $crud): array
    {
        $filters = array_filter(is_array($this->req->query['f'] ?? null) ? $this->req->query['f'] : [], 'is_string');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 correctement
        fputcsv($out, array_keys($r->fields), ';', '"', '');
        $total = 0;
        for ($page = 1; $page <= 200; $page++) {
            $result = $crud->paginate($this->req->input('q'), $filters, 'id' === $r->primary()[0] ? 'id' : null, 'asc', $page);
            foreach ($result['rows'] as $row) {
                // Une cellule commençant par = + - @ serait exécutée comme formule par le tableur
                fputcsv($out, array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                    array_intersect_key($row, $r->fields)), ';', '"', '');
                $total++;
            }
            if ($page >= $result['pages']) {
                break;
            }
        }
        rewind($out);
        Audit::log($this->req->admin['id'], 'export', $this->req->ip, $r->table, null, ['rows' => $total]);

        return [200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $r->table . '-' . date('Y-m-d') . '.csv"'], (string) stream_get_contents($out)];
    }

    private function edit(Resource $r, Crud $crud): array
    {
        $key = $r->parseKey($this->req->input('key'));
        $row = $key !== null ? $crud->find($key) : null;
        if ($row === null) {
            return [404, [], self::page('Introuvable', '<div class="card"><p>Cet élément n\'existe pas (ou plus).</p></div>', $this->req)];
        }

        return $this->form($r, $crud, $row, [], $row);
    }

    private function store(Resource $r, Crud $crud): array
    {
        try {
            $key = Database::transaction(fn () => $crud->create($this->req->post));
        } catch (ValidationException $e) {
            return $this->form($r, $crud, null, (array) $e->details, $this->req->post, 422);
        } catch (\PDOException $e) {
            return $this->form($r, $crud, null, ['_' => self::sqlError($e)], $this->req->post, 422);
        }
        $row = $crud->find((array) $r->parseKey($key));
        Audit::log($this->req->admin['id'], 'create', $this->req->ip, $r->table, $key, array_intersect_key((array) $row, $r->fields));
        CacheInvalidator::afterWrite($r->table, null, $row);
        $this->flash('success', 'Élément créé.');

        return self::redirect('/r/' . $r->table . '/edit', ['key' => $key]);
    }

    private function save(Resource $r, Crud $crud): array
    {
        $key = $r->parseKey($this->req->input('key'));
        $row = $key !== null ? $crud->find($key) : null;
        if ($row === null) {
            return self::redirect('/r/' . $r->table);
        }
        // Un admin ne peut pas se retirer ses propres droits ni se désactiver (évite de verrouiller le back-office)
        if ($r->table === 'admin_users' && (int) $row['id'] === $this->req->admin['id']
            && ($this->req->input('role', $row['role']) !== $row['role'] || $this->req->input('status', $row['status']) !== 'active')) {
            return $this->form($r, $crud, $row, ['role' => 'Vous ne pouvez pas modifier votre propre rôle ou statut.'], $this->req->post + $row, 422);
        }
        try {
            $changes = Database::transaction(fn () => $crud->update($key, $row, $this->req->post));
        } catch (ValidationException $e) {
            return $this->form($r, $crud, $row, (array) $e->details, $this->req->post + $row, 422);
        } catch (\PDOException $e) {
            return $this->form($r, $crud, $row, ['_' => self::sqlError($e)], $this->req->post + $row, 422);
        }
        if ($changes !== []) {
            $after = array_merge($row, array_map(static fn ($c) => $c[1], $changes));
            Audit::log($this->req->admin['id'], 'update', $this->req->ip, $r->table, $this->req->input('key'), $changes);
            CacheInvalidator::afterWrite($r->table, $row, $after);
            $this->flash('success', count($changes) . ' modification(s) enregistrée(s).');
        } else {
            $this->flash('info', 'Aucune modification.');
        }

        return self::redirect('/r/' . $r->table . '/edit', ['key' => $this->req->input('key')]);
    }

    private function destroy(Resource $r, Crud $crud): array
    {
        $key = $r->parseKey($this->req->input('key'));
        $row = $key !== null ? $crud->find($key) : null;
        if ($row === null) {
            return self::redirect('/r/' . $r->table);
        }
        if ($r->table === 'admin_users' && (int) $row['id'] === $this->req->admin['id']) {
            $this->flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return self::redirect('/r/' . $r->table);
        }
        try {
            $crud->delete($key);
        } catch (\PDOException $e) {
            $this->flash('error', self::sqlError($e));
            return self::redirect('/r/' . $r->table . '/edit', ['key' => $this->req->input('key')]);
        }
        Audit::log($this->req->admin['id'], 'delete', $this->req->ip, $r->table, $this->req->input('key'), array_intersect_key($row, $r->fields));
        CacheInvalidator::afterWrite($r->table, $row, null);
        $this->flash('success', 'Élément supprimé.');

        return self::redirect('/r/' . $r->table);
    }

    private function form(Resource $r, Crud $crud, ?array $row, array $errors, array $values, int $status = 200): array
    {
        $options = [];
        $relationLabels = [];
        foreach ($r->fields as $name => $f) {
            if ($f['kind'] === 'relation') {
                $options[$name] = Crud::relationOptions($f['fk']);
                if ($options[$name] === null && $row !== null) {
                    $relationLabels[$name] = $crud->relationLabels([$row], [$name])[$name] ?? [];
                }
            }
        }
        $key = $row !== null ? $r->keyOf($row) : null;
        $title = $row !== null ? (string) ($row[$r->titleColumn()] ?? $key) : 'Nouveau';

        return [$status, [], self::page($title . ' · ' . $r->label, Html::render('form', [
            'r' => $r, 'row' => $row, 'key' => $key, 'values' => $values, 'errors' => $errors, 'options' => $options, 'relationLabels' => $relationLabels,
            'title' => $title, 'csrf' => $this->req->session['csrf'], 'history' => $key !== null ? Audit::history($r->table, $key) : [],
            'readonly' => $r->readonly(), 'posted' => $this->req->post,
        ]), $this->req)];
    }

    // ---------------------------------------------------------------- Utilitaires

    /** Page complète (menu + messages flash) ; $bare = sans menu (connexion). */
    public static function page(string $title, string $content, AdminRequest $req, bool $bare = false): string
    {
        $flash = [];
        if ($req->session !== null && isset($req->session['data']['flash'])) {
            $flash = $req->session['data']['flash'];
            unset($req->session['data']['flash']);
            AdminAuth::saveData($req->session);
        }

        return Html::render('layout', [
            'title' => $title, 'content' => $content, 'bare' => $bare || $req->admin === null, 'admin' => $req->admin, 'flash' => $flash,
            'menu' => $req->admin !== null ? Resource::menu($req->admin['role']) : [], 'csrf' => $req->session['csrf'] ?? '', 'path' => $req->path,
            'pending' => $req->admin !== null && !$bare ? Moderation::count((string) $req->admin['role']) : 0,
        ]);
    }

    public static function redirect(string $path, array $query = []): array
    {
        return [303, ['Location' => Html::url($path, $query)], ''];
    }

    private function flash(string $type, string $message): void
    {
        $this->req->session['data']['flash'][] = [$type, $message];
        AdminAuth::saveData($this->req->session);
    }

    private function forbidden(): array
    {
        return [403, [], self::page('Accès refusé', '<div class="card"><p>Votre rôle ne permet pas cette action.</p></div>', $this->req)];
    }

    /** Erreurs MySQL courantes traduites (doublon, élément encore utilisé ailleurs). */
    private static function sqlError(\PDOException $e): string
    {
        $info = $e->errorInfo[1] ?? null;

        return match ($info) {
            1062       => 'Cette valeur existe déjà (doublon) : ' . (preg_match("/for key '([^']+)'/", $e->getMessage(), $m) ? $m[1] : ''),
            1451       => 'Impossible : cet élément est encore utilisé ailleurs (supprimez ou modifiez d\'abord les éléments liés).',
            1452       => 'Élément lié introuvable : vérifiez les champs de type « … ID ».',
            1406       => 'Texte trop long pour un des champs.',
            default    => 'Erreur de la base de données (' . ($info ?? '?') . ').',
        };
    }
}
