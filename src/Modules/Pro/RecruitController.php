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
 * Recrutement, demandes de devis et annonces de logement (demande 7).
 *
 * Chaque contenu garde le formulaire pro tel quel (colonne pro, JSON) et recopie dans ses colonnes ce que l'app lit
 * (GET /jobs, /services, /housing). Les candidatures, demandes de devis et de visite arrivent par les routes de l'app déjà livrées.
 */
final class RecruitController
{
    private const CONTRACTS = ['CDI' => 'cdi', 'CDD' => 'cdd', 'Alternance' => 'alternance', 'Stage' => 'stage', 'Saisonnier' => 'saisonnier', 'Extra' => 'saisonnier', 'Intérim' => 'interim'];
    private const MODES = ['Sur site' => 'onsite', 'Hybride' => 'hybrid', 'Télétravail' => 'remote'];
    private const JOB_STATUSES = ['brouillon', 'publiee', 'pourvue', 'archivee'];
    private const APPLICATION_STATUSES = ['nouvelle', 'vue', 'entretien', 'retenue', 'refusee'];
    private const QUOTE_STATUSES = ['nouvelle', 'en_cours', 'devis_envoye', 'gagnee', 'perdue'];
    private const HOUSING_TYPES = ['Location' => 'rent', 'Colocation' => 'colocation', 'Achat' => 'sale'];
    private const HOUSING_STATUSES = ['brouillon', 'publiee', 'louee', 'archivee'];
    private const CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    // --- Offres d'emploi ---------------------------------------------------

    public function jobs(Request $request): Response
    {
        $rows = Database::fetchAll('SELECT * FROM job_offers WHERE business_id = :b ORDER BY id DESC', ['b' => Pro::business($request)['id']]);

        return Response::success(array_map([self::class, 'job'], $rows));
    }

    public function createJob(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        $now = gmdate('Y-m-d H:i:s');
        $d = $this->validateJob($request->json(), self::jobBlank($b));
        $id = Database::insert(
            "INSERT INTO job_offers (uid, business_id, category_id, contract, title, company_name, created_at, updated_at, status) VALUES (:u, :b, :c, 'cdi', :t, :co, :cr, :up, 'draft')",
            ['u' => Crypto::uuid4(), 'b' => $b['id'], 'c' => self::jobCategory($b['activity']), 't' => $d['title'] ?: 'Offre', 'co' => Pro::displayName($b), 'cr' => $now, 'up' => $now]);
        $this->saveJob($id, $d, $b);

        return Response::success(self::job($this->one('job_offers', $id)), 201);
    }

    public function updateJob(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $job = $this->owned($request, 'job_offers');
        $this->saveJob((int) $job['id'], $this->validateJob($request->json(), self::pro($job, self::jobBlank(Pro::business($request)))), Pro::business($request));

        return Response::success(self::job($this->one('job_offers', (int) $job['id'])));
    }

    public function deleteJob(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $job = $this->owned($request, 'job_offers');
        Database::execute('DELETE FROM job_applications WHERE offer_id = :o', ['o' => $job['id']]);
        Database::execute('DELETE FROM job_offers WHERE id = :o', ['o' => $job['id']]);

        return Response::noContent();
    }

    public function applications(Request $request): Response
    {
        $q = $request->query();
        $where = ['o.business_id = :b'];
        $params = ['b' => Pro::business($request)['id']];
        if (is_string($q['job_id'] ?? null) && $q['job_id'] !== '') {
            $where[] = 'o.uid = :j';
            $params['j'] = $q['job_id'];
        }
        if (in_array($q['status'] ?? null, self::APPLICATION_STATUSES, true)) {
            $where[] = 'a.pro_status = :s';
            $params['s'] = $q['status'];
        }
        $rows = Database::fetchAll('SELECT a.*, o.uid AS job_uid, d.filename AS cv_name FROM job_applications a JOIN job_offers o ON o.id = a.offer_id
             LEFT JOIN documents d ON d.id = a.cv_document_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC', $params);

        return Response::success(array_map([self::class, 'application'], $rows));
    }

    public function updateApplication(Request $request): Response
    {
        $a = $this->application_($request);
        $status = $request->json()['status'] ?? null;
        if (!in_array($status, self::APPLICATION_STATUSES, true)) {
            throw new ValidationException(['status' => 'Statut inconnu.']);
        }
        Database::execute('UPDATE job_applications SET pro_status = :s WHERE id = :id', ['s' => $status, 'id' => $a['id']]);
        // application_update (4890352c) : la personne suit l'avancée de sa candidature
        $texts = ['vue' => 'a consulté ta candidature', 'entretien' => 'te propose un entretien', 'retenue' => 'a retenu ta candidature', 'refusee' => 'n\'a pas retenu ta candidature cette fois'];
        if (!empty($a['user_id']) && isset($texts[$status]) && $status !== ($a['pro_status'] ?? null)) {
            $o = Database::fetch('SELECT title, company_name FROM job_offers WHERE id = :id', ['id' => $a['offer_id']]);
            \App\Modules\Notifications\Notices::send((int) $a['user_id'], 'application_update', '💼 ' . ($o['company_name'] ?? 'Le recruteur') . ' ' . $texts[$status],
                '« ' . ($o['title'] ?? 'Ton offre') . ' »', ['type' => 'application', 'id' => $a['uid']]);
        }

        return Response::success(self::application($this->application_($request)));
    }

    public function deleteApplication(Request $request): Response
    {
        $a = $this->application_($request);
        Database::execute('DELETE FROM job_applications WHERE id = :id', ['id' => $a['id']]);

        return Response::noContent();
    }

    /** DELETE /pro/applications?status=refusee : purge RGPD des candidatures refusées. */
    public function purgeApplications(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $status = $request->query()['status'] ?? null;
        if (!in_array($status, self::APPLICATION_STATUSES, true)) {
            throw new ValidationException(['status' => 'Précise le statut des candidatures à supprimer.']);
        }
        Database::execute('DELETE a FROM job_applications a JOIN job_offers o ON o.id = a.offer_id WHERE o.business_id = :b AND a.pro_status = :s',
            ['b' => Pro::business($request)['id'], 's' => $status]);

        return Response::noContent();
    }

    /** CV : lien temporaire (10 min) vers le PDF privé. */
    public function cv(Request $request): Response
    {
        $a = $this->application_($request);
        if ($a['cv_document_id'] === null) {
            throw new HttpException(404, 'not_found', 'Pas de CV joint.');
        }
        $exp = time() + 600;
        $sig = hash_hmac('sha256', $a['uid'] . '|' . $exp, (string) \App\Core\Config::get('app.key'));

        return Response::success(['url' => rtrim((string) \App\Core\Config::get('app.url'), '/') . '/api/v1/pro/cv/' . $a['uid'] . '?e=' . $exp . '&s=' . $sig,
                                  'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp)]);
    }

    /** GET /pro/cv/{id}?e=&s= : téléchargement du CV par le lien temporaire (sans session : ouvert dans un nouvel onglet). */
    public function downloadCv(Request $request): Response
    {
        $q = $request->query();
        $uid = (string) $request->param('id');
        $exp = (int) ($q['e'] ?? 0);
        if ($exp < time() || !hash_equals(hash_hmac('sha256', $uid . '|' . $exp, (string) \App\Core\Config::get('app.key')), (string) ($q['s'] ?? ''))) {
            throw new HttpException(403, 'forbidden', 'Lien expiré : rouvre le CV depuis l\'espace pro.');
        }
        $a = Database::fetch('SELECT cv_document_id FROM job_applications WHERE uid = :u', ['u' => $uid]) ?? throw new HttpException(404, 'not_found', 'CV introuvable.');
        $doc = $a['cv_document_id'] !== null ? (new \App\Content\Documents\DocumentStore())->read((int) $a['cv_document_id']) : null;
        if ($doc === null) {
            throw new HttpException(404, 'not_found', 'CV introuvable.');
        }

        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $doc['filename'])) ?: 'cv.pdf';

        return Response::raw($doc['content'], 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . (str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf') . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // --- Prestations et devis ----------------------------------------------

    public function services(Request $request): Response
    {
        return Response::success(array_map([self::class, 'service'],
            Database::fetchAll('SELECT * FROM service_providers WHERE business_id = :b ORDER BY id DESC', ['b' => Pro::business($request)['id']])));
    }

    public function createService(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        $d = $this->validateService($request->json(), self::serviceBlank());
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert("INSERT INTO service_providers (uid, business_id, category_id, name, created_at, updated_at, status) VALUES (:u, :b, 1, :n, :c, :up, 'draft')",
            ['u' => Crypto::uuid4(), 'b' => $b['id'], 'n' => $d['name'] ?: $b['trade_name'], 'c' => $now, 'up' => $now]);
        $this->saveService($id, $d, $request);

        return Response::success(self::service($this->one('service_providers', $id)), 201);
    }

    public function updateService(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $s = $this->owned($request, 'service_providers');
        $this->saveService((int) $s['id'], $this->validateService($request->json(), self::pro($s, self::serviceBlank())), $request);

        return Response::success(self::service($this->one('service_providers', (int) $s['id'])));
    }

    public function deleteService(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $s = $this->owned($request, 'service_providers');
        Database::execute('DELETE FROM service_quote_requests WHERE provider_id = :p', ['p' => $s['id']]);
        Database::execute('DELETE FROM service_providers WHERE id = :p', ['p' => $s['id']]);

        return Response::noContent();
    }

    public function quotes(Request $request): Response
    {
        $q = $request->query();
        $where = ['s.business_id = :b'];
        $params = ['b' => Pro::business($request)['id']];
        if (in_array($q['status'] ?? null, self::QUOTE_STATUSES, true)) {
            $where[] = 'r.pro_status = :s';
            $params['s'] = $q['status'];
        }
        $rows = Database::fetchAll('SELECT r.*, s.uid AS service_uid FROM service_quote_requests r JOIN service_providers s ON s.id = r.provider_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY r.id DESC', $params);

        return Response::success(array_map([self::class, 'quote'], $rows));
    }

    public function updateQuote(Request $request): Response
    {
        $r = $this->quote_($request);
        $j = $request->json();
        $set = [];
        if (array_key_exists('status', $j)) {
            in_array($j['status'], self::QUOTE_STATUSES, true) ? $set['pro_status'] = $j['status'] : throw new ValidationException(['status' => 'Statut inconnu.']);
        }
        if (array_key_exists('note', $j)) {
            is_string($j['note']) && mb_strlen($j['note']) <= 2000 ? $set['note'] = trim($j['note']) : throw new ValidationException(['note' => 'Note : 2000 caractères au plus.']);
        }
        if ($set !== []) {
            Database::execute('UPDATE service_quote_requests SET ' . implode(', ', array_map(static fn ($k) => "{$k} = :{$k}", array_keys($set))) . ' WHERE id = :id', $set + ['id' => $r['id']]);
        }

        return Response::success(self::quote($this->quote_($request)));
    }

    public function deleteQuote(Request $request): Response
    {
        Database::execute('DELETE FROM service_quote_requests WHERE id = :id', ['id' => $this->quote_($request)['id']]);

        return Response::noContent();
    }

    // --- Logements ---------------------------------------------------------

    public function housings(Request $request): Response
    {
        return Response::success(array_map([self::class, 'housing'],
            Database::fetchAll('SELECT * FROM housing_listings WHERE business_id = :b ORDER BY id DESC', ['b' => Pro::business($request)['id']])));
    }

    public function createHousing(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $b = Pro::business($request);
        $d = $this->validateHousing($request->json(), self::housingBlank());
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert("INSERT INTO housing_listings (uid, business_id, transaction, title, price_cents, created_at, updated_at, status) VALUES (:u, :b, 'rent', :t, 0, :c, :up, 'draft')",
            ['u' => Crypto::uuid4(), 'b' => $b['id'], 't' => $d['title'] ?: 'Logement', 'c' => $now, 'up' => $now]);
        $this->saveHousing($id, $d, $request);

        return Response::success(self::housing($this->one('housing_listings', $id)), 201);
    }

    public function updateHousing(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $h = $this->owned($request, 'housing_listings');
        $this->saveHousing((int) $h['id'], $this->validateHousing($request->json(), self::pro($h, self::housingBlank())), $request);

        return Response::success(self::housing($this->one('housing_listings', (int) $h['id'])));
    }

    public function deleteHousing(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $h = $this->owned($request, 'housing_listings');
        foreach (['housing_contact_requests', 'housing_images'] as $t) {
            Database::execute("DELETE FROM {$t} WHERE listing_id = :l", ['l' => $h['id']]);
        }
        Database::execute('DELETE FROM housing_listings WHERE id = :l', ['l' => $h['id']]);

        return Response::noContent();
    }

    public function housingContacts(Request $request): Response
    {
        $q = $request->query();
        $where = ['h.business_id = :b'];
        $params = ['b' => Pro::business($request)['id']];
        if (is_string($q['housing_id'] ?? null) && $q['housing_id'] !== '') {
            $where[] = 'h.uid = :h';
            $params['h'] = $q['housing_id'];
        }
        if (in_array($q['status'] ?? null, ['nouvelle', 'traitee'], true)) {
            $where[] = 'c.pro_status = :s';
            $params['s'] = $q['status'];
        }
        $rows = Database::fetchAll('SELECT c.*, h.uid AS housing_uid FROM housing_contact_requests c JOIN housing_listings h ON h.id = c.listing_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY c.id DESC', $params);

        return Response::success(array_map([self::class, 'housingContact'], $rows));
    }

    public function updateHousingContact(Request $request): Response
    {
        $c = $this->contact_($request);
        $status = $request->json()['status'] ?? null;
        if (!in_array($status, ['nouvelle', 'traitee'], true)) {
            throw new ValidationException(['status' => 'Statut : nouvelle ou traitee.']);
        }
        Database::execute('UPDATE housing_contact_requests SET pro_status = :s WHERE id = :id', ['s' => $status, 'id' => $c['id']]);

        return Response::success(self::housingContact($this->contact_($request)));
    }

    public function deleteHousingContact(Request $request): Response
    {
        Database::execute('DELETE FROM housing_contact_requests WHERE id = :id', ['id' => $this->contact_($request)['id']]);

        return Response::noContent();
    }

    // --- Formats -----------------------------------------------------------

    public static function job(array $j): array
    {
        return ['id' => $j['uid']] + self::pro($j, []) + ['status' => $j['pro_status'] ?? 'brouillon', 'created_at' => Pro::iso($j['created_at'])];
    }

    public static function application(array $a): array
    {
        return ['id' => $a['uid'], 'job_id' => $a['job_uid'], 'full_name' => $a['name'], 'email' => $a['email'], 'phone' => (string) $a['phone'],
                'message' => (string) $a['message'], 'cv_name' => $a['cv_document_id'] !== null ? ($a['cv_name'] ?? 'CV.pdf') : null,
                'status' => $a['pro_status'], 'created_at' => Pro::iso($a['created_at'])];
    }

    public static function service(array $s): array
    {
        return ['id' => $s['uid']] + self::pro($s, []) + ['active' => $s['status'] === 'published', 'created_at' => Pro::iso($s['created_at'])];
    }

    public static function quote(array $r): array
    {
        $out = ['id' => $r['uid'], 'service_id' => $r['service_uid'], 'full_name' => $r['name'], 'email' => $r['email'], 'phone' => (string) $r['phone'],
                'message' => (string) $r['message'], 'details' => (object) (json_decode((string) $r['details'], true) ?: array_filter([
                    'Code postal' => $r['postal_code'], 'Date souhaitée' => $r['preferred_date'] ? date('d/m/Y', strtotime($r['preferred_date'])) : null])),
                'status' => $r['pro_status'], 'created_at' => Pro::iso($r['created_at'])];
        if ($r['note'] !== null) {
            $out['note'] = $r['note'];
        }

        return $out;
    }

    public static function housing(array $h): array
    {
        return ['id' => $h['uid']] + self::pro($h, []) + ['status' => $h['pro_status'] ?? 'brouillon', 'created_at' => Pro::iso($h['created_at'])];
    }

    public static function housingContact(array $c): array
    {
        return ['id' => $c['uid'], 'housing_id' => $c['housing_uid'], 'full_name' => $c['name'], 'email' => $c['email'], 'phone' => (string) $c['phone'],
                'message' => (string) $c['message'], 'created_at' => Pro::iso($c['created_at']), 'status' => $c['pro_status']];
    }

    // --- Validation et recopie vers les colonnes de l'app --------------------

    private static function jobBlank(array $b): array
    {
        return ['title' => '', 'company' => Pro::displayName($b), 'location' => 'Clermont-Ferrand', 'salary' => '', 'contract' => 'CDI', 'work_mode' => 'Sur site',
                'icon' => 'briefcase', 'summary' => '', 'description' => '', 'apply_mode' => 'app', 'apply_url' => '', 'status' => 'brouillon'];
    }

    private function validateJob(array $j, array $d): array
    {
        $errors = [];
        foreach (['title' => 160, 'location' => 100, 'salary' => 80, 'icon' => 40, 'summary' => 300, 'description' => 5000] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        foreach (['contract' => self::CONTRACTS, 'work_mode' => self::MODES, 'apply_mode' => ['app' => 1, 'url' => 1], 'status' => array_flip(self::JOB_STATUSES)] as $k => $allowed) {
            if (array_key_exists($k, $j)) {
                isset($allowed[$j[$k]]) ? $d[$k] = $j[$k] : $errors[$k] = 'Valeur inconnue.';
            }
        }
        if (array_key_exists('apply_url', $j)) {
            Pro::url($j['apply_url']) ? $d['apply_url'] = (string) $j['apply_url'] : $errors['apply_url'] = 'Lien invalide.';
        }
        if ($errors === [] && $d['status'] === 'publiee') {
            foreach (['title' => 'Indique l\'intitulé.', 'location' => 'Indique le lieu.', 'salary' => 'Indique le salaire.', 'description' => 'Ajoute une description.'] as $k => $m) {
                if (trim($d[$k]) === '') {
                    $errors[$k] = $m;
                }
            }
            if ($d['apply_mode'] === 'url' && $d['apply_url'] === '') {
                $errors['apply_url'] = 'Indique le lien pour postuler.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $d;
    }

    private function saveJob(int $id, array $d, array $b): void
    {
        if ($d['status'] === 'publiee') {
            Verification::requireBusiness($b);
        }
        $d['company'] = Pro::displayName($b);
        $now = gmdate('Y-m-d H:i:s');
        $owner = Database::fetch("SELECT email FROM pro_members WHERE business_id = :b AND role = 'proprietaire' LIMIT 1", ['b' => $b['id']]);
        Database::execute(
            "UPDATE job_offers SET title = :t, company_name = :co, contract = :c, work_mode = :w, city = :city, salary_label = :sal, summary = :sum, description = :ds,
                    application_url = :url, contact_email = :mail, pro_status = :ps, status = :s, published_at = IF(:s2 = 'published', COALESCE(published_at, :now), published_at),
                    pro = :pro, updated_at = :up WHERE id = :id",
            ['t' => $d['title'] ?: 'Offre', 'co' => Pro::displayName($b), 'c' => self::CONTRACTS[$d['contract']], 'w' => self::MODES[$d['work_mode']], 'city' => $d['location'] ?: 'Clermont-Ferrand',
             'sal' => $d['salary'] ?: null, 'sum' => $d['summary'] ?: mb_substr($d['description'], 0, 300) ?: null, 'ds' => $d['description'] ?: null,
             'url' => $d['apply_mode'] === 'url' ? $d['apply_url'] : null, 'mail' => $owner['email'] ?? null, 'ps' => $d['status'],
             's' => $d['status'] === 'publiee' ? 'published' : ($d['status'] === 'brouillon' ? 'draft' : 'archived'), 's2' => $d['status'] === 'publiee' ? 'published' : 'x',
             'now' => $now, 'pro' => self::json(array_diff_key($d, ['status' => 1])), 'up' => $now, 'id' => $id],
        );
    }

    private static function serviceBlank(): array
    {
        return ['name' => '', 'category' => 'Maison & travaux', 'district' => '', 'icon' => 'wrench', 'description' => '', 'specialties' => [], 'hours' => '', 'price' => '',
                'contact_mode' => 'app', 'contact_url' => '', 'active' => false];
    }

    private function validateService(array $j, array $d): array
    {
        $errors = [];
        foreach (['name' => 160, 'district' => 100, 'icon' => 40, 'description' => 3000, 'hours' => 120, 'price' => 80] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        if (array_key_exists('category', $j)) {
            Database::fetch('SELECT 1 FROM service_categories WHERE name = :n', ['n' => (string) $j['category']]) ? $d['category'] = $j['category'] : $errors['category'] = 'Catégorie inconnue.';
        }
        if (array_key_exists('specialties', $j)) {
            is_array($j['specialties']) && count($j['specialties']) <= 20
                ? $d['specialties'] = array_values(array_filter(array_map(static fn ($s) => mb_substr(trim((string) $s), 0, 80), $j['specialties']), 'strlen'))
                : $errors['specialties'] = '20 spécialités au plus.';
        }
        if (array_key_exists('contact_mode', $j)) {
            in_array($j['contact_mode'], ['app', 'url'], true) ? $d['contact_mode'] = $j['contact_mode'] : $errors['contact_mode'] = 'Mode : app ou url.';
        }
        if (array_key_exists('contact_url', $j)) {
            Pro::url($j['contact_url']) ? $d['contact_url'] = (string) $j['contact_url'] : $errors['contact_url'] = 'Lien invalide.';
        }
        if (array_key_exists('active', $j)) {
            $d['active'] = $j['active'] === true;
        }
        if ($errors === [] && $d['active'] && (trim($d['name']) === '' || trim($d['description']) === '')) {
            $errors[trim($d['name']) === '' ? 'name' : 'description'] = 'Indique le nom et la description avant d\'activer la prestation.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $d;
    }

    private function saveService(int $id, array $d, Request $request): void
    {
        if ($d['active']) {
            Verification::requireBusiness(Pro::business($request));
        }
        $b = Pro::business($request);
        $place = Pro::place($request);
        $owner = Database::fetch("SELECT email FROM pro_members WHERE business_id = :b AND role = 'proprietaire' LIMIT 1", ['b' => $b['id']]);
        $cat = Database::fetch('SELECT id FROM service_categories WHERE name = :n', ['n' => $d['category']]);
        Database::execute(
            "UPDATE service_providers SET name = :n, category_id = :c, summary = :sum, description = :ds, services = :sv, hours_label = :h, price_label = :p, city = :city,
                    latitude = :lat, longitude = :lon, phone = :ph, website_url = :w, rating_avg = :ra, rating_count = :rc, contact_email = :mail,
                    status = :s, published_at = IF(:s2 = 'published', COALESCE(published_at, :now), published_at), pro = :pro, updated_at = :up WHERE id = :id",
            ['n' => $d['name'] ?: $b['trade_name'], 'c' => $cat['id'] ?? 1, 'sum' => mb_substr($d['description'], 0, 300) ?: null, 'ds' => $d['description'] ?: null,
             'sv' => json_encode($d['specialties'], JSON_UNESCAPED_UNICODE), 'h' => $d['hours'] ?: null, 'p' => $d['price'] ?: null, 'city' => $place['city'] ?: 'Clermont-Ferrand',
             'lat' => $place['latitude'], 'lon' => $place['longitude'], 'ph' => $place['phone'], 'w' => $d['contact_mode'] === 'url' ? $d['contact_url'] : $place['website_url'],
             'ra' => $place['rating_avg'], 'rc' => (int) $place['rating_count'], 'mail' => $owner['email'] ?? null,
             's' => $d['active'] ? 'published' : 'draft', 's2' => $d['active'] ? 'published' : 'x', 'now' => gmdate('Y-m-d H:i:s'),
             'pro' => self::json(array_diff_key($d, ['active' => 1])), 'up' => gmdate('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    private static function housingBlank(): array
    {
        return ['title' => '', 'district' => '', 'price' => 0, 'surface' => 0, 'rooms' => 1, 'bedrooms' => 0, 'type' => 'Location', 'furnished' => false, 'images' => [],
                'features' => [], 'description' => '', 'latitude' => null, 'longitude' => null, 'energy_class' => null, 'climate_class' => null, 'charges' => null,
                'deposit' => null, 'fees' => '', 'status' => 'brouillon'];
    }

    private function validateHousing(array $j, array $d): array
    {
        $errors = [];
        foreach (['title' => 160, 'district' => 100, 'description' => 5000, 'fees' => 160] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        foreach (['price' => 100000000, 'surface' => 100000, 'rooms' => 50, 'bedrooms' => 50] as $k => $max) {
            if (array_key_exists($k, $j)) {
                is_numeric($j[$k]) && $j[$k] >= 0 && $j[$k] <= $max ? $d[$k] = $j[$k] + 0 : $errors[$k] = 'Valeur invalide.';
            }
        }
        foreach (['charges', 'deposit'] as $k) {
            if (array_key_exists($k, $j)) {
                $j[$k] === null || (is_numeric($j[$k]) && $j[$k] >= 0) ? $d[$k] = $j[$k] === null ? null : $j[$k] + 0 : $errors[$k] = 'Montant invalide.';
            }
        }
        foreach (['energy_class', 'climate_class'] as $k) {
            if (array_key_exists($k, $j)) {
                $j[$k] === null || in_array($j[$k], self::CLASSES, true) ? $d[$k] = $j[$k] : $errors[$k] = 'Classe de A à G.';
            }
        }
        if (array_key_exists('type', $j)) {
            isset(self::HOUSING_TYPES[$j['type']]) ? $d['type'] = $j['type'] : $errors['type'] = 'Type : Location, Colocation ou Achat.';
        }
        if (array_key_exists('status', $j)) {
            in_array($j['status'], self::HOUSING_STATUSES, true) ? $d['status'] = $j['status'] : $errors['status'] = 'Statut inconnu.';
        }
        if (array_key_exists('furnished', $j)) {
            $d['furnished'] = $j['furnished'] === true;
        }
        if (array_key_exists('features', $j)) {
            is_array($j['features']) ? $d['features'] = array_values(array_filter(array_map(static fn ($f) => mb_substr(trim((string) $f), 0, 60), $j['features']), 'strlen'))
                : $errors['features'] = 'Liste attendue.';
        }
        if (array_key_exists('images', $j)) {
            if (!is_array($j['images']) || count($j['images']) > 10 || array_filter($j['images'], static fn ($u) => !is_string($u) || $u === '' || !Pro::url($u))) {
                $errors['images'] = '10 photos au plus (adresses renvoyées par l\'envoi).';
            } else {
                $d['images'] = array_values($j['images']);
            }
        }
        foreach (['latitude', 'longitude'] as $k) {
            if (array_key_exists($k, $j)) {
                $d[$k] = is_numeric($j[$k]) ? round((float) $j[$k], 6) : null;
            }
        }
        if ($errors === [] && $d['status'] === 'publiee') {
            if (trim($d['title']) === '' || $d['price'] <= 0) {
                $errors['title'] = 'Indique le titre et le prix.';
            }
            if ($d['energy_class'] === null || $d['climate_class'] === null) {
                $errors['energy_class'] = 'Le DPE et le GES sont obligatoires pour publier.';
            }
            if ($d['type'] !== 'Achat' && ($d['charges'] === null || $d['deposit'] === null)) {
                $errors['charges'] = 'Indique les charges et le dépôt de garantie.';
            }
            if (trim($d['fees']) === '') {
                $errors['fees'] = 'Indique les honoraires (ou « Aucun »).';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $d;
    }

    private function saveHousing(int $id, array $d, Request $request): void
    {
        if ($d['status'] === 'publiee') {
            Verification::requireBusiness(Pro::business($request));
        }
        $place = Pro::place($request);
        $owner = Database::fetch("SELECT first_name, last_name, email, phone FROM pro_members WHERE business_id = :b AND role = 'proprietaire' LIMIT 1", ['b' => Pro::business($request)['id']]);
        [$lat, $lon] = [$d['latitude'], $d['longitude']];
        if ($lat === null || $lon === null) {
            // Pas d'adresse précise : centre du quartier, sinon position de la fiche
            [$lat, $lon] = ($d['district'] !== '' ? (new \App\Content\Import\Geocoder())->locate($place['city'] ?: 'Clermont-Ferrand', null, $d['district']) : null)
                ?? [(float) $place['latitude'], (float) $place['longitude']];
        }
        $labels = array_map(static fn ($f) => mb_strtolower($f[0]), (array) \App\Core\Config::get('housing.features', []));
        $codes = array_values(array_filter(array_map(static fn ($f) => array_search(mb_strtolower($f), $labels, true) ?: null, $d['features'])));
        $isRent = $d['type'] !== 'Achat';
        Database::execute(
            "UPDATE housing_listings SET title = :t, transaction = :tr, description = :ds, price_cents = :p, charges_cents = :ch, charges_included = :ci, deposit_cents = :dep, fees_label = :fees,
                    surface_m2 = :s, rooms = :r, bedrooms = :b, furnished = :f, energy_class = :ec, climate_class = :cc, features = :feat, district_label = :dist, city = :city,
                    latitude = :lat, longitude = :lon, contact_name = :cn, contact_email = :ce, contact_phone = :cp, pro_status = :ps, status = :st,
                    published_at = IF(:st2 = 'published', COALESCE(published_at, :now), published_at), pro = :pro, updated_at = :up WHERE id = :id",
            ['t' => $d['title'] ?: 'Logement', 'tr' => self::HOUSING_TYPES[$d['type']], 'ds' => $d['description'] ?: null,
             // price = charges comprises : on stocke le loyer hors charges si les charges sont connues
             'p' => (int) round(($isRent && $d['charges'] !== null ? max(0, $d['price'] - $d['charges']) : $d['price']) * 100),
             'ch' => $d['charges'] === null ? null : (int) round($d['charges'] * 100), 'ci' => $isRent && $d['charges'] !== null ? 0 : null,
             'dep' => $d['deposit'] === null ? null : (int) round($d['deposit'] * 100), 'fees' => $d['fees'] ?: null, 's' => $d['surface'] ?: null, 'r' => $d['rooms'] ?: null,
             'b' => $d['bedrooms'], 'f' => (int) $d['furnished'], 'ec' => $d['energy_class'], 'cc' => $d['climate_class'], 'feat' => implode(',', $codes) ?: null,
             'dist' => $d['district'] ?: null, 'city' => $place['city'] ?: 'Clermont-Ferrand', 'lat' => round($lat, 6), 'lon' => round($lon, 6),
             'cn' => trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')) ?: null, 'ce' => $owner['email'] ?? null, 'cp' => $owner['phone'] ?? null,
             'ps' => $d['status'], 'st' => $d['status'] === 'publiee' ? 'published' : ($d['status'] === 'brouillon' ? 'draft' : 'archived'),
             'st2' => $d['status'] === 'publiee' ? 'published' : 'x', 'now' => gmdate('Y-m-d H:i:s'),
             'pro' => self::json(array_diff_key($d, ['status' => 1])), 'up' => gmdate('Y-m-d H:i:s'), 'id' => $id],
        );
        Database::execute('DELETE FROM housing_images WHERE listing_id = :l', ['l' => $id]);
        foreach ($d['images'] as $i => $url) {
            Database::insert('INSERT INTO housing_images (listing_id, url, position) VALUES (:l, :u, :p)', ['l' => $id, 'u' => $url, 'p' => $i]);
        }
    }

    // -----------------------------------------------------------------------

    /** Formulaire pro (colonne pro) complété des valeurs par défaut. */
    private static function pro(array $row, array $blank): array
    {
        $d = array_replace($blank, json_decode((string) ($row['pro'] ?? ''), true) ?: []);
        if (array_key_exists('status', $blank)) {
            $d['status'] = $row['pro_status'] ?? $blank['status'];
        }
        if (array_key_exists('active', $blank)) {
            $d['active'] = $row['status'] === 'published';
        }

        return $d;
    }

    private static function jobCategory(string $activity): int
    {
        $slug = in_array($activity, ['restaurant', 'cafe', 'fastFood', 'boulangerie', 'hebergement'], true) ? 'hotellerie'
              : ($activity === 'culture' ? 'culture' : ($activity === 'immobilier' || $activity === 'services' ? 'administratif' : 'commerce'));

        return (int) (Database::fetch('SELECT id FROM job_categories WHERE slug = :s', ['s' => $slug])['id'] ?? 12);
    }

    private function owned(Request $request, string $table): array
    {
        return Database::fetch("SELECT * FROM {$table} WHERE uid = :u AND business_id = :b", ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Élément introuvable.');
    }

    private function one(string $table, int $id): array
    {
        return Database::fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
    }

    private function application_(Request $request): array
    {
        return Database::fetch('SELECT a.*, o.uid AS job_uid, d.filename AS cv_name FROM job_applications a JOIN job_offers o ON o.id = a.offer_id
             LEFT JOIN documents d ON d.id = a.cv_document_id WHERE a.uid = :u AND o.business_id = :b', ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Candidature introuvable.');
    }

    private function quote_(Request $request): array
    {
        return Database::fetch('SELECT r.*, s.uid AS service_uid FROM service_quote_requests r JOIN service_providers s ON s.id = r.provider_id WHERE r.uid = :u AND s.business_id = :b',
            ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']]) ?? throw new HttpException(404, 'not_found', 'Demande introuvable.');
    }

    private function contact_(Request $request): array
    {
        return Database::fetch('SELECT c.*, h.uid AS housing_uid FROM housing_contact_requests c JOIN housing_listings h ON h.id = c.listing_id WHERE c.uid = :u AND h.business_id = :b',
            ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']]) ?? throw new HttpException(404, 'not_found', 'Demande introuvable.');
    }

    private static function json(array $d): string
    {
        return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
