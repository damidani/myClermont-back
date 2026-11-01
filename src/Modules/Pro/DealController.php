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
 * Bons plans et validation des codes en boutique (demande 5).
 *
 *   GET /pro/deals ; POST /pro/deals ; PATCH|DELETE /pro/deals/{id}
 *   GET /pro/deal-codes?dealId=&status=&q= ; GET /pro/deal-codes/{code} ; POST /pro/deal-codes/{code}/redeem
 *
 * Statut pro → app : actif = published (visible dès startsAt, tant qu'il reste du stock), sinon draft / archived.
 */
final class DealController
{
    public const CATEGORIES = ['Restaurants', 'Gastronomie', 'Bien-être', 'Sorties', 'Sport', 'Culture', 'Shopping', 'Services', 'Famille'];
    private const STATUSES = ['brouillon', 'actif', 'en_pause', 'termine'];

    public function index(Request $request): Response
    {
        $rows = Database::fetchAll(
            'SELECT d.*, (SELECT COUNT(*) FROM deal_redemptions r WHERE r.deal_id = d.id) AS codes, (SELECT COUNT(*) FROM deal_redemptions r WHERE r.deal_id = d.id AND r.validated_at IS NOT NULL) AS used
             FROM deals d WHERE d.business_id = :b AND d.deleted_at IS NULL ORDER BY d.id DESC', ['b' => Pro::business($request)['id']]);

        return Response::success(array_map(static fn (array $d) => self::present($d) + ['stats' => ['codes' => (int) $d['codes'], 'used' => (int) $d['used']]], $rows));
    }

    public function create(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $business = Pro::business($request);
        $data = self::validate($request->json(), self::blank(), $business, null);
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert(
            "INSERT INTO deals (uid, place_id, business_id, merchant, title, category, created_at, updated_at, status) VALUES (:u, :p, :b, :m, :t, 'autre', :c, :up, 'draft')",
            ['u' => Crypto::uuid4(), 'p' => $business['place_id'], 'b' => $business['id'], 'm' => Pro::displayName($business), 't' => $data['title'] ?: 'Bon plan', 'c' => $now, 'up' => $now]);
        self::save($id, $data, $business);

        return Response::success(self::present($this->row($id)), 201);
    }

    public function update(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $deal = $this->find($request);
        $business = Pro::business($request);
        self::save((int) $deal['id'], self::validate($request->json(), self::data($deal), $business, (int) $deal['id']), $business);

        return Response::success(self::present($this->row((int) $deal['id'])));
    }

    public function destroy(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $deal = $this->find($request);
        Database::execute('DELETE FROM deal_redemptions WHERE deal_id = :d', ['d' => $deal['id']]);
        Database::execute("UPDATE deals SET deleted_at = :n, status = 'archived' WHERE id = :d", ['n' => gmdate('Y-m-d H:i:s'), 'd' => $deal['id']]);

        return Response::noContent();
    }

    public function codes(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = Pro::page($request, 50);
        $where = ['d.business_id = :b'];
        $params = ['b' => Pro::business($request)['id']];
        if (is_string($q['deal_id'] ?? null) && $q['deal_id'] !== '') {
            $where[] = 'd.uid = :d';
            $params['d'] = $q['deal_id'];
        }
        $where[] = match ($q['status'] ?? '') {
            'utilise' => 'r.validated_at IS NOT NULL',
            'expire'  => 'r.validated_at IS NULL AND r.expires_at IS NOT NULL AND r.expires_at < UTC_TIMESTAMP()',
            'valide'  => 'r.validated_at IS NULL AND (r.expires_at IS NULL OR r.expires_at >= UTC_TIMESTAMP())',
            default   => '1 = 1',
        };
        if (is_string($q['q'] ?? null) && trim($q['q']) !== '') {
            $where[] = '(r.code LIKE :q OR u.handle LIKE :q2)';
            $params['q'] = $params['q2'] = '%' . addcslashes(strtoupper(trim($q['q'])), '%_\\') . '%';
        }
        $rows = Database::fetchAll('SELECT r.*, d.uid AS deal_uid, u.handle, u.first_name, u.display_name FROM deal_redemptions r JOIN deals d ON d.id = r.deal_id LEFT JOIN users u ON u.id = r.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT ' . ($limit + 1) . " OFFSET {$offset}", $params);

        return Response::success(array_map([self::class, 'code'], array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function check(Request $request): Response
    {
        return Response::success($this->codeCheck($this->findCode($request)));
    }

    public function redeem(Request $request): Response
    {
        $r = $this->findCode($request);
        $check = $this->codeCheck($r);
        match ($check['status']) {
            'utilise' => throw new HttpException(409, 'conflict', 'Ce code a déjà été utilisé.', ['reason' => 'already_used', 'used_at' => $check['used_at']]),
            'expire'  => throw new HttpException(409, 'conflict', 'Ce code a expiré.', ['reason' => 'expired']),
            'inactif' => throw new HttpException(409, 'conflict', 'Ce bon plan n\'est pas actif.', ['reason' => 'deal_inactive']),
            default   => null,
        };
        $n = Database::execute('UPDATE deal_redemptions SET validated_at = :d, validated_by_member = :m WHERE id = :id AND validated_at IS NULL',
            ['d' => gmdate('Y-m-d H:i:s'), 'm' => Pro::member($request)['id'], 'id' => $r['id']]);
        if ($n === 0) {
            throw new HttpException(409, 'conflict', 'Ce code a déjà été utilisé.', ['reason' => 'already_used']);
        }

        return Response::success($this->codeCheck($this->findCode($request)));
    }

    // -----------------------------------------------------------------------

    public static function present(array $d): array
    {
        $p = self::data($d);

        return ['id' => $d['uid']] + $p + ['created_at' => Pro::iso($d['created_at'])];
    }

    private static function code(array $r): array
    {
        return ['code' => $r['code'], 'deal_id' => $r['deal_uid'], 'holder' => \App\Modules\Account\Handles::display($r['handle'] ?? null, Pro::shortName($r['first_name'] ?? null, null, $r['display_name'] ?? null)),
                'issued_at' => Pro::iso($r['redeemed_at']), 'expires_at' => Pro::iso($r['expires_at']), 'used_at' => Pro::iso($r['validated_at'])];
    }

    private function codeCheck(array $r): array
    {
        $deal = $this->row((int) $r['deal_id']);
        $status = match (true) {
            $r['validated_at'] !== null => 'utilise',
            $r['expires_at'] !== null && strtotime($r['expires_at'] . ' UTC') < time() => 'expire',
            $deal['pro_status'] !== 'actif' && $deal['pro_status'] !== null => 'inactif',
            default => 'valide',
        };
        $c = self::code($r + ['deal_uid' => $deal['uid']]);

        return ['code' => $c['code'], 'status' => $status, 'holder' => $c['holder'], 'deal' => self::present($deal),
                'issued_at' => $c['issued_at'], 'expires_at' => $c['expires_at'], 'used_at' => $c['used_at']];
    }

    /** Code saisi, lu dans le QR ou contenu dans le qrPayload de l'app (myclermont://deal/<id>/<code>). */
    private function findCode(Request $request): array
    {
        $raw = strtoupper((string) preg_replace('/\s+/', '', rawurldecode((string) $request->param('code'))));
        $code = preg_match('/MC-[A-Z0-9]{4}-[A-Z0-9]{3}/', $raw, $m) ? $m[0] : (preg_match('#(?:^|/)([A-Z0-9]{8})$#', $raw, $m) ? $m[1] : null);

        return ($code === null ? null : Database::fetch(
            'SELECT r.*, u.handle, u.first_name, u.display_name FROM deal_redemptions r JOIN deals d ON d.id = r.deal_id LEFT JOIN users u ON u.id = r.user_id WHERE r.code = :c AND d.business_id = :b',
            ['c' => $code, 'b' => Pro::business($request)['id']],
        )) ?? throw new HttpException(404, 'not_found', 'Code inconnu pour ton établissement.');
    }

    /** Données du formulaire pro (deals.pro), complétées par les colonnes. */
    private static function data(array $d): array
    {
        $p = json_decode((string) ($d['pro'] ?? ''), true) ?: [];

        return array_replace(self::blank(), [
            'title' => $d['title'], 'area' => (string) $d['area'], 'exclusive' => (bool) $d['exclusive_plus'], 'image' => $d['image_url'],
            'description' => (string) $d['description'], 'conditions' => (string) $d['conditions'], 'starts_at' => Pro::iso($d['starts_at']),
            'expires_at' => Pro::iso($d['expires_at']), 'quantity' => $d['max_redemptions'] !== null ? (int) $d['max_redemptions'] : null, 'per_person' => (int) $d['per_person'],
            'status' => $d['pro_status'] ?? 'brouillon',
        ], array_intersect_key($p, array_flip(['category', 'original', 'reduced', 'discount'])));
    }

    private static function blank(): array
    {
        return ['title' => '', 'category' => 'Restaurants', 'area' => '', 'original' => '', 'reduced' => '', 'discount' => '', 'exclusive' => false, 'image' => null,
                'description' => '', 'conditions' => '', 'starts_at' => null, 'expires_at' => null, 'quantity' => null, 'per_person' => 1, 'status' => 'brouillon'];
    }

    private static function validate(array $j, array $d, array $business, ?int $dealId): array
    {
        $errors = [];
        foreach (['title' => 80, 'area' => 100, 'original' => 40, 'reduced' => 40, 'discount' => 40, 'description' => 1000, 'conditions' => 1000] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        if (array_key_exists('category', $j)) {
            in_array($j['category'], self::CATEGORIES, true) ? $d['category'] = $j['category'] : $errors['category'] = 'Catégorie inconnue.';
        }
        if (array_key_exists('exclusive', $j)) {
            $d['exclusive'] = $j['exclusive'] === true;
        }
        if (array_key_exists('image', $j)) {
            Pro::url($j['image']) ? $d['image'] = $j['image'] ?: null : $errors['image'] = 'Image invalide.';
        }
        foreach (['starts_at', 'expires_at'] as $k) {
            if (array_key_exists($k, $j)) {
                $d[$k] = Pro::iso(Pro::toUtc($j[$k], $k));
            }
        }
        if (array_key_exists('quantity', $j)) {
            $j['quantity'] === null || (is_int($j['quantity']) && $j['quantity'] > 0) ? $d['quantity'] = $j['quantity'] : $errors['quantity'] = 'Quantité invalide.';
        }
        if (array_key_exists('per_person', $j)) {
            is_int($j['per_person']) && $j['per_person'] >= 1 && $j['per_person'] <= 10 ? $d['per_person'] = $j['per_person'] : $errors['per_person'] = 'Entre 1 et 10 codes par personne.';
        }
        $status = $j['status'] ?? null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'Statut inconnu.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $d['status'] = $status ?? ($d['status'] ?? 'brouillon');
        if ($d['status'] === 'actif') {
            $missing = [];
            foreach (['title' => 'Indique un titre.', 'reduced' => 'Indique le prix.', 'description' => 'Ajoute une description.', 'conditions' => 'Précise les conditions.'] as $k => $msg) {
                if (trim((string) $d[$k]) === '') {
                    $missing[$k] = $msg;
                }
            }
            if ($d['starts_at'] !== null && $d['expires_at'] !== null && strtotime($d['expires_at']) <= strtotime($d['starts_at'])) {
                $missing['expires_at'] = 'La date de fin doit suivre la date de début.';
            }
            if ($d['expires_at'] !== null && strtotime($d['expires_at']) < time()) {
                $missing['expires_at'] = 'La date de fin est déjà passée.';
            }
            if ($missing !== []) {
                throw new ValidationException($missing);
            }
            $max = Pro::limits($business)['active_deals'];
            $active = (int) Database::fetch("SELECT COUNT(*) AS n FROM deals WHERE business_id = :b AND pro_status = 'actif' AND deleted_at IS NULL AND id <> :id",
                ['b' => $business['id'], 'id' => $dealId ?? 0])['n'];
            if ($max !== null && $active >= $max) {
                throw Pro::planLimit('activeDeals', 'Ta formule gratuite permet un bon plan actif à la fois : mets l\'autre en pause ou passe Partenaire.');
            }
        }

        return $d;
    }

    private static function save(int $id, array $d, array $business): void
    {
        $original = Pro::cents($d['original']);
        $reduced = Pro::cents($d['reduced']);
        Database::execute(
            'UPDATE deals SET title = :t, category = :c, area = :a, original_cents = :o, reduced_cents = :r, discount_label = :dl, exclusive_plus = :x, image_url = :img,
                    description = :ds, conditions = :cd, starts_at = :sa, expires_at = :ea, max_redemptions = :q, per_person = :pp, merchant = :m,
                    pro_status = :ps, status = :s, pro = :pro, updated_at = :up WHERE id = :id',
            ['t' => $d['title'] ?: 'Bon plan', 'c' => $d['category'], 'a' => $d['area'] ?: null, 'o' => $original, 'r' => $reduced, 'dl' => $d['discount'] ?: null,
             'x' => (int) $d['exclusive'], 'img' => $d['image'], 'ds' => $d['description'] ?: null, 'cd' => $d['conditions'] ?: null,
             'sa' => Pro::toUtc($d['starts_at'], 'starts_at'), 'ea' => Pro::toUtc($d['expires_at'], 'expires_at'), 'q' => $d['quantity'], 'pp' => $d['per_person'],
             'm' => Pro::displayName($business), 'ps' => $d['status'], 's' => match ($d['status']) { 'actif' => 'published', 'termine' => 'archived', default => 'draft' },
             'pro' => json_encode(['category' => $d['category'], 'original' => $d['original'], 'reduced' => $d['reduced'], 'discount' => $d['discount']], JSON_UNESCAPED_UNICODE),
             'up' => gmdate('Y-m-d H:i:s'), 'id' => $id],
        );
        // Les codes déjà distribués suivent la date de fin du bon plan
        Database::execute('UPDATE deal_redemptions SET expires_at = :x WHERE deal_id = :d AND validated_at IS NULL', ['x' => Pro::toUtc($d['expires_at'], 'expires_at'), 'd' => $id]);
    }

    private function row(int $id): array
    {
        return Database::fetch('SELECT * FROM deals WHERE id = :id', ['id' => $id]);
    }

    private function find(Request $request): array
    {
        return Database::fetch('SELECT * FROM deals WHERE uid = :u AND business_id = :b AND deleted_at IS NULL', ['u' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Bon plan introuvable.');
    }
}
