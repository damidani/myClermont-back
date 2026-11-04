<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\HttpException;
use App\Modules\Notifications\Notices;
use App\Modules\Pro\PlaceController as ProPlace;

/**
 * Photos des visiteurs (demande iOS ca817cb4) : décisions partagées entre le commerçant (/pro/visitor-photos)
 * et l'équipe MyClermont (back-office, photos sans commerçant vérifié).
 *   statuts app : pending | approved | rejected     statuts pro : en_attente | validee | refusee
 */
final class VisitorPhotos
{
    public const REASONS = ['floue' => 'Photo floue', 'hors_sujet' => 'Photo hors sujet', 'personnes' => 'Des personnes sont reconnaissables',
                            'inapproprie' => 'Contenu inapproprié', 'doublon' => 'Photo déjà publiée', 'autre' => 'Photo non retenue'];
    public const PRO_STATUS = ['pending' => 'en_attente', 'approved' => 'validee', 'rejected' => 'refusee'];
    public const TEAM = 'Équipe MyClermont';

    /**
     * Pin désigné par la clé de l'app → [place_id, event_id, business_id vérifié, nom].
     * Un repère spot-… inconnu devient une ébauche de pin (brouillon) pour la modération et une future revendication.
     */
    public static function route(string $key, array $pin): array
    {
        $placeId = $eventId = null;
        $name = trim((string) ($pin['name'] ?? ''));
        if (str_starts_with($key, 'event-')) {
            $e = Database::fetch('SELECT id, place_id, business_id, title FROM events WHERE slug = :s AND deleted_at IS NULL', ['s' => substr($key, 6)]);
            $eventId = $e['id'] ?? null;
            $placeId = $e['place_id'] ?? null;
            $name = $e['title'] ?? $name;
            $businessId = $e['business_id'] ?? null;
        } else {
            $id = preg_replace('/^(spot|place)-/', '', $key);
            $p = Database::fetch('SELECT id, name, business_id FROM places WHERE app_id = :a AND deleted_at IS NULL', ['a' => $id])
                ?? (preg_match('/^[0-9a-f-]{36}$/', $id) ? Database::fetch('SELECT id, name, business_id FROM places WHERE uid = :u AND deleted_at IS NULL', ['u' => $id]) : null)
                ?? (str_starts_with($key, 'place-') ? Database::fetch('SELECT id, name, business_id FROM places WHERE slug = :s AND deleted_at IS NULL', ['s' => $id]) : null);
            if ($p === null && str_starts_with($key, 'spot-') && is_numeric($pin['latitude'] ?? null) && is_numeric($pin['longitude'] ?? null) && $name !== '') {
                $p = ['id' => self::draftPin($id, $name, (string) ($pin['category'] ?? 'lieu'), (float) $pin['latitude'], (float) $pin['longitude']), 'name' => $name, 'business_id' => null];
            }
            $placeId = $p['id'] ?? null;
            $name = $p['name'] ?? $name;
            $businessId = $p['business_id'] ?? null;
        }
        // Seulement un établissement vérifié ; sinon la file de l'équipe
        $verified = $businessId !== null && Database::fetch("SELECT 1 FROM pro_businesses WHERE id = :b AND verification = 'verifie' AND deleted_at IS NULL", ['b' => $businessId]);

        return [$placeId !== null ? (int) $placeId : null, $eventId !== null ? (int) $eventId : null, $verified ? (int) $businessId : null, mb_substr($name ?: 'ce lieu', 0, 160)];
    }

    private static function draftPin(string $appId, string $name, string $category, float $lat, float $lon): int
    {
        $category = in_array($category, ProPlace::CATEGORIES, true) ? $category : 'lieu';
        $now = gmdate('Y-m-d H:i:s');

        return Database::insert(
            "INSERT INTO places (uid, app_id, category_id, app_category, name, latitude, longitude, city, status, created_at, updated_at)
             VALUES (:u, :a, (SELECT id FROM place_categories ORDER BY sort_order LIMIT 1), :c, :n, :lat, :lon, 'Clermont-Ferrand', 'draft', :d, :d2)",
            ['u' => \App\Security\Crypto::uuid4(), 'a' => mb_substr($appId, 0, 120), 'c' => $category, 'n' => mb_substr($name, 0, 120), 'lat' => $lat, 'lon' => $lon, 'd' => $now, 'd2' => $now],
        );
    }

    /** VisitorPhoto du dashboard (camelCase par le contrat) ; $admin ajoute pin { key, kind, name, city, claimedBy } */
    public static function present(array $v, bool $admin = false): array
    {
        $out = [
            'id' => $v['uid'], 'src' => $v['url'], 'author' => \App\Modules\Account\Handles::display($v['handle'] ?? null), 'caption' => $v['caption'],
            'place_key' => $v['place_key'], 'submitted_at' => self::iso($v['created_at']), 'taken_at' => self::iso($v['taken_at']),
            'status' => self::PRO_STATUS[$v['status']], 'decided_at' => self::iso($v['decided_at']), 'decided_by' => $v['decided_by'],
            'refusal_reason' => $v['refusal_reason'], 'refusal_note' => $v['refusal_note'], 'in_gallery' => (bool) $v['in_gallery'],
        ];
        if ($admin) {
            $claim = $v['place_id'] ? Database::fetch('SELECT b.id, b.trade_name, b.verification FROM places p JOIN pro_businesses b ON b.id = p.business_id WHERE p.id = :p', ['p' => $v['place_id']]) : null;
            $out['pin'] = ['key' => $v['place_key'], 'kind' => explode('-', $v['place_key'])[0], 'name' => $v['pin_name'],
                           'city' => (string) (Database::fetch('SELECT city FROM places WHERE id = :p', ['p' => $v['place_id'] ?? 0])['city'] ?? ''),
                           'claimed_by' => $claim ? ['business_id' => $claim['id'], 'name' => $claim['trade_name'], 'status' => $claim['verification']] : null];
        }

        return $out;
    }

    public const SELECT = 'SELECT v.*, u.handle, u.is_staff FROM place_visitor_photos v LEFT JOIN users u ON u.id = v.user_id';

    /**
     * Validation de photos en attente. $businessId null = équipe (galerie sans limite de formule).
     * @return array{validated: list<string>, added: list<string>, over: list<string>, cover: ?string}
     */
    public static function validate(array $ids, ?int $businessId, string $by, bool $addToGallery, bool $setAsCover): array
    {
        $rows = self::find($ids, $businessId);
        $decided = array_values(array_map(static fn ($r) => $r['uid'], array_filter($rows, static fn ($r) => $r['status'] !== 'pending')));
        if ($decided !== [] || count($rows) !== count(array_unique($ids))) {
            throw new HttpException(409, 'already_decided', 'Certaines photos ont déjà été traitées.', ['ids' => $decided ?: array_values(array_diff($ids, array_column($rows, 'uid')))]);
        }
        $out = ['validated' => [], 'added' => [], 'over' => [], 'cover' => null];
        foreach ($rows as $r) {
            Database::execute("UPDATE place_visitor_photos SET status = 'approved', decided_at = :d, decided_by = :by, refusal_reason = NULL, refusal_note = NULL WHERE id = :id",
                ['d' => gmdate('Y-m-d H:i:s'), 'by' => $by, 'id' => $r['id']]);
            $out['validated'][] = $r['uid'];
            if ($addToGallery) {
                self::gallery($r, true, $businessId) ? $out['added'][] = $r['uid'] : $out['over'][] = $r['uid'];
            }
            self::notifyAuthor($r, true);
        }
        if ($setAsCover && count($rows) === 1 && in_array($rows[0]['uid'], $out['added'], true)) {
            self::setCover((int) $rows[0]['place_id'], 'ph_' . $rows[0]['uid']);
            $out['cover'] = 'ph_' . $rows[0]['uid'];
        }

        return $out;
    }

    public static function refuse(array $row, string $reason, ?string $note, string $by): void
    {
        if ($row['status'] === 'rejected') {
            throw new HttpException(409, 'already_decided', 'Cette photo a déjà été refusée.', ['ids' => [$row['uid']]]);
        }
        self::gallery($row, false, null);
        Database::execute("UPDATE place_visitor_photos SET status = 'rejected', in_gallery = 0, refusal_reason = :r, refusal_note = :n, decided_at = :d, decided_by = :by WHERE id = :id",
            ['r' => $reason, 'n' => $note, 'd' => gmdate('Y-m-d H:i:s'), 'by' => $by, 'id' => $row['id']]);
        self::notifyAuthor(['refusal_reason' => $reason, 'refusal_note' => $note] + $row, false);
    }

    /** Retour en attente, hors galerie, sans notification */
    public static function reopen(array $row): void
    {
        self::gallery($row, false, null);
        Database::execute("UPDATE place_visitor_photos SET status = 'pending', in_gallery = 0, decided_at = NULL, decided_by = NULL, refusal_reason = NULL, refusal_note = NULL WHERE id = :id", ['id' => $row['id']]);
    }

    /**
     * Ajout ou retrait de la galerie de la fiche (places.pro.photos pour une fiche commerçant).
     * @return bool false si la limite de photos de la formule est atteinte
     */
    public static function gallery(array $row, bool $add, ?int $businessId): bool
    {
        $place = $row['place_id'] ? Database::fetch('SELECT * FROM places WHERE id = :id', ['id' => $row['place_id']]) : null;
        $photoId = 'ph_' . $row['uid'];
        if ($place !== null && $place['pro'] !== null) {
            $d = ProPlace::data($place);
            $photos = array_values(array_filter($d['photos'], static fn ($p) => $p['id'] !== $photoId));
            if ($add) {
                $max = $businessId !== null ? (int) (\App\Modules\Pro\Pro::limits(Database::fetch('SELECT plan FROM pro_businesses WHERE id = :b', ['b' => $businessId]) ?? ['plan' => 'gratuit'])['photos']) : PHP_INT_MAX;
                if (count($photos) >= $max) {
                    return false;
                }
                $photos[] = ['id' => $photoId, 'src' => $row['url'], 'caption' => $row['caption'], 'credit' => 'Photo de @' . \App\Modules\Account\Handles::display($row['handle'] ?? null), 'visitor_photo_id' => $row['uid']];
            }
            $d['photos'] = $photos;
            if (!$add && $d['cover_id'] === $photoId) {
                $d['cover_id'] = $photos[0]['id'] ?? null;
            }
            Database::execute('UPDATE places SET pro = :j WHERE id = :id', ['j' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $place['id']]);
            if ($place['status'] === 'published') {
                ProPlace::sync((int) $place['id'], ProPlace::published(['pro' => json_encode($d)] + $place), true);
            }
        }
        Database::execute('UPDATE place_visitor_photos SET in_gallery = :g WHERE id = :id', ['g' => $add ? 1 : 0, 'id' => $row['id']]);

        return true;
    }

    private static function setCover(int $placeId, string $photoId): void
    {
        $place = Database::fetch('SELECT * FROM places WHERE id = :id', ['id' => $placeId]);
        if ($place === null || $place['pro'] === null) {
            return;
        }
        $d = ProPlace::data($place);
        $d['cover_id'] = $photoId;
        Database::execute('UPDATE places SET pro = :j WHERE id = :id', ['j' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $placeId]);
        if ($place['status'] === 'published') {
            ProPlace::sync($placeId, ProPlace::published(['pro' => json_encode($d)] + $place), true);
        }
    }

    /** photo_approved / photo_rejected (4890352c) */
    private static function notifyAuthor(array $r, bool $approved): void
    {
        $place = $r['place_id'] ? Database::fetch('SELECT ' . MapController::columns() . ' FROM places p JOIN place_categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $r['place_id']]) : null;
        $spot = $place ? MapController::place($place) : null;
        $target = ['type' => 'photo', 'id' => $r['uid'], 'placeKey' => $r['place_key'], 'name' => $r['pin_name']]
            + ($spot ? ['latitude' => $spot['latitude'], 'longitude' => $spot['longitude'], 'category' => $spot['category']] : []);
        $approved
            ? Notices::send((int) $r['user_id'], 'photo_approved', "Ta photo de {$r['pin_name']} est en ligne 🎉", 'Merci pour ta contribution !', $target, ['image_url' => $r['url']])
            : Notices::send((int) $r['user_id'], 'photo_rejected', "Ta photo de {$r['pin_name']} n'a pas été retenue",
                ($reason = self::reasonText($r['refusal_reason'] ?? null, $r['refusal_note'] ?? null)) !== null
                    ? 'Motif : ' . rtrim($reason, '. ') . '. Tu peux en proposer une autre.' : 'Tu peux en proposer une autre.',
                $target + ['reason' => $reason, 'reasonCode' => $r['refusal_reason'] ?? null], ['image_url' => $r['url']]);
    }

    public static function reasonText(?string $reason, ?string $note): ?string
    {
        if ($reason === null) {
            return null;
        }

        return $reason === 'autre' && trim((string) $note) !== '' ? trim((string) $note) : (self::REASONS[$reason] ?? 'Photo non retenue');
    }

    /** Photos d'un commerçant (ou de l'équipe si $businessId null), par uid */
    public static function find(array $uids, ?int $businessId): array
    {
        $uids = array_values(array_unique(array_filter($uids, 'is_string')));
        if ($uids === []) {
            return [];
        }
        $in = implode(',', array_map(static fn ($i) => ':i' . $i, array_keys($uids)));

        return Database::fetchAll(self::SELECT . " WHERE v.uid IN ({$in}) AND " . ($businessId === null ? 'v.business_id IS NULL' : 'v.business_id = :b'),
            array_combine(array_map(static fn ($i) => 'i' . $i, array_keys($uids)), $uids) + ($businessId === null ? [] : ['b' => $businessId]));
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC')) : null;
    }

    public static function maxPerDay(): int
    {
        return (int) Config::get('places.visitor_photos_per_day', 20);
    }
}
