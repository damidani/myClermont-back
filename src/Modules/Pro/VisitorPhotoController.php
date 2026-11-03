<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Places\VisitorPhotos;

/**
 * Photos des visiteurs dans le dashboard (ca817cb4) : lecture pour toute l'équipe, décisions pour le propriétaire et le gérant.
 *   GET /pro/visitor-photos?status=      → { items, nextCursor, counts: { en_attente, validee, refusee } }
 *   GET /pro/visitor-photos/{id}
 *   POST /pro/visitor-photos/validate { ids, addToGallery, setAsCover? } → { validated, addedToGallery, overLimit, cover, items, place: { photos, coverId } }
 *   POST /pro/visitor-photos/{id}/refuse { reason, note? }
 *   PATCH /pro/visitor-photos/{id} { inGallery } → { photo, place }
 *   POST /pro/visitor-photos/{id}/reopen
 */
final class VisitorPhotoController
{
    private const STATUS = ['en_attente' => 'pending', 'validee' => 'approved', 'refusee' => 'rejected'];

    public function index(Request $request): Response
    {
        $b = (int) Pro::business($request)['id'];
        [$limit, $offset] = Pro::page($request);
        $status = self::STATUS[$request->query()['status'] ?? ''] ?? null;
        $where = 'v.business_id = :b' . ($status !== null ? ' AND v.status = :s' : '');
        $order = $status === 'pending' ? 'v.id ASC' : "v.status = 'pending' DESC, v.decided_at DESC, v.id DESC";
        $rows = Database::fetchAll(VisitorPhotos::SELECT . " WHERE {$where} ORDER BY {$order} LIMIT " . ($limit + 1) . ' OFFSET ' . $offset, ['b' => $b] + ($status !== null ? ['s' => $status] : []));
        $counts = ['en_attente' => 0, 'validee' => 0, 'refusee' => 0];
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM place_visitor_photos WHERE business_id = :b GROUP BY status', ['b' => $b]) as $c) {
            $counts[VisitorPhotos::PRO_STATUS[$c['status']]] = (int) $c['n'];
        }

        return Response::success(['items' => array_map([VisitorPhotos::class, 'present'], array_slice($rows, 0, $limit)),
                                  'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null, 'counts' => $counts]);
    }

    public function show(Request $request): Response
    {
        return Response::success(VisitorPhotos::present($this->find($request)));
    }

    public function validate(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $j = $request->json();
        $ids = is_array($j['ids'] ?? null) ? array_values(array_filter($j['ids'], 'is_string')) : [];
        if ($ids === [] || count($ids) > 50) {
            throw new ValidationException(['ids' => 'Choisis entre 1 et 50 photos.']);
        }
        $cover = ($j['set_as_cover'] ?? false) === true;
        if ($cover && count($ids) !== 1) {
            throw new ValidationException(['setAsCover' => 'La photo de couverture se choisit pour une seule photo.']);
        }
        $b = (int) Pro::business($request)['id'];
        $r = VisitorPhotos::validate($ids, $b, self::by($request), ($j['add_to_gallery'] ?? false) === true, $cover);

        return Response::success([
            'validated' => $r['validated'], 'added_to_gallery' => $r['added'], 'over_limit' => $r['over'], 'cover' => $r['cover'],
            'items' => array_map([VisitorPhotos::class, 'present'], VisitorPhotos::find($ids, $b)), 'place' => self::gallery($request),
        ]);
    }

    public function refuse(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $j = $request->json();
        $reason = $j['reason'] ?? null;
        if (!isset(VisitorPhotos::REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Motif inconnu.']);
        }
        $note = is_string($j['note'] ?? null) && trim($j['note']) !== '' ? mb_substr(trim($j['note']), 0, 200) : null;
        VisitorPhotos::refuse($this->find($request), $reason, $note, self::by($request));

        return Response::success(VisitorPhotos::present($this->find($request)));
    }

    public function update(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $in = $request->json()['in_gallery'] ?? null;
        if (!is_bool($in)) {
            throw new ValidationException(['inGallery' => 'Booléen attendu.']);
        }
        $row = $this->find($request);
        if ($row['status'] !== 'approved') {
            throw new HttpException(409, 'not_validated', 'Valide d\'abord la photo pour l\'ajouter à ta galerie.');
        }
        $business = Pro::business($request);
        if (!VisitorPhotos::gallery($row, $in, (int) $business['id'])) {
            $max = Pro::limits($business)['photos'];
            throw new HttpException(403, 'plan_limit', "Ta galerie est pleine ({$max} photos avec ta formule).", ['max' => $max, 'current' => count(PlaceController::data(Pro::place($request))['photos'])]);
        }

        return Response::success(['photo' => VisitorPhotos::present($this->find($request)), 'place' => self::gallery($request)]);
    }

    public function reopen(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        VisitorPhotos::reopen($this->find($request));

        return Response::success(VisitorPhotos::present($this->find($request)));
    }

    private function find(Request $request): array
    {
        return VisitorPhotos::find([(string) $request->param('id')], (int) Pro::business($request)['id'])[0]
            ?? throw new HttpException(404, 'not_found', 'Photo introuvable.');
    }

    private static function gallery(Request $request): array
    {
        $d = PlaceController::data(Pro::place($request));

        return ['photos' => $d['photos'], 'cover_id' => $d['cover_id']];
    }

    private static function by(Request $request): string
    {
        $m = Pro::member($request);

        return Pro::shortName($m['first_name'], $m['last_name'], $m['email']);
    }
}

