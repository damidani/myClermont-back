<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Content\Import\Geocoder;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Places\MapController;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * Sorties selon la spec v1 (docs/SPEC_V1.txt › Sorties et événements). Identifiant = slug « titre-date ».
 *
 *   GET    /events                from?, to?, kind?, bbox?, limit?, cursor?   → { items: Event[], nextCursor }
 *   GET    /events/{id}           Event
 *   GET    /evenements.json       Event[] (tableau nu, événements non terminés — compatibilité build 16, non signé)
 *   POST   /events/submissions    compte                                       → 202 { id, status: "pending" }
 *   POST   /events                partner, admin                                → 201 Event
 *   PATCH  /events/{id}           auteur, admin                                 → Event
 *   DELETE /events/{id}           auteur, admin                                 → 204
 */
final class SpecEventController
{
    public const KINDS = ['soiree', 'concert', 'spectacle', 'expo', 'famille', 'sport', 'marche', 'festival'];

    private const COLUMNS = 'e.id, e.uid, e.slug, e.title, e.kind, e.venue_name, e.address, e.city, e.latitude, e.longitude, e.starts_at, e.ends_at,
                             e.schedule_label, e.price_label, e.age_limit, e.link_url, e.ticket_url, e.external_url, e.all_day, e.summary, e.description,
                             e.image_url, e.status, e.author_user_id, e.deleted_at, p.uid AS place_uid';
    private const FROM = 'events e LEFT JOIN places p ON p.id = e.place_id';

    public function index(Request $request): Response
    {
        $q = $request->query();
        $limit = min(50, max(1, (int) ($q['limit'] ?? 20)));
        $from = isset($q['from']) ? self::time((string) $q['from'], 'from') : time();
        $where = ["e.status = 'published'", 'e.deleted_at IS NULL', 'e.ends_at >= :from'];
        $params = ['from' => gmdate('Y-m-d H:i:s', $from), 'now' => gmdate('Y-m-d H:i:s')];

        if (isset($q['to'])) {
            $where[] = 'e.starts_at <= :to';
            $params['to'] = gmdate('Y-m-d H:i:s', self::time((string) $q['to'], 'to'));
        }
        if (isset($q['kind'])) {
            if (!in_array($q['kind'], self::KINDS, true)) {
                throw new ValidationException(['kind' => 'Type d\'événement inconnu.']);
            }
            $where[] = 'e.kind = :kind';
            $params['kind'] = $q['kind'];
        }
        if (isset($q['bbox'])) {
            $b = array_map('floatval', explode(',', (string) $q['bbox']));
            if (count($b) !== 4) {
                throw new ValidationException(['bbox' => 'Format attendu : minLon,minLat,maxLon,maxLat.']);
            }
            $where[] = 'e.latitude BETWEEN :b1 AND :b3 AND e.longitude BETWEEN :b0 AND :b2';
            $params += ['b0' => $b[0], 'b1' => $b[1], 'b2' => $b[2], 'b3' => $b[3]];
        }
        $offset = isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0;

        // En cours d'abord, puis par date de début
        $rows = Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY (e.starts_at <= :now) DESC, e.starts_at, e.id LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            $params,
        );
        $next = count($rows) > $limit ? (string) ($offset + $limit) : null;

        return Response::success(array_map([self::class, 'present'], array_slice($rows, 0, $limit)), 200, ['next_cursor' => $next])
            ->withHeader('Cache-Control', 'private, max-age=60');
    }

    public function show(Request $request): Response
    {
        return Response::success(self::present($this->find((string) $request->param('id'), true)));
    }

    public function legacyFeed(Request $request): Response
    {
        $rows = Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE e.status = 'published' AND e.deleted_at IS NULL AND e.ends_at >= UTC_TIMESTAMP()
             ORDER BY e.starts_at, e.id LIMIT 1000",
        );

        return Response::json(array_map(static fn (array $e) => \App\Core\Contract::camel(self::present($e)), $rows))
            ->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }

    /** Proposition d'un utilisateur : en attente de validation dans le back-office. */
    public function submit(Request $request): Response
    {
        $id = $this->create($request, 'pending');

        return Response::json(['id' => $id, 'status' => 'pending'], 202);
    }

    /** Publication directe par un partenaire (organisateur) ou un administrateur. */
    public function store(Request $request): Response
    {
        $slug = $this->create($request, 'published');

        return Response::success(self::present($this->find($slug, false)), 201);
    }

    public function update(Request $request): Response
    {
        $event = $this->find((string) $request->param('id'), false);
        $this->assertCanEdit($request, $event);
        $data = $this->validate($request->json(), true);
        $fields = $this->columns($data, $event);
        if ($fields !== []) {
            $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($fields)));
            Database::execute("UPDATE events SET {$sets}, updated_at = :u WHERE id = :id", $fields + ['u' => gmdate('Y-m-d H:i:s'), 'id' => $event['id']]);
        }

        return Response::success(self::present($this->find((string) $event['slug'], false)));
    }

    public function destroy(Request $request): Response
    {
        $event = $this->find((string) $request->param('id'), false);
        $this->assertCanEdit($request, $event);
        Database::execute('UPDATE events SET deleted_at = :d, updated_at = :d2 WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'd2' => gmdate('Y-m-d H:i:s'), 'id' => $event['id']]);

        return Response::noContent();
    }

    /** Event : les champs de ClermontEvent dans l'app, plus imageUrl et placeId. Dates à l'heure de Paris, sans millisecondes. */
    public static function present(array $e): array
    {
        return [
            'id'         => $e['slug'],
            'title'      => $e['title'],
            'kind'       => $e['kind'] ?? 'soiree',
            'venue'      => $e['venue_name'],
            'address'    => $e['address'] !== null ? $e['address'] : null,
            'latitude'   => $e['latitude'] === null ? null : (float) $e['latitude'],
            'longitude'  => $e['longitude'] === null ? null : (float) $e['longitude'],
            'start'      => self::iso($e['starts_at']),
            'end'        => $e['ends_at'] === null ? null : self::iso($e['ends_at']),
            'schedule'   => $e['schedule_label'],
            'price'      => $e['price_label'],
            'age_limit'  => $e['age_limit'],
            'url'        => $e['link_url'] ?? $e['ticket_url'] ?? $e['external_url'],
            'all_day'    => (bool) $e['all_day'],
            'summary'    => $e['summary'] ?? ($e['description'] !== null ? mb_strimwidth((string) $e['description'], 0, 280, '…') : null),
            'image_url'  => $e['image_url'],
            'place_id'   => $e['place_uid'],
        ];
    }

    /** « 2026-10-31T19:30:00+01:00 » (heure de Paris, jamais de millisecondes : .iso8601 de Swift les refuse). */
    public static function iso(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i:sP');
    }

    /** Slug « titre-date » en minuscules sans accents, unique, jamais modifié ensuite. */
    public static function slug(string $title, int $start): string
    {
        $base = substr(EventImportTarget::slugify($title), 0, 90) . '-' . (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $slug = $base;
        for ($i = 2; Database::fetch('SELECT 1 FROM events WHERE slug = :s', ['s' => $slug]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function create(Request $request, string $status): string
    {
        $data = $this->validate($request->json(), false);
        $fields = $this->columns($data, null);
        $slug = self::slug($data['title'], (int) $data['_start']);
        $now = gmdate('Y-m-d H:i:s');
        $category = Database::fetch('SELECT id FROM event_categories WHERE kind = :k ORDER BY sort_order LIMIT 1', ['k' => $data['kind']])
            ?? Database::fetch('SELECT id FROM event_categories ORDER BY sort_order LIMIT 1');

        Database::insert(
            'INSERT INTO events (uid, slug, category_id, status, author_user_id, created_at, updated_at, ' . implode(', ', array_keys($fields)) . ')
             VALUES (:uid, :slug, :cat, :status, :author, :c, :u, ' . implode(', ', array_map(static fn ($k) => ':' . $k, array_keys($fields))) . ')',
            $fields + ['uid' => Crypto::uuid4(), 'slug' => $slug, 'cat' => $category['id'], 'status' => $status,
                       'author' => $request->attributes['user_id'], 'c' => $now, 'u' => $now],
        );

        return $slug;
    }

    /** EventInput (création complète ou modification partielle). */
    private function validate(array $input, bool $partial): array
    {
        $req = $partial ? '' : 'required|';
        $data = Validator::validate($input, [
            'title'     => $req . 'string|min:3|max:160',
            'kind'      => $req . 'in:' . implode(',', self::KINDS),
            'venue'     => $req . 'string|min:2|max:160',
            'address'   => $req . 'string|min:3|max:255',
            'start'     => $req . 'string|max:40',
            'end'       => 'nullable|string|max:40',
            'schedule'  => 'nullable|string|max:160',
            'price'     => 'nullable|string|max:80',
            'age_limit' => 'nullable|string|max:60',
            'url'       => 'nullable|string|max:500',
            'all_day'   => 'boolean',
            'summary'   => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:500',
            'place_id'  => 'nullable|uuid',
        ]);
        foreach (['url', 'image_url'] as $k) {
            if (!empty($data[$k]) && !preg_match('#^https://#', $data[$k])) {
                throw new ValidationException([$k => 'Le lien doit commencer par https://.']);
            }
        }
        if (isset($data['start'])) {
            $data['_start'] = self::time($data['start'], 'start');
        }
        if (!empty($data['end'])) {
            $data['_end'] = self::time($data['end'], 'end');
            if (isset($data['_start']) && $data['_end'] < $data['_start']) {
                throw new ValidationException(['end' => 'La fin doit être après le début.']);
            }
        }

        return $data;
    }

    /** Champs validés → colonnes ; l'adresse est géocodée et doit être dans la métropole. */
    private function columns(array $d, ?array $existing): array
    {
        $map = ['title' => 'title', 'kind' => 'kind', 'venue' => 'venue_name', 'address' => 'address', 'schedule' => 'schedule_label',
                'price' => 'price_label', 'age_limit' => 'age_limit', 'url' => 'link_url', 'summary' => 'summary', 'image_url' => 'image_url'];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $d)) {
                $out[$col] = $d[$in];
            }
        }
        if (isset($d['all_day'])) {
            $out['all_day'] = $d['all_day'] ? 1 : 0;
        }
        if (isset($d['_start'])) {
            $out['starts_at'] = gmdate('Y-m-d H:i:s', $d['_start']);
        }
        if (isset($d['_start']) || array_key_exists('end', $d)) {
            $start = $d['_start'] ?? strtotime($existing['starts_at'] . ' UTC');
            $out['ends_at'] = gmdate('Y-m-d H:i:s', $d['_end'] ?? ($existing !== null && !array_key_exists('end', $d) ? strtotime($existing['ends_at'] . ' UTC') : $start + 2 * 3600));
        }
        if (array_key_exists('place_id', $d)) {
            $out['place_id'] = $d['place_id'] === null ? null
                : (Database::fetch("SELECT id FROM places WHERE uid = :u AND status = 'published'", ['u' => $d['place_id']])['id']
                    ?? throw new ValidationException(['place_id' => 'Lieu inconnu.']));
        }
        if (isset($d['address'])) {
            $pos = (new Geocoder())->address($d['address']);
            [$minLon, $minLat, $maxLon, $maxLat] = MapController::ZONE;
            if ($pos === null) {
                throw new ValidationException(['address' => 'Adresse introuvable, vérifie-la.']);
            }
            if ($pos[0] < $minLat || $pos[0] > $maxLat || $pos[1] < $minLon || $pos[1] > $maxLon) {
                throw new ValidationException(['address' => 'Cette adresse est en dehors de la métropole clermontoise.']);
            }
            [$out['latitude'], $out['longitude']] = $pos;
        }

        return $out;
    }

    private function assertCanEdit(Request $request, array $event): void
    {
        $role = $request->attributes['role'] ?? 'user';
        if (!in_array($role, ['admin', 'moderator'], true) && (int) $event['author_user_id'] !== (int) $request->attributes['user_id']) {
            throw HttpException::forbidden('forbidden', 'Tu ne peux modifier que tes propres événements.');
        }
    }

    private function find(string $id, bool $publicOnly): array
    {
        $e = preg_match('/^[a-z0-9-]{1,120}$/', $id) ? Database::fetch(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE e.slug = :s AND e.deleted_at IS NULL'
            . ($publicOnly ? " AND e.status IN ('published', 'archived', 'cancelled')" : ''),
            ['s' => $id],
        ) : null;

        return $e ?? throw new HttpException(404, 'event_not_found', 'Cet événement n\'existe pas ou a été retiré.');
    }

    private static function time(string $value, string $field): int
    {
        // ISO 8601 avec fuseau ; sans fuseau = heure de Paris
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone((string) Config::get('events.timezone', 'Europe/Paris'))))->getTimestamp();
        } catch (\Exception) {
            throw new ValidationException([$field => 'Date invalide (ISO 8601 attendu, ex. 2026-10-31T19:30:00+01:00).']);
        }
    }
}
