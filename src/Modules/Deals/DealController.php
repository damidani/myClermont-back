<?php

declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Premium\PremiumService;

/**
 * Bons plans selon la spec v1 (› Ma Ville).
 *
 *   GET  /deals?category=&area=                        { items: Deal[], nextCursor }
 *   GET  /deals/{id}                                   Deal
 *   POST /deals/{id}/redeem                            { code, expiresAt, qrPayload }  (un code par compte et par offre)
 *   POST /deals/redemptions/{code}/validate  partner   { code, deal, validatedAt }    (le commerçant valide en caisse)
 */
final class DealController
{
    private const COLUMNS = 'd.*, p.uid AS place_uid, p.manager_user_id';
    private const FROM = 'deals d LEFT JOIN places p ON p.id = d.place_id';
    private const LIVE = "d.status = 'published' AND d.deleted_at IS NULL AND (d.starts_at IS NULL OR d.starts_at <= UTC_TIMESTAMP())
                          AND (d.expires_at IS NULL OR d.expires_at > UTC_TIMESTAMP())";

    public function index(Request $request): Response
    {
        $q = $request->query();
        $where = [self::LIVE];
        $params = [];
        if (isset($q['area']) && is_string($q['area']) && $q['area'] !== '') {
            $where[] = 'd.area = :area';
            $params['area'] = $q['area'];
        }
        // Catégorie : libellé de l'app (« Restaurants ») ou slug interne
        if (isset($q['category']) && is_string($q['category']) && $q['category'] !== '') {
            $slugs = array_keys(self::CATEGORIES, $q['category'], true) ?: [$q['category']];
            $in = [];
            foreach (array_values($slugs) as $i => $slug) {
                $in[] = ':cat' . $i;
                $params['cat' . $i] = $slug;
            }
            $where[] = 'd.category IN (' . implode(', ', $in) . ')';
        }
        $limit = min(50, max(1, (int) ($q['limit'] ?? 20)));
        $offset = isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0;
        $rows = Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY d.exclusive_plus DESC, COALESCE(d.expires_at, \'9999-12-31\'), d.id LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            $params,
        );

        return Response::success(array_map([self::class, 'present'], array_slice($rows, 0, $limit)), 200,
            ['next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null]);
    }

    public function show(Request $request): Response
    {
        return Response::success(self::present($this->find((string) $request->param('id'))));
    }

    public function redeem(Request $request): Response
    {
        $deal = $this->find((string) $request->param('id'));
        $userId = (int) $request->attributes['user_id'];
        // Le compte a déjà son code : on le renvoie (l'utilisateur rouvre la fiche), 200 sans erreur
        $existing = static fn () => Database::fetch('SELECT code FROM deal_redemptions WHERE deal_id = :d AND user_id = :u', ['d' => $deal['id'], 'u' => $userId]);
        if ($row = $existing()) {
            return Response::success(self::redemption($deal, $row['code']));
        }
        if ($deal['exclusive_plus'] && !PremiumService::isActiveById($userId)) {
            throw new HttpException(403, 'plus_required', 'Ce bon plan est réservé aux membres My Clermont+.');
        }

        $code = Database::transaction(static function () use ($deal, $userId): string {
            // Plafond respecté même si 100 personnes valident en même temps
            if ($deal['max_redemptions'] !== null && Database::execute(
                'UPDATE deals SET redemptions_count = redemptions_count + 1 WHERE id = :id AND redemptions_count < max_redemptions', ['id' => $deal['id']],
            ) === 0) {
                throw new HttpException(409, 'conflict', 'Il n\'y a plus de bon plan disponible.', ['reason' => 'sold_out']);
            }
            if ($deal['max_redemptions'] === null) {
                Database::execute('UPDATE deals SET redemptions_count = redemptions_count + 1 WHERE id = :id', ['id' => $deal['id']]);
            }
            for ($i = 0; $i < 5; $i++) {
                $code = self::newCode();
                try {
                    Database::execute(
                        'INSERT INTO deal_redemptions (deal_id, user_id, code, redeemed_at, expires_at) VALUES (:d, :u, :c, :r, :x)',
                        ['d' => $deal['id'], 'u' => $userId, 'c' => $code, 'r' => gmdate('Y-m-d H:i:s'), 'x' => $deal['expires_at']],
                    );
                    return $code;
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    // Deux appels simultanés du même compte : le premier code gagne
                    if ($row = Database::fetch('SELECT code FROM deal_redemptions WHERE deal_id = :d AND user_id = :u', ['d' => $deal['id'], 'u' => $userId])) {
                        return $row['code'];
                    }
                }
            }
            throw new \RuntimeException('Génération du code impossible.');
        });

        return Response::success(self::redemption($deal, $code), 201);
    }

    /** Réponse de POST /deals/{id}/redeem : { code, expiresAt, qrPayload } */
    public static function redemption(array $deal, string $code): array
    {
        return [
            'code'       => $code,
            'expires_at' => $deal['expires_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($deal['expires_at'] . ' UTC')) : null,
            'qr_payload' => 'myclermont://deal/' . $deal['uid'] . '/' . $code,
        ];
    }

    /** Validation en caisse par le commerçant (compte partner gérant le lieu du bon plan) ou un administrateur. */
    public function validateCode(Request $request): Response
    {
        $code = strtoupper((string) $request->param('code'));
        $row = preg_match('/^[A-Z0-9]{8}$/', $code) ? Database::fetch(
            'SELECT r.*, d.uid AS deal_uid, d.title, p.manager_user_id FROM deal_redemptions r JOIN deals d ON d.id = r.deal_id LEFT JOIN places p ON p.id = d.place_id WHERE r.code = :c',
            ['c' => $code],
        ) : null;
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Code inconnu.');
        }
        $role = $request->attributes['role'] ?? 'user';
        if ($role !== 'admin' && (int) $row['manager_user_id'] !== (int) $request->attributes['user_id']) {
            throw HttpException::forbidden('forbidden', 'Ce code ne concerne pas ton établissement.');
        }
        if ($row['validated_at'] !== null) {
            throw new HttpException(409, 'conflict', 'Ce code a déjà été utilisé le ' . gmdate('d/m/Y', strtotime($row['validated_at'] . ' UTC')) . '.');
        }
        if ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') < time()) {
            throw new HttpException(409, 'conflict', 'Ce bon plan a expiré.');
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::execute('UPDATE deal_redemptions SET validated_at = :d, validated_by = :u WHERE id = :id AND validated_at IS NULL',
            ['d' => $now, 'u' => $request->attributes['user_id'], 'id' => $row['id']]);

        return Response::success(['code' => $code, 'deal' => ['id' => $row['deal_uid'], 'title' => $row['title']], 'validated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /** Catégorie interne (slug) → libellé de l'app (brief iOS §11.3) */
    public const CATEGORIES = [
        'restaurant' => 'Restaurants', 'restaurants' => 'Restaurants', 'cafe' => 'Restaurants', 'boulangerie' => 'Restaurants', 'bar' => 'Sorties',
        'bien-etre' => 'Bien-être', 'beaute' => 'Bien-être', 'spa' => 'Bien-être', 'sante' => 'Bien-être',
        'sorties' => 'Sorties', 'culture' => 'Sorties', 'loisirs' => 'Sorties', 'spectacle' => 'Sorties', 'shopping' => 'Sorties',
        'sport' => 'Sport', 'autre' => 'Sorties',
    ];

    public static function present(array $d): array
    {
        $euros = static fn ($c) => $c === null ? null : round((int) $c / 100, 2);
        $label = static fn ($c) => $c === null ? '' : number_format((int) $c / 100, 2, ',', ' ') . ' €';
        $expires = $d['expires_at'] ? strtotime($d['expires_at'] . ' UTC') : null;
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $paris = $expires === null ? null : (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone('Europe/Paris'));

        $category = self::CATEGORIES[$d['category']] ?? (in_array($d['category'], self::CATEGORIES, true) ? $d['category'] : 'Sorties');

        return [
            'id'          => $d['uid'],
            'title'       => (string) $d['title'],
            'merchant'    => (string) ($d['merchant'] ?? ''),
            'place_id'    => $d['place_uid'],
            'area'        => (string) ($d['area'] ?? ''),
            'category'    => $category,
            // Libellés prêts à afficher (« 3,50 € ») ; montants en euros dans originalAmount / reducedAmount
            'original'    => $label($d['original_cents']),
            'reduced'     => $label($d['reduced_cents']),
            'original_amount' => $euros($d['original_cents']),
            'reduced_amount'  => $euros($d['reduced_cents']),
            'discount'    => (string) ($d['discount_label'] ?? ($d['original_cents'] && $d['reduced_cents'] !== null
                                ? '-' . (int) round(100 - $d['reduced_cents'] * 100 / $d['original_cents']) . ' %' : '')),
            'exclusive'   => (bool) $d['exclusive_plus'],
            'image'       => \App\Support\DefaultImage::urls([$d['image_url']], 'deal-' . \App\Support\DefaultImage::slug($category))[0],
            'image_url'   => $d['image_url'],
            'description' => (string) ($d['description'] ?? ''),
            'conditions'  => (string) ($d['conditions'] ?? ''),
            'expires'     => $paris === null ? 'Sans date limite' : 'Jusqu\'au ' . $paris->format('j') . ' ' . $months[(int) $paris->format('n') - 1],
            'expires_at'  => $expires ? gmdate('Y-m-d\TH:i:s\Z', $expires) : null,
        ];
    }

    private function find(string $id): array
    {
        $d = preg_match('/^[0-9a-f-]{36}$/i', $id)
            ? Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE d.uid = :u AND ' . self::LIVE, ['u' => strtolower($id)])
            : null;

        return $d ?? throw new HttpException(404, 'deal_not_found', 'Ce bon plan n\'existe pas ou a expiré.');
    }

    /** 8 caractères sans ambiguïté (pas de 0/O, 1/I/L), faciles à dicter en caisse. */
    private static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
