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
        foreach (['category', 'area'] as $f) {
            if (isset($q[$f]) && is_string($q[$f]) && $q[$f] !== '') {
                $where[] = "d.{$f} = :{$f}";
                $params[$f] = $q[$f];
            }
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
        if ($deal['exclusive_plus'] && !PremiumService::isActiveById($userId)) {
            throw new HttpException(403, 'plus_required', 'Ce bon plan est réservé aux membres My Clermont+.');
        }
        if (Database::fetch('SELECT 1 FROM deal_redemptions WHERE deal_id = :d AND user_id = :u', ['d' => $deal['id'], 'u' => $userId])) {
            throw new HttpException(409, 'conflict', 'Tu as déjà obtenu ce bon plan.');
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
                    if ($e->getCode() !== '23000' || Database::fetch('SELECT 1 FROM deal_redemptions WHERE deal_id = :d AND user_id = :u', ['d' => $deal['id'], 'u' => $userId])) {
                        throw new HttpException(409, 'conflict', 'Tu as déjà obtenu ce bon plan.');
                    }
                }
            }
            throw new \RuntimeException('Génération du code impossible.');
        });

        return Response::success([
            'code'       => $code,
            'expires_at' => $deal['expires_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($deal['expires_at'] . ' UTC')) : null,
            'qr_payload' => 'myclermont://deal/' . $deal['uid'] . '/' . $code,
        ], 201);
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

    public static function present(array $d): array
    {
        $euros = static fn ($c) => $c === null ? null : round((int) $c / 100, 2);

        return [
            'id'          => $d['uid'],
            'title'       => $d['title'],
            'merchant'    => $d['merchant'],
            'place_id'    => $d['place_uid'],
            'area'        => $d['area'],
            'category'    => $d['category'],
            'original'    => $euros($d['original_cents']),
            'reduced'     => $euros($d['reduced_cents']),
            'discount'    => $d['discount_label'] ?? ($d['original_cents'] && $d['reduced_cents'] !== null
                                ? '-' . (int) round(100 - $d['reduced_cents'] * 100 / $d['original_cents']) . ' %' : null),
            'exclusive'   => (bool) $d['exclusive_plus'],
            'image_url'   => $d['image_url'],
            'description' => $d['description'],
            'conditions'  => $d['conditions'],
            'expires_at'  => $d['expires_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($d['expires_at'] . ' UTC')) : null,
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
