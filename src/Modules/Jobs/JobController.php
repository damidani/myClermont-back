<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\Documents\DocumentStore;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Security\Crypto;
use App\Support\Validator;

final class JobController
{
    private JobRepository $jobs;

    public function __construct()
    {
        $this->jobs = new JobRepository();
    }

    /**
     * GET /v1/jobs — écran « Emploi & stages »
     *   ?contract=cdi &category=tech &work_mode=hybrid &q=swift &city=Chamalières &min_salary=35000 (€/an) &limit &cursor
     */
    public function index(Request $request): Response
    {
        $q = $request->query();
        $filters = $this->filters($q);
        $limit = max(1, min((int) Config::get('jobs.max_per_page', 50), (int) ($q['limit'] ?? Config::get('jobs.per_page', 20))));

        $rows = $this->jobs->search($filters, $limit, isset($q['cursor']) ? $this->decodeCursor((string) $q['cursor']) : null);
        $favorites = $this->favoriteIds($request, $rows);
        $counts = $this->jobs->contractCounts();
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $j) => JobPresenter::card($j, $favorites === null ? null : in_array($j['uid'], $favorites, true)), $rows),
            200,
            [
                // « Tous 4 · CDI 2 · CDD 1 · Alternance 1 » (contrats sans offre inclus, count 0)
                'contracts' => array_map(
                    static fn (string $code, array $c) => ['code' => $code, 'label' => $c[0], 'color' => $c[1], 'count' => $counts[$code] ?? 0],
                    array_keys((array) Config::get('jobs.contracts')),
                    (array) Config::get('jobs.contracts'),
                ),
                'all_count'   => array_sum($counts),
                'total'       => isset($q['cursor']) ? null : $this->jobs->count($filters), // « 4 offre(s) »
                'next_cursor' => count($rows) === $limit && $last
                    ? rtrim(strtr(base64_encode($last['published_at'] . '|' . $last['id']), '+/', '-_'), '=')
                    : null,
            ],
        );
    }

    /** GET /v1/jobs/filters — contrats, rythmes, catégories (libellés, couleurs, icônes) */
    public function filtersCatalog(Request $request): Response
    {
        return Response::success([
            'contracts'  => array_map(static fn ($code, $c) => ['code' => $code, 'label' => $c[0], 'color' => $c[1]], array_keys((array) Config::get('jobs.contracts')), (array) Config::get('jobs.contracts')),
            'work_modes' => array_map(static fn ($code, $m) => ['code' => $code, 'label' => $m[0], 'icon' => $m[1]], array_keys((array) Config::get('jobs.work_modes')), (array) Config::get('jobs.work_modes')),
            'categories' => $this->jobs->categories(),
        ]);
    }

    /** GET /v1/jobs/{id} */
    public function show(Request $request): Response
    {
        $offer = $this->findOrFail((string) $request->param('id'));
        $favorites = $this->favoriteIds($request, [$offer]);

        return Response::success(JobPresenter::detail(
            $offer,
            $this->jobs->images((int) $offer['id']),
            $favorites === null ? null : in_array($offer['uid'], $favorites, true),
            $this->existingApplication($request, (int) $offer['id']),
        ));
    }

    /**
     * POST /v1/jobs/{id}/apply — formulaire « Candidature »
     * Les PDF sont envoyés AVANT via POST /v1/documents, puis référencés ici par leur id.
     */
    public function apply(Request $request): Response
    {
        $offer = $this->findOrFail((string) $request->param('id'), true);
        $mode = JobPresenter::apply($offer);
        if ($mode['mode'] === 'none') {
            throw new HttpException(409, 'job_inactive', 'Cette offre n\'est plus disponible.');
        }
        if ($mode['mode'] === 'external') {
            throw new HttpException(409, 'apply_external', 'La candidature se fait sur le site du recruteur.', ['url' => $mode['url']]);
        }

        $coverRule = match ($offer['cover_letter_mode']) {
            'required' => 'required|uuid',
            'optional' => 'nullable|uuid',
            default    => 'nullable|uuid',
        };
        // Spec v1 : fullName, cvUploadId (uploadId d'un PDF envoyé par POST /uploads, purpose « cv »)
        $input = $request->json();
        $input['name'] ??= $input['full_name'] ?? null;
        $input['cv_document_id'] ??= $input['cv_upload_id'] ?? null;
        $input['cover_letter_document_id'] ??= $input['cover_letter_upload_id'] ?? null;
        $data = Validator::validate(array_diff_key($input, array_flip(['full_name', 'cv_upload_id', 'cover_letter_upload_id'])), [
            'name'                     => 'required|string|min:2|max:120',
            'email'                    => 'required|email|max:191',
            'phone'                    => 'nullable|string|max:30',
            'message'                  => 'nullable|string|max:3000',
            'cv_document_id'           => 'required|uuid',
            'cover_letter_document_id' => $coverRule,
        ]);
        if (isset($data['phone']) && !preg_match('/^\+?[0-9 .()-]{6,30}$/', $data['phone'])) {
            throw new ValidationException(['phone' => 'Numéro de téléphone invalide.']);
        }
        if ($offer['cover_letter_mode'] === 'none') {
            $data['cover_letter_document_id'] = null;
        }

        $device = $request->attributes['device'];
        $userId = $request->attributes['user_id'] ?? null;
        if ($this->existingApplication($request, (int) $offer['id']) !== null) {
            throw new HttpException(409, 'already_applied', 'Tu as déjà postulé à cette offre.');
        }

        $store = new DocumentStore();
        $cv = $store->claim($data['cv_document_id'], 'cv', $device);
        $cover = isset($data['cover_letter_document_id']) ? $store->claim($data['cover_letter_document_id'], 'cover_letter', $device) : null;

        $uid = Crypto::uuid4();
        Database::transaction(function () use ($uid, $offer, $userId, $device, $data, $cv, $cover, $store): void {
            Database::execute(
                'INSERT INTO job_applications (uid, offer_id, user_id, device_id, name, email, phone, message, cv_document_id, cover_document_id, created_at)
                 VALUES (:uid, :o, :u, :d, :name, :email, :phone, :msg, :cv, :cover, :now)',
                [
                    'uid' => $uid, 'o' => $offer['id'], 'u' => $userId, 'd' => $device['id'], 'name' => $data['name'],
                    'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'msg' => $data['message'] ?? null,
                    'cv' => $cv['id'], 'cover' => $cover['id'] ?? null, 'now' => gmdate('Y-m-d H:i:s'),
                ],
            );
            $store->attach((int) $cv['id']);
            if ($cover !== null) {
                $store->attach((int) $cover['id']);
            }
        });

        Events::dispatch('jobs.application_submitted', ['user_id' => $userId, 'offer_uid' => $offer['uid'], 'application_uid' => $uid]);

        return Response::success([
            'id'         => $uid,
            'status'     => 'pending',
            'message'    => 'Ta candidature a été envoyée au recruteur.',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], 201);
    }

    /** GET /v1/users/me/applications — « Mes candidatures » */
    public function mine(Request $request): Response
    {
        $rows = Database::fetchAll(
            'SELECT a.uid AS application_uid, a.status AS application_status, a.created_at AS applied_at, o.uid
             FROM job_applications a JOIN job_offers o ON o.id = a.offer_id
             WHERE a.user_id = :u ORDER BY a.id DESC LIMIT 100',
            ['u' => $request->attributes['user_id']],
        );
        $offers = $this->jobs->findManyByUid(array_column($rows, 'uid'));

        return Response::success(array_values(array_filter(array_map(static fn (array $r) => isset($offers[$r['uid']]) ? [
            'id'         => $r['application_uid'],
            'status'     => self::applicationStatus($r['application_status']),
            'applied_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['applied_at'] . ' UTC')),
            'offer'      => JobPresenter::card($offers[$r['uid']]),
        ] : null, $rows))));
    }

    /** Statut lisible : la file d'envoi interne (pending / failed) n'est pas exposée telle quelle. */
    private static function applicationStatus(string $status): string
    {
        return $status === 'sent' ? 'sent' : 'processing';
    }

    private function existingApplication(Request $request, int $offerId): ?array
    {
        $since = gmdate('Y-m-d H:i:s', time() - (int) Config::get('jobs.applications.dedupe_days', 30) * 86400);
        $userId = $request->attributes['user_id'] ?? null;

        $row = Database::fetch(
            'SELECT uid, status, created_at FROM job_applications
             WHERE offer_id = :o AND created_at > :since AND (device_id = :d OR (user_id IS NOT NULL AND user_id = :u))
             ORDER BY id DESC LIMIT 1',
            ['o' => $offerId, 'since' => $since, 'd' => $request->attributes['device']['id'], 'u' => $userId ?? 0],
        );

        return $row === null ? null : [
            'id'         => $row['uid'],
            'status'     => self::applicationStatus($row['status']),
            'applied_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
        ];
    }

    private function findOrFail(string $id, bool $withContact = false): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($offer = $this->jobs->find($id, $withContact)) === null) {
            throw new HttpException(404, 'job_not_found', 'Offre introuvable.');
        }

        return $offer;
    }

    private function filters(array $q): array
    {
        // Spec v1 : l'app envoie les libellés (« CDI », « Hybride ») ; les codes internes restent acceptés
        foreach (['contract' => 'jobs.contracts', 'work_mode' => 'jobs.work_modes'] as $key => $config) {
            if (isset($q[$key]) && is_string($q[$key])) {
                foreach ((array) Config::get($config) as $code => $def) {
                    if (mb_strtolower($def[0]) === mb_strtolower($q[$key])) {
                        $q[$key] = $code;
                    }
                }
            }
        }
        $errors = [];
        foreach (['contract' => 'jobs.contracts', 'work_mode' => 'jobs.work_modes'] as $key => $config) {
            if (isset($q[$key]) && $q[$key] !== '' && !array_key_exists((string) $q[$key], (array) Config::get($config))) {
                $errors[$key] = 'Valeur inconnue.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'contract'         => ($q['contract'] ?? '') !== '' ? (string) $q['contract'] : null,
            'work_mode'        => ($q['work_mode'] ?? '') !== '' ? (string) $q['work_mode'] : null,
            'category'         => ($q['category'] ?? '') !== '' ? (string) $q['category'] : null,
            'city'             => ($q['city'] ?? '') !== '' ? mb_substr((string) $q['city'], 0, 100) : null,
            'min_salary_cents' => isset($q['min_salary']) && (int) $q['min_salary'] > 0 ? (int) $q['min_salary'] * 100 : null,
            'q'                => isset($q['q']) ? mb_substr(trim((string) $q['q']), 0, 100) : null,
        ];
    }

    private function favoriteIds(Request $request, array $rows): ?array
    {
        $userId = $request->attributes['user_id'] ?? null;

        return $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'job', array_column($rows, 'uid'));
    }

    /** @return array{0: string, 1: int} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = (string) base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $m)) {
            throw new ValidationException(['cursor' => 'Curseur invalide.']);
        }

        return [$m[1], (int) $m[2]];
    }
}
