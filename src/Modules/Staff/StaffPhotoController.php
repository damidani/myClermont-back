<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Places\VisitorPhotos;

/**
 * Photos des visiteurs sans commerçant vérifié, validées par l'équipe depuis /admin du dashboard (ca817cb4) :
 * mêmes routes que /pro/visitor-photos*, éléments enrichis de pin { key, kind, name, city, claimedBy }.
 * Une photo validée par l'équipe rejoint la galerie du pin sans limite de formule.
 */
final class StaffPhotoController
{
    private const STATUS = ['en_attente' => 'pending', 'validee' => 'approved', 'refusee' => 'rejected'];

    public function index(Request $request): Response
    {
        $q = $request->query();
        [$limit, $offset] = [max(1, min(100, (int) ($q['limit'] ?? 30))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
        $status = self::STATUS[$q['status'] ?? ''] ?? null;
        $rows = Database::fetchAll(VisitorPhotos::SELECT . ' WHERE v.business_id IS NULL' . ($status !== null ? ' AND v.status = :s' : '')
            . ' ORDER BY ' . ($status === 'pending' ? 'v.id ASC' : "v.status = 'pending' DESC, v.decided_at DESC, v.id DESC") . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            $status !== null ? ['s' => $status] : []);
        $counts = ['en_attente' => 0, 'validee' => 0, 'refusee' => 0];
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM place_visitor_photos WHERE business_id IS NULL GROUP BY status') as $c) {
            $counts[VisitorPhotos::PRO_STATUS[$c['status']]] = (int) $c['n'];
        }

        return Response::success(['items' => array_map(static fn ($r) => VisitorPhotos::present($r, true), array_slice($rows, 0, $limit)),
                                  'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null, 'counts' => $counts]);
    }

    public function validate(Request $request): Response
    {
        $j = $request->json();
        $ids = is_array($j['ids'] ?? null) ? array_values(array_filter($j['ids'], 'is_string')) : [];
        if ($ids === [] || count($ids) > 50) {
            throw new ValidationException(['ids' => 'Choisis entre 1 et 50 photos.']);
        }
        $r = VisitorPhotos::validate($ids, null, VisitorPhotos::TEAM, ($j['add_to_gallery'] ?? true) !== false, false);
        \App\Admin\Audit::log((int) $request->attributes['staff']['id'], 'visitor_photo.validate', $request->ip, 'place_visitor_photos', implode(',', $ids));

        return Response::success(['validated' => $r['validated'], 'added_to_gallery' => $r['added'], 'over_limit' => $r['over'], 'cover' => null,
                                  'items' => array_map(static fn ($p) => VisitorPhotos::present($p, true), VisitorPhotos::find($ids, null))]);
    }

    public function refuse(Request $request): Response
    {
        $j = $request->json();
        if (!isset(VisitorPhotos::REASONS[$j['reason'] ?? null])) {
            throw new ValidationException(['reason' => 'Motif inconnu.']);
        }
        $note = is_string($j['note'] ?? null) && trim($j['note']) !== '' ? mb_substr(trim($j['note']), 0, 200) : null;
        VisitorPhotos::refuse($this->find($request), $j['reason'], $note, VisitorPhotos::TEAM);
        \App\Admin\Audit::log((int) $request->attributes['staff']['id'], 'visitor_photo.refuse', $request->ip, 'place_visitor_photos', (string) $request->param('id'));

        return Response::success(VisitorPhotos::present($this->find($request), true));
    }

    public function update(Request $request): Response
    {
        $in = $request->json()['in_gallery'] ?? null;
        if (!is_bool($in)) {
            throw new ValidationException(['inGallery' => 'Booléen attendu.']);
        }
        $row = $this->find($request);
        if ($row['status'] !== 'approved') {
            throw new HttpException(409, 'not_validated', 'Valide d\'abord la photo.');
        }
        VisitorPhotos::gallery($row, $in, null);

        return Response::success(['photo' => VisitorPhotos::present($this->find($request), true)]);
    }

    public function reopen(Request $request): Response
    {
        VisitorPhotos::reopen($this->find($request));

        return Response::success(VisitorPhotos::present($this->find($request), true));
    }

    private function find(Request $request): array
    {
        return VisitorPhotos::find([(string) $request->param('id')], null)[0] ?? throw new HttpException(404, 'not_found', 'Photo introuvable.');
    }
}
