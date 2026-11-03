<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Content\Import\Geocoder;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Événements de l'établissement (demande 6).
 *
 *   GET /pro/events ; POST /pro/events ; PATCH|DELETE /pro/events/{id} ; POST /pro/events/{id}/submit | withdraw
 *
 * brouillon → en_revue (events.status = pending) → validation admin → publie (published) ; refus → refuse + motif.
 * Un événement publié modifié repasse en vérification : la modification attend dans events.pro_pending,
 * l'ancienne version reste dans l'app.
 */
final class EventController
{
    private const KINDS = ['soiree', 'concert', 'spectacle', 'expo', 'famille', 'sport', 'marche', 'festival'];
    private const CATEGORY = ['soiree' => 'soiree', 'concert' => 'musique', 'spectacle' => 'culture', 'expo' => 'culture', 'famille' => 'famille',
                              'sport' => 'sport', 'marche' => 'marche', 'festival' => 'festival'];

    public function index(Request $request): Response
    {
        $rows = Database::fetchAll('SELECT * FROM events WHERE business_id = :b AND deleted_at IS NULL ORDER BY starts_at DESC, id DESC', ['b' => Pro::business($request)['id']]);

        return Response::success(array_map([self::class, 'present'], $rows));
    }

    public function create(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $business = Pro::business($request);
        $place = Pro::place($request);
        $d = self::validate($request->json(), self::blank($place), $business);
        $now = gmdate('Y-m-d H:i:s');
        $slug = self::slug($d['title'] ?: 'evenement', $d['start']);
        $id = Database::insert(
            "INSERT INTO events (uid, slug, category_id, title, starts_at, ends_at, venue_name, place_id, business_id, pro_status, status, created_at, updated_at)
             VALUES (:u, :s, :c, :t, :st, :en, :v, :p, :b, 'brouillon', 'draft', :cr, :up)",
            ['u' => Crypto::uuid4(), 's' => $slug, 'c' => self::categoryId($d['kind']), 't' => $d['title'] ?: 'Événement', 'st' => Pro::toUtc($d['start'], 'start') ?? $now,
             'en' => Pro::toUtc($d['end'] ?? $d['start'], 'end') ?? $now, 'v' => $d['venue'] ?: $business['trade_name'], 'p' => $place['id'], 'b' => $business['id'], 'cr' => $now, 'up' => $now],
        );
        self::write($id, $d);

        return Response::success(self::present(self::row($id)), 201);
    }

    public function update(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $e = $this->find($request);
        $d = self::validate($request->json(), self::data($e), Pro::business($request));
        if ($e['pro_status'] === 'publie') {
            // L'ancienne version reste en ligne jusqu'à la validation
            Database::execute("UPDATE events SET pro_pending = :p, pro_status = 'en_revue', updated_at = :d WHERE id = :id",
                ['p' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $e['id']]);
        } elseif ($e['pro_status'] === 'en_revue' && $e['status'] === 'published') {
            Database::execute('UPDATE events SET pro_pending = :p, updated_at = :d WHERE id = :id',
                ['p' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $e['id']]);
        } else {
            self::write((int) $e['id'], $d);
            if ($e['pro_status'] === 'refuse') {
                Database::execute("UPDATE events SET pro_status = 'brouillon', status = 'draft', refusal_reason = NULL WHERE id = :id", ['id' => $e['id']]);
            }
        }

        return Response::success(self::present(self::row((int) $e['id'])));
    }

    public function submit(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        Verification::require($request);
        $e = $this->find($request);
        if (in_array($e['pro_status'], ['brouillon', 'refuse'], true)) {
            $d = self::data($e);
            $errors = [];
            if (mb_strlen($d['title']) < 3) {
                $errors['title'] = 'Indique un titre.';
            }
            if (trim($d['summary']) === '') {
                $errors['summary'] = 'Ajoute un résumé.';
            }
            if ($d['start'] === null) {
                $errors['start'] = 'Indique la date de début.';
            } elseif (strtotime($d['end'] ?? $d['start']) < time()) {
                $errors['start'] = 'L\'événement est déjà passé.';
            }
            if ($d['latitude'] === null) {
                $errors['address'] = 'Indique une adresse trouvable sur la carte.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            Database::execute("UPDATE events SET pro_status = 'en_revue', status = 'pending', refusal_reason = NULL, updated_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $e['id']]);
        }

        return Response::success(self::present(self::row((int) $e['id'])));
    }

    public function withdraw(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $e = $this->find($request);
        $d = $e['pro_pending'] !== null ? json_decode($e['pro_pending'], true) : null;
        Database::execute("UPDATE events SET pro_status = 'brouillon', status = 'draft', pro_pending = NULL, updated_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $e['id']]);
        if (is_array($d)) {
            self::write((int) $e['id'], $d);
        }
        \App\Admin\CacheInvalidator::afterWrite('events', $e, null);

        return Response::success(self::present(self::row((int) $e['id'])));
    }

    public function destroy(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $e = $this->find($request);
        Database::execute("UPDATE events SET deleted_at = :d, status = 'archived' WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $e['id']]);
        \App\Admin\CacheInvalidator::afterWrite('events', $e, null);

        return Response::noContent();
    }

    /** Validation par l'équipe : l'événement (ou sa modification) passe en ligne. */
    public static function approve(int $id): void
    {
        $e = self::row($id);
        if ($e['pro_pending'] !== null) {
            self::write($id, json_decode($e['pro_pending'], true) ?: self::data($e));
        }
        Database::execute("UPDATE events SET pro_status = 'publie', status = 'published', pro_pending = NULL, refusal_reason = NULL WHERE id = :id", ['id' => $id]);
        if ($e['business_id'] !== null) {
            Pro::notify((int) $e['business_id'], 'moderation', 'Événement publié : ' . $e['title'], 'Ton événement est visible dans l\'app My Clermont.', '/evenements');
        }
    }

    /** Refus : un nouvel événement passe « refuse » ; une modification refusée laisse la version publiée en place. */
    public static function reject(int $id, string $reason): void
    {
        $e = self::row($id);
        if ($e['status'] === 'published' && $e['pro_pending'] !== null) {
            Database::execute("UPDATE events SET pro_status = 'publie', pro_pending = NULL, refusal_reason = :r WHERE id = :id", ['r' => $reason ?: null, 'id' => $id]);
        } else {
            Database::execute("UPDATE events SET pro_status = 'refuse', status = 'rejected', refusal_reason = :r WHERE id = :id", ['r' => $reason ?: null, 'id' => $id]);
        }
        if ($e['business_id'] !== null) {
            Pro::notify((int) $e['business_id'], 'moderation', 'Événement refusé : ' . $e['title'], $reason ?: 'Il ne respecte pas les règles de publication.', '/evenements');
        }
    }

    public static function present(array $e): array
    {
        $d = $e['pro_pending'] !== null ? (json_decode($e['pro_pending'], true) ?: self::data($e)) : self::data($e);
        unset($d['flash']);
        $out = ['id' => $e['slug']] + $d + ['status' => $e['pro_status'] ?? 'brouillon'];
        if ($e['refusal_reason'] !== null) {
            $out['refusal_reason'] = $e['refusal_reason'];
        }

        return $out + ['flash' => (bool) $e['is_flash'], 'interested' => (int) $e['participants_count'], 'created_at' => Pro::iso($e['created_at'])];
    }

    /** Champs ProEvent (sans le suivi), depuis events.pro ou les colonnes. */
    private static function data(array $e): array
    {
        $p = json_decode((string) ($e['pro'] ?? ''), true);
        if (is_array($p)) {
            return array_replace(self::blank(null), $p, ['flash' => (bool) $e['is_flash']]);
        }

        return array_replace(self::blank(null), [
            'title' => $e['title'], 'kind' => $e['kind'] ?? 'soiree', 'venue' => $e['venue_name'], 'address' => (string) $e['address'],
            'latitude' => $e['latitude'] !== null ? (float) $e['latitude'] : null, 'longitude' => $e['longitude'] !== null ? (float) $e['longitude'] : null,
            'start' => Pro::paris($e['starts_at']), 'end' => Pro::paris($e['ends_at']), 'schedule' => $e['schedule_label'], 'price' => $e['price_label'],
            'age_limit' => $e['age_limit'], 'url' => $e['link_url'], 'all_day' => (bool) $e['all_day'], 'summary' => (string) $e['summary'], 'image_url' => $e['image_url'],
            'flash' => (bool) $e['is_flash'],
        ]);
    }

    private static function blank(?array $place): array
    {
        $p = $place ? PlaceController::data($place) : null;

        return ['title' => '', 'kind' => 'soiree', 'venue' => $p['name'] ?? '', 'address' => $p ? trim($p['address'] . ', ' . $p['postal_code'] . ' ' . $p['city'], ', ') : '',
                'latitude' => $p['latitude'] ?? null, 'longitude' => $p['longitude'] ?? null, 'start' => null, 'end' => null, 'schedule' => null, 'price' => null,
                'age_limit' => null, 'url' => null, 'all_day' => false, 'summary' => '', 'image_url' => null, 'flash' => false];
    }

    private static function validate(array $j, array $d, array $business): array
    {
        $errors = [];
        foreach (['title' => 70, 'venue' => 160, 'address' => 255, 'summary' => 280] as $k => $max) {
            $v = Pro::str($j, $k, $max, $errors);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        foreach (['schedule' => 160, 'price' => 80, 'age_limit' => 60] as $k => $max) {
            if (array_key_exists($k, $j)) {
                $v = Pro::str($j, $k, $max, $errors);
                $d[$k] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('kind', $j)) {
            in_array($j['kind'], self::KINDS, true) ? $d['kind'] = $j['kind'] : $errors['kind'] = 'Type d\'événement inconnu.';
        }
        foreach (['url', 'image_url'] as $k) {
            if (array_key_exists($k, $j)) {
                Pro::url($j[$k]) ? $d[$k] = $j[$k] ?: null : $errors[$k] = 'Lien invalide : il doit commencer par http:// ou https://.';
            }
        }
        foreach (['start', 'end'] as $k) {
            if (array_key_exists($k, $j)) {
                $d[$k] = Pro::paris(Pro::toUtc($j[$k], $k));
            }
        }
        if ($d['start'] !== null && $d['end'] !== null && strtotime($d['end']) < strtotime($d['start'])) {
            $errors['end'] = 'La fin doit suivre le début.';
        }
        if (array_key_exists('all_day', $j)) {
            $d['all_day'] = $j['all_day'] === true;
        }
        if (array_key_exists('flash', $j)) {
            if ($j['flash'] === true && !Pro::limits($business)['flash']) {
                throw Pro::planLimit('flash', 'Les Événements Flash sont réservés aux Partenaires.');
            }
            $d['flash'] = $j['flash'] === true;
        }
        $coords = array_key_exists('latitude', $j) && is_numeric($j['latitude']) && is_numeric($j['longitude'] ?? null);
        if ($coords) {
            [$d['latitude'], $d['longitude']] = [round((float) $j['latitude'], 6), round((float) $j['longitude'], 6)];
        } elseif (array_key_exists('address', $j)) {
            [$d['latitude'], $d['longitude']] = $d['address'] !== '' ? ((new Geocoder())->address($d['address']) ?? [null, null]) : [null, null];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $d;
    }

    /** Recopie l'événement pro dans les colonnes lues par l'app (GET /events, agenda). */
    private static function write(int $id, array $d): void
    {
        $start = Pro::toUtc($d['start'], 'start');
        $end = Pro::toUtc($d['end'], 'end') ?? ($start ? gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + ($d['all_day'] ? 86399 : 7200)) : null);
        $flash = (bool) ($d['flash'] ?? false);
        unset($d['flash']);
        Database::execute(
            'UPDATE events SET title = :t, kind = :k, category_id = :c, venue_name = :v, address = :a, latitude = :lat, longitude = :lon, starts_at = COALESCE(:s, starts_at),
                    ends_at = COALESCE(:e, ends_at), schedule_label = :sch, price_label = :pr, price_min_cents = :pmin, age_limit = :age, link_url = :url, all_day = :ad,
                    summary = :sum, image_url = :img, is_flash = :fl, pro = :pro, updated_at = :up WHERE id = :id',
            ['t' => $d['title'] ?: 'Événement', 'k' => $d['kind'], 'c' => self::categoryId($d['kind']), 'v' => $d['venue'] ?: 'À préciser', 'a' => $d['address'] ?: null,
             'lat' => $d['latitude'], 'lon' => $d['longitude'], 's' => $start, 'e' => $end, 'sch' => $d['schedule'], 'pr' => $d['price'],
             'pmin' => $d['price'] === null ? null : (preg_match('/gratuit/i', $d['price']) ? 0 : Pro::cents($d['price'])), 'age' => $d['age_limit'], 'url' => $d['url'],
             'ad' => (int) $d['all_day'], 'sum' => $d['summary'] ?: null, 'img' => $d['image_url'], 'fl' => (int) $flash,
             'pro' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'up' => gmdate('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    private static function slug(string $title, ?string $start): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($title, \Normalizer::FORM_D) ?: $title))), '-');
        $base = mb_substr($base ?: 'evenement', 0, 90) . '-' . ($start ? substr($start, 0, 10) : gmdate('Y-m-d'));
        $slug = $base;
        for ($i = 2; Database::fetch('SELECT 1 FROM events WHERE slug = :s', ['s' => $slug]); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private static function categoryId(string $kind): int
    {
        static $ids = null;
        $ids ??= array_column(Database::fetchAll('SELECT id, slug FROM event_categories'), 'id', 'slug');

        return (int) ($ids[self::CATEGORY[$kind] ?? 'soiree'] ?? reset($ids));
    }

    private static function row(int $id): array
    {
        return Database::fetch('SELECT * FROM events WHERE id = :id', ['id' => $id]);
    }

    private function find(Request $request): array
    {
        return Database::fetch('SELECT * FROM events WHERE slug = :s AND business_id = :b AND deleted_at IS NULL', ['s' => (string) $request->param('id'), 'b' => Pro::business($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Événement introuvable.');
    }
}
