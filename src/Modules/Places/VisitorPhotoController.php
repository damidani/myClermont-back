<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Photos des visiteurs côté app (demande iOS ca817cb4) :
 *   POST /visitor-photos { photoUrl, caption?, takenAt?, placeKey, place { name, category, latitude, longitude } } → 201 { id, status, reviewer }
 *   GET /me/visitor-photos                 → { items: [{ id, status, reviewer, rejectionReason? }], nextCursor }
 *   GET /visitor-photos?placeKey=…         → { items: [{ id, url, author, caption, createdAt }], nextCursor } (validées et dans la galerie, 30 au plus)
 */
final class VisitorPhotoController
{
    public function store(Request $request): Response
    {
        $j = $request->json();
        $userId = (int) $request->attributes['user_id'];
        $key = is_string($j['place_key'] ?? null) ? trim($j['place_key']) : '';
        $url = is_string($j['photo_url'] ?? null) ? trim($j['photo_url']) : '';
        $errors = [];
        if (!preg_match('/^(spot|place|event)-.{1,240}$/su', $key)) {
            $errors['placeKey'] = 'Lieu inconnu : impossible d\'envoyer la photo.';
        }
        $caption = is_string($j['caption'] ?? null) ? trim($j['caption']) : '';
        if (mb_strlen($caption) > 90) {
            $errors['caption'] = 'La légende doit faire 90 caractères au plus.';
        }
        // La photo doit venir de POST /uploads (purpose visitor_photo) de ce compte
        $upload = $url === '' ? null : Database::fetch("SELECT u.uid, m.path FROM uploads u LEFT JOIN media m ON m.uid = u.uid
                                                        WHERE u.url = :u AND u.user_id = :usr AND u.purpose = 'visitor_photo' AND u.confirmed_at IS NOT NULL", ['u' => $url, 'usr' => $userId]);
        if ($upload === null) {
            $errors['photoUrl'] = 'Photo introuvable : renvoie-la depuis l\'app.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $count = (int) Database::fetch('SELECT COUNT(*) AS n FROM place_visitor_photos WHERE user_id = :u AND created_at > :d', ['u' => $userId, 'd' => gmdate('Y-m-d H:i:s', time() - 86400)])['n'];
        if ($count >= VisitorPhotos::maxPerDay()) {
            throw new HttpException(429, 'too_many_photos', 'Tu as déjà envoyé ' . VisitorPhotos::maxPerDay() . ' photos aujourd\'hui. Réessaie demain !');
        }
        $file = $upload['path'] ? \App\Content\Media\MediaStore::root() . '/' . $upload['path'] : null;
        $hash = $file && is_file($file) ? hash_file('sha256', $file) : hash('sha256', $url);
        if ($dup = Database::fetch('SELECT uid, status, business_id FROM place_visitor_photos WHERE user_id = :u AND (file_hash = :h OR url = :url) LIMIT 1', ['u' => $userId, 'h' => $hash, 'url' => $url])) {
            // Même photo renvoyée (l'app réessaie après une coupure) : même réponse, sans doublon
            return Response::success(['id' => $dup['uid'], 'status' => $dup['status'], 'reviewer' => $dup['business_id'] ? 'merchant' : 'myclermont'], 201);
        }
        [$placeId, $eventId, $businessId, $name] = VisitorPhotos::route($key, is_array($j['place'] ?? null) ? $j['place'] : []);
        $takenAt = is_string($j['taken_at'] ?? null) && strtotime($j['taken_at']) ? gmdate('Y-m-d H:i:s', strtotime($j['taken_at'])) : null;
        $uid = Crypto::uuid4();
        Database::insert(
            'INSERT INTO place_visitor_photos (uid, user_id, place_key, place_id, event_id, business_id, pin_name, url, file_hash, caption, taken_at, created_at)
             VALUES (:uid, :u, :k, :p, :e, :b, :n, :url, :h, :c, :t, :d)',
            ['uid' => $uid, 'u' => $userId, 'k' => mb_substr($key, 0, 255), 'p' => $placeId, 'e' => $eventId, 'b' => $businessId, 'n' => $name, 'url' => $url,
             'h' => $hash, 'c' => $caption, 't' => $takenAt, 'd' => gmdate('Y-m-d H:i:s')],
        );
        if ($businessId !== null) {
            \App\Modules\Pro\Pro::notify($businessId, 'photo', 'Nouvelle photo d\'un visiteur', "Une photo de {$name} attend ta validation.", '/photos-visiteurs');
        }

        return Response::success(['id' => $uid, 'status' => 'pending', 'reviewer' => $businessId !== null ? 'merchant' : 'myclermont'], 201);
    }

    /**
     * DELETE /visitor-photos/{id} : l'auteur retire sa photo, quel que soit son statut (même validée).
     * Elle quitte la fiche (galerie et couverture du commerçant), la file de modération, l'espace pro et l'admin ;
     * le fichier est effacé. Idempotent : un id inconnu ou déjà supprimé répond aussi 204.
     */
    public function destroy(Request $request): Response
    {
        $userId = (int) $request->attributes['user_id'];
        $row = Database::fetch(VisitorPhotos::SELECT . ' WHERE v.uid = :id', ['id' => (string) $request->param('id')]);
        if ($row === null) {
            return Response::noContent();
        }
        if ((int) $row['user_id'] !== $userId) {
            throw new HttpException(404, 'not_found', 'Photo introuvable.');
        }
        if ((int) $row['in_gallery'] === 1) {
            VisitorPhotos::gallery($row, false, $row['business_id'] !== null ? (int) $row['business_id'] : null);
        }
        Database::execute('DELETE FROM place_visitor_photos WHERE id = :id', ['id' => $row['id']]);
        $media = Database::fetchAll("SELECT m.id FROM uploads u JOIN media m ON m.uid = u.uid WHERE u.url = :url AND u.user_id = :u AND u.purpose = 'visitor_photo'",
            ['url' => $row['url'], 'u' => $userId]);
        (new \App\Content\Media\MediaStore())->deleteByIds(array_column($media, 'id'));

        return Response::noContent();
    }

    public function mine(Request $request): Response
    {
        [$limit, $offset] = self::page($request);
        $rows = Database::fetchAll('SELECT uid, status, business_id, refusal_reason, refusal_note FROM place_visitor_photos WHERE user_id = :u ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            ['u' => $request->attributes['user_id']]);

        return Response::success([
            'items' => array_map(static fn ($r) => array_filter(['id' => $r['uid'], 'status' => $r['status'], 'reviewer' => $r['business_id'] ? 'merchant' : 'myclermont',
                'rejection_reason' => VisitorPhotos::reasonText($r['refusal_reason'], $r['refusal_note'])], static fn ($v) => $v !== null), array_slice($rows, 0, $limit)),
            'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null,
        ]);
    }

    public function index(Request $request): Response
    {
        $key = (string) ($request->query()['place_key'] ?? $request->query()['placeKey'] ?? '');
        if ($key === '') {
            throw new ValidationException(['placeKey' => 'Paramètre placeKey manquant.']);
        }
        // Validées et visibles : dans la galerie (une photo validée hors galerie, limite de formule atteinte, ne réapparaît pas ici)
        [$limit, $offset] = self::page($request, 30);
        $rows = Database::fetchAll(VisitorPhotos::SELECT . " WHERE v.place_key = :k AND v.status = 'approved' AND v.in_gallery = 1 ORDER BY v.decided_at DESC, v.id DESC LIMIT " . ($limit + 1) . ' OFFSET ' . $offset, ['k' => $key]);

        return Response::success([
            'items' => array_map(static fn ($r) => ['id' => $r['uid'], 'url' => $r['url'], 'author' => \App\Modules\Account\Handles::display($r['handle']),
                                                    'caption' => $r['caption'], 'created_at' => VisitorPhotos::iso($r['created_at']), 'author_is_staff' => (bool) $r['is_staff']], array_slice($rows, 0, $limit)),
            'next_cursor' => count($rows) > $limit ? (string) ($offset + $limit) : null,
        ]);
    }

    private static function page(Request $request, int $max = 30): array
    {
        $q = $request->query();

        return [max(1, min($max, (int) ($q['limit'] ?? $max))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
    }
}
