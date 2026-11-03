<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Admin\Audit;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Pro\PlaceController;
use App\Modules\Pro\Pro;
use App\Modules\Pro\Verification;

/**
 * Validation des inscriptions commerçant depuis /admin du dashboard (ca817cb4) :
 *   GET /admin/overview ; GET /admin/merchants?status=&q= ; GET /admin/merchants/{id}
 *   POST /admin/merchants/{id}/approve | request-info { message } | reject { reason, message }
 */
final class MerchantController
{
    private const STATUSES = ['a_verifier', 'en_cours', 'complement', 'verifie', 'refuse'];

    public function overview(Request $request): Response
    {
        $pending = Database::fetchAll("SELECT verification_submitted_at FROM pro_businesses WHERE verification = 'en_cours' AND deleted_at IS NULL");
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $decided = Database::fetch("SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(MINUTE, verification_submitted_at, verification_decided_at)) / 60 AS h FROM pro_businesses
                                    WHERE verification_decided_at > UTC_TIMESTAMP() - INTERVAL 30 DAY AND verification_submitted_at IS NOT NULL");

        return Response::success([
            'pending_applications' => count($pending),
            'overdue_applications' => count(array_filter($pending, static fn ($p) => ($d = Verification::dueAt($p['verification_submitted_at'])) !== null && $d < $now)),
            'waiting_merchant' => (int) Database::fetch("SELECT COUNT(*) AS n FROM pro_businesses WHERE verification IN ('a_verifier', 'complement') AND deleted_at IS NULL")['n'],
            'pending_photos' => (int) Database::fetch("SELECT COUNT(*) AS n FROM place_visitor_photos WHERE business_id IS NULL AND status = 'pending'")['n'],
            'average_decision_hours' => $decided['h'] === null ? null : round((float) $decided['h'], 1),
            'decisions_count' => (int) $decided['n'],
        ]);
    }

    public function index(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = [max(1, min(100, (int) ($q['limit'] ?? 30))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
        $where = ['deleted_at IS NULL'];
        $params = [];
        if (in_array($q['status'] ?? null, self::STATUSES, true)) {
            $where[] = 'verification = :s';
            $params['s'] = $q['status'];
        }
        if (trim((string) ($q['q'] ?? '')) !== '') {
            $where[] = '(trade_name LIKE :q OR legal_name LIKE :q2 OR siret LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . trim((string) $q['q']) . '%';
        }
        $rows = Database::fetchAll('SELECT * FROM pro_businesses WHERE ' . implode(' AND ', $where)
            . " ORDER BY verification = 'en_cours' DESC, COALESCE(verification_submitted_at, created_at) ASC LIMIT " . ($limit + 1) . ' OFFSET ' . $offset, $params);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Database::fetchAll('SELECT verification, COUNT(*) AS n FROM pro_businesses WHERE deleted_at IS NULL GROUP BY verification') as $c) {
            $counts[$c['verification']] = (int) $c['n'];
        }

        return Response::success(['items' => array_map([self::class, 'application'], array_slice($rows, 0, $limit)),
                                  'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null, 'counts' => $counts]);
    }

    public function show(Request $request): Response
    {
        return Response::success(self::application($this->find($request)));
    }

    public function approve(Request $request): Response
    {
        $b = $this->find($request);
        Verification::approve($b, (string) $request->attributes['staff']['name']);
        Audit::log((int) $request->attributes['staff']['id'], 'merchant.approve', $request->ip, 'pro_businesses', $b['uid']);

        return Response::success(self::application($this->find($request)));
    }

    public function requestInfo(Request $request): Response
    {
        $message = trim((string) ($request->json()['message'] ?? ''));
        if (mb_strlen($message) < 10 || mb_strlen($message) > 1000) {
            throw new ValidationException(['message' => 'Le message doit faire entre 10 et 1000 caractères.']);
        }
        $b = $this->find($request);
        Verification::requestInfo($b, (string) $request->attributes['staff']['name'], $message);
        Audit::log((int) $request->attributes['staff']['id'], 'merchant.request_info', $request->ip, 'pro_businesses', $b['uid']);

        return Response::success(self::application($this->find($request)));
    }

    public function reject(Request $request): Response
    {
        $j = $request->json();
        $reason = $j['reason'] ?? null;
        $message = trim((string) ($j['message'] ?? ''));
        $errors = [];
        if (!isset(Verification::REFUSALS[$reason])) {
            $errors['reason'] = 'Motif inconnu.';
        }
        if ($reason === 'autre' && $message === '') {
            $errors['message'] = 'Précise le motif.';
        }
        if (mb_strlen($message) > 1000) {
            $errors['message'] = '1000 caractères au plus.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $b = $this->find($request);
        Verification::reject($b, (string) $request->attributes['staff']['name'], $reason, $message);
        Audit::log((int) $request->attributes['staff']['id'], 'merchant.reject', $request->ip, 'pro_businesses', $b['uid'], ['reason' => $reason]);

        return Response::success(self::application($this->find($request)));
    }

    private function find(Request $request): array
    {
        return Database::fetch('SELECT * FROM pro_businesses WHERE uid = :u AND deleted_at IS NULL', ['u' => (string) $request->param('id')])
            ?? throw new HttpException(404, 'not_found', 'Inscription introuvable.');
    }

    /** MerchantApplication */
    public static function application(array $b): array
    {
        $owner = Database::fetch("SELECT first_name, last_name, job_title, email, phone FROM pro_members WHERE business_id = :b AND role = 'proprietaire' ORDER BY id LIMIT 1", ['b' => $b['id']]);
        $place = $b['place_id'] ? Database::fetch('SELECT * FROM places WHERE id = :p', ['p' => $b['place_id']]) : null;
        $d = $place ? PlaceController::data($place) : null;
        $review = Verification::review($b);
        $completion = $d === null ? 0 : (int) round(100 * count(array_filter([
            mb_strlen($d['name']) >= 2, mb_strlen($d['description']) >= 80, $d['address'] !== '' && $d['latitude'] !== null, array_filter($d['hours']) !== [],
            $d['photos'] !== [], $d['phone'] !== '', $d['tagline'] !== '', $d['website'] !== '' || $d['instagram'] !== '',
        ])) / 8);

        return [
            'id' => $b['uid'], 'status' => $b['verification'], 'submitted_at' => Pro::iso($b['verification_submitted_at'] ?? null),
            'due_at' => $b['verification'] === 'en_cours' ? Verification::dueAt($b['verification_submitted_at'] ?? null) : null,
            'business' => ['trade_name' => $b['trade_name'], 'legal_name' => $b['legal_name'], 'siret' => $b['siret'], 'naf_code' => $b['naf_code'], 'naf_label' => $b['naf_label'],
                           'activity' => $b['activity'], 'created_at' => Pro::iso($b['created_at'])],
            'contact' => $owner ? ['first_name' => $owner['first_name'], 'last_name' => $owner['last_name'], 'job_title' => $owner['job_title'], 'email' => $owner['email'], 'phone' => $owner['phone']] : null,
            'place' => $d === null ? null : ['name' => $d['name'], 'category' => $d['category'], 'address' => $d['address'], 'postal_code' => $d['postal_code'], 'city' => $d['city'],
                                             'latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'website' => $d['website'], 'status' => $place['pro_status'], 'completion' => $completion],
            'claim' => $review['claim'], 'sirene' => $review['sirene'], 'signals' => Verification::signals($b, $place, $owner),
            'request' => $review['request'], 'refusal' => $review['refusal'], 'decided_at' => Pro::iso($b['verification_decided_at'] ?? null), 'history' => $review['history'],
        ];
    }
}
