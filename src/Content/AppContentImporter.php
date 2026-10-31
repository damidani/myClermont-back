<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Import\Geocoder;
use App\Content\Import\HttpClient;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import du contenu réel de l'app iOS (database/content/*.json, envoyé sur le canal agents) : lieux, fiches, événements.
 * Upsert par l'identifiant de l'app : rejouable sans doublon. Les positions approximatives sont complétées par le
 * géocodeur national (IGN : points d'intérêt, adresses), jamais déplacées de plus de 2,5 km.
 */
final class AppContentImporter
{
    private const SOURCE_ID = 1;   // myClermont (contenu édité)
    private const MAX_SHIFT_M = 2500;

    /** catégorie de l'app → slug de place_categories */
    private const CATEGORIES = ['incontournable' => 'monuments', 'spot' => 'spots', 'culture' => 'culture', 'sport' => 'sport', 'nature' => 'parcs'];

    /** @var list<string> */
    public array $log = [];

    public function places(array $items, bool $geocode = true): int
    {
        $cats = array_column(Database::fetchAll('SELECT id, slug FROM place_categories'), 'id', 'slug');
        $n = 0;
        foreach ($items as $p) {
            $lat = (float) $p['latitude'];
            $lon = (float) $p['longitude'];
            $d = $p['details'] ?? [];
            $subtitle = (string) ($p['subtitle'] ?? '');
            $address = $postal = null;
            $city = 'Clermont-Ferrand';
            if ($geocode && ($d['positionApprox'] ?? false)) {
                $confirmed = false;
                if (($poi = $this->poi((string) ($d['searchQuery'] ?? $p['name']), (string) $p['name'], $lat, $lon)) !== null) {
                    [$lat, $lon, $poiCity] = $poi;
                    $city = $poiCity ?: $city;
                    $confirmed = true;
                    $this->log[] = "lieu {$p['id']} : position confirmée par l'IGN";
                } else {
                    $this->log[] = "lieu {$p['id']} : position de l'app gardée (non trouvé à l'IGN), à vérifier";
                }
                if (($addr = $this->reverse($lat, $lon)) !== null) {
                    // Adresse de rue seulement si la position est confirmée ; sinon la commune seule (fiable même approximative)
                    [$street, $postal, $city] = $addr;
                    $address = $confirmed ? $street : null;
                    if ($subtitle === '') {
                        $subtitle = $confirmed ? $street . ', ' . $city : $city;
                    }
                }
                // Lieu sans adresse postale (parc de loisirs, sommet) : la commune
                if ($subtitle === '') {
                    $subtitle = $city;
                }
            }
            $nature = isset($d['kinds']) ? json_encode(['kinds' => $d['kinds']] + array_intersect_key($d, array_flip(['highlight', 'propice', 'warning'])), JSON_UNESCAPED_UNICODE) : null;
            $row = [
                'app_id' => $p['id'], 'name' => mb_substr($p['name'], 0, 120), 'app_category' => $p['category'],
                'category_id' => $cats[self::CATEGORIES[$p['category']] ?? 'spots'] ?? reset($cats), 'subtitle' => mb_substr($subtitle, 0, 160),
                'demo_name' => $p['demoName'] ?? null, 'description' => $d['summary'] ?? null, 'latitude' => round($lat, 6), 'longitude' => round($lon, 6),
                'address' => $address, 'postal_code' => $postal, 'city' => $city, 'phone' => $p['phone'] ?? null, 'website_url' => $p['website'] ?? null,
                'nature' => $nature, 'details' => $d === [] ? null : json_encode($d, JSON_UNESCAPED_UNICODE),
                'is_must_see' => $p['category'] === 'incontournable' ? 1 : 0, 'is_partner' => 0,
            ];
            $this->upsertPlace($row);
            $n++;
        }

        return $n;
    }

    /** Fiches PlaceGuide des incontournables : gardées entières (guide) et reportées dans les colonnes de la fiche. */
    public function guides(array $items): int
    {
        $n = 0;
        foreach ($items as $g) {
            $hours = $g['hours'] ?? [];
            $hoursLabel = match ($hours['type'] ?? null) {
                'always', 'info' => $hours['text'] ?? null,
                'daily' => 'Tous les jours, ' . str_replace(':', ' h ', $hours['open']) . ' – ' . str_replace(':', ' h ', $hours['close']),
                default => null,
            };
            $info = static fn (string $title) => current(array_filter($g['infos'] ?? [], static fn ($i) => $i['title'] === $title))['value'] ?? null;
            $n += Database::execute(
                'UPDATE places SET guide = :g, tagline = :t, description = :i, facts = :f, highlights = :h, timeline = :tl, opening_hours = :o,
                    hours_label = :hl, tip = :tip, sources_label = :s, access_label = COALESCE(:acc, access_label), price_label = COALESCE(:price, price_label),
                    address = COALESCE(:addr, address), updated_at = :now WHERE app_id = :id',
                ['g' => json_encode($g, JSON_UNESCAPED_UNICODE), 't' => mb_substr((string) $g['tagline'], 0, 160), 'i' => $g['intro'],
                 'f' => json_encode($g['facts'] ?? [], JSON_UNESCAPED_UNICODE), 'h' => json_encode($g['highlights'] ?? [], JSON_UNESCAPED_UNICODE),
                 'tl' => json_encode($g['timeline'] ?? [], JSON_UNESCAPED_UNICODE), 'o' => json_encode($hours, JSON_UNESCAPED_UNICODE),
                 'hl' => $hoursLabel === null ? null : mb_substr($hoursLabel, 0, 120), 'tip' => mb_substr((string) ($g['tip'] ?? ''), 0, 400) ?: null,
                 's' => mb_substr((string) ($g['sources'] ?? ''), 0, 300) ?: null, 'acc' => $info('Accès') === null ? null : mb_substr($info('Accès'), 0, 160),
                 'price' => ($info('Tarif') ?? null) === null ? null : mb_substr($info('Tarif'), 0, 80),
                 'addr' => ($info('Adresse') ?? null) === null ? null : mb_substr($info('Adresse'), 0, 200), 'now' => gmdate('Y-m-d H:i:s'), 'id' => $g['placeId']],
            );
        }

        return $n;
    }

    /** Événements au format GET /events (id = slug de l'app, dates à l'heure de Paris). */
    public function events(array $items, bool $geocode = true): int
    {
        $cats = array_column(Database::fetchAll('SELECT id, kind FROM event_categories WHERE kind IS NOT NULL ORDER BY sort_order'), 'id', 'kind');
        $anyCat = (int) Database::fetch('SELECT id FROM event_categories ORDER BY sort_order LIMIT 1')['id'];
        $n = 0;
        foreach ($items as $e) {
            $start = new \DateTimeImmutable($e['start']);
            // Sans fin : 3 h (l'app compte pareil), ou la journée entière
            $end = $e['end'] !== null ? new \DateTimeImmutable($e['end']) : ($e['allDay'] ? $start->setTime(23, 59, 59) : $start->modify('+3 hours'));
            $place = isset($e['placeId']) ? Database::fetch('SELECT id, latitude, longitude, city FROM places WHERE app_id = :a', ['a' => $e['placeId']]) : null;
            $lat = $e['latitude'];
            $lon = $e['longitude'];
            if (($lat === null || $lon === null) && $geocode && ($pos = (new Geocoder())->address((string) $e['address'])) !== null) {
                [$lat, $lon] = $pos;
            }
            if (($lat === null || $lon === null) && $place !== null) {
                [$lat, $lon] = [(float) $place['latitude'], (float) $place['longitude']];
            }
            if ($lat === null) {
                $this->log[] = "événement {$e['id']} : adresse non géocodée";
            }
            preg_match('/\b(\d{5})\s+([^,]+)$/u', (string) $e['address'], $m);
            $row = [
                'slug' => $e['id'], 'title' => mb_substr($e['title'], 0, 160), 'kind' => $e['kind'], 'category_id' => $cats[$e['kind']] ?? $anyCat,
                'venue_name' => mb_substr($e['venue'], 0, 160), 'address' => $e['address'], 'city' => isset($m[2]) ? trim($m[2]) : ($place['city'] ?? 'Clermont-Ferrand'),
                'latitude' => $lat, 'longitude' => $lon, 'starts_at' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'ends_at' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'all_day' => $e['allDay'] ? 1 : 0,
                'schedule_label' => $e['schedule'], 'price_label' => $e['price'], 'age_limit' => $e['ageLimit'], 'link_url' => $e['url'],
                'summary' => $e['summary'], 'image_url' => $e['imageUrl'], 'place_id' => $place['id'] ?? null,
            ];
            $this->upsertEvent($row);
            $n++;
        }

        return $n;
    }

    private function upsertPlace(array $row): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = Database::fetch('SELECT id FROM places WHERE app_id = :a', ['a' => $row['app_id']]);
        if ($existing) {
            $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($row)));
            Database::execute("UPDATE places SET {$sets}, status = 'published', deleted_at = NULL, updated_at = :now WHERE id = :id", $row + ['now' => $now, 'id' => $existing['id']]);
            return;
        }
        $row += ['uid' => Crypto::uuid4(), 'source_id' => self::SOURCE_ID, 'external_id' => 'app:' . self::ascii($row['app_id']), 'status' => 'published', 'created_at' => $now, 'updated_at' => $now];
        Database::insert('INSERT INTO places (' . implode(', ', array_keys($row)) . ') VALUES (:' . implode(', :', array_keys($row)) . ')', $row);
    }

    private function upsertEvent(array $row): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = Database::fetch('SELECT id FROM events WHERE slug = :s', ['s' => $row['slug']]);
        if ($existing) {
            $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($row)));
            Database::execute("UPDATE events SET {$sets}, status = 'published', deleted_at = NULL, updated_at = :now WHERE id = :id", $row + ['now' => $now, 'id' => $existing['id']]);
            return;
        }
        $row += ['uid' => Crypto::uuid4(), 'source_id' => self::SOURCE_ID, 'status' => 'published', 'is_featured' => 0, 'participants_count' => 0, 'created_at' => $now, 'updated_at' => $now];
        Database::insert('INSERT INTO events (' . implode(', ', array_keys($row)) . ') VALUES (:' . implode(', :', array_keys($row)) . ')', $row);
    }

    /** Point d'intérêt IGN : accepté seulement si son nom ressemble au nom du lieu et s'il est à moins de 2,5 km de la position de l'app. */
    private function poi(string $query, string $name, float $lat, float $lon): ?array
    {
        try {
            $data = (new HttpClient(timeout: 8, retries: 1, delayMs: 150))->getJson((string) Config::get('imports.geocoder_url', 'https://data.geopf.fr/geocodage/search')
                . '?' . http_build_query(['q' => $query, 'index' => 'poi', 'limit' => 1, 'lat' => $lat, 'lon' => $lon]));
        } catch (\Throwable) {
            return null;
        }
        $f = $data['features'][0] ?? null;
        if ($f === null || ($f['properties']['score'] ?? 0) < 0.5) {
            return null;
        }
        $names = array_merge((array) ($f['properties']['name'] ?? []), [(string) ($f['properties']['toponym'] ?? '')]);
        if (!array_filter($names, static fn ($n) => self::sameName((string) $n, $name))) {
            return null;
        }
        [$plon, $plat] = $f['geometry']['coordinates'];

        return self::meters($lat, $lon, (float) $plat, (float) $plon) <= self::MAX_SHIFT_M
            ? [round((float) $plat, 6), round((float) $plon, 6), (string) (((array) ($f['properties']['city'] ?? []))[0] ?? '')]
            : null;
    }

    /** @return array{0: string, 1: string, 2: string}|null rue, code postal, commune */
    private function reverse(float $lat, float $lon): ?array
    {
        try {
            $data = (new HttpClient(timeout: 8, retries: 1, delayMs: 150))->getJson('https://data.geopf.fr/geocodage/reverse?'
                . http_build_query(['lat' => $lat, 'lon' => $lon, 'index' => 'address', 'limit' => 1]));
        } catch (\Throwable) {
            return null;
        }
        $p = $data['features'][0]['properties'] ?? null;

        return $p === null || empty($p['name']) ? null : [(string) $p['name'], (string) ($p['postcode'] ?? ''), (string) ($p['city'] ?? 'Clermont-Ferrand')];
    }

    /** « Musée d'art Roger-Quilliot » ≈ « MARQ - Musée d'Art Roger Quilliot » : mots significatifs du nom de l'app presque tous présents */
    public static function sameName(string $found, string $wanted): bool
    {
        $words = static fn (string $s) => array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: '')) ?: [],
            static fn ($w) => strlen($w) > 2 && !in_array($w, ['les', 'des', 'de', 'du', 'la', 'le', 'clermont', 'ferrand'], true)));
        $want = $words($wanted);
        $have = $words($found);
        if ($want === [] || $have === []) {
            return false;
        }

        return count(array_intersect($want, $have)) / count($want) >= 0.75;
    }

    /** external_id est en ASCII : « demo-Cathédrale » → « demo-cathedrale » */
    private static function ascii(string $id): string
    {
        $t = strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $id) ?: ''));

        return substr(trim($t, '-'), 0, 170) ?: substr(sha1($id), 0, 16);
    }

    private static function meters(float $a, float $b, float $c, float $d): float
    {
        $r = 6371000;
        $x = sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($x)));
    }
}
