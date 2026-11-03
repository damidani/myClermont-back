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
 * Fiche du lieu (demande 3) : GET /pro/place, PUT /pro/place, POST /pro/place/submit.
 *
 * La fiche complète de l'espace pro est gardée telle quelle dans places.pro (JSON) ; les colonnes lues par l'app
 * (nom, catégorie, position, horaires, atouts…) en sont recopiées à la publication.
 * Sur une fiche publiée, un changement de nom, d'adresse ou de catégorie attend la vérification (places.pro_pending) :
 * l'ancienne version reste sur la carte ; les autres champs passent tout de suite.
 */
final class PlaceController
{
    public const CATEGORIES = ['restaurant', 'fastFood', 'cafe', 'boulangerie', 'culture', 'nature', 'sport', 'lieu'];
    private const DAYS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
    private const ATTRIBUTES = ['terrasse', 'pmr', 'wifi', 'animaux', 'enfants', 'vegetarien', 'emporter', 'livraison', 'reservation', 'cb', 'titres', 'parking', 'tram', 'clim', 'local'];
    private const ICONS = ['coffee', 'fork', 'cake', 'bread', 'burger', 'wine', 'music', 'sun', 'leaf', 'plant', 'heart', 'star', 'sparkle', 'medal', 'gift', 'confetti',
                           'mask', 'mountains', 'tree', 'dog', 'baby', 'wheelchair', 'wifi', 'barbell', 'bed', 'house', 'wrench', 'bag'];
    /** Catégorie de l'app → place_categories.slug (catégorie interne) */
    private const CATEGORY_SLUGS = ['restaurant' => 'restaurants', 'fastFood' => 'fast-food', 'cafe' => 'cafes', 'boulangerie' => 'patisseries',
                                    'culture' => 'culture', 'nature' => 'parcs', 'sport' => 'sport', 'lieu' => 'shopping'];
    /** Champs dont la modification sur une fiche publiée repasse en vérification */
    private const REVIEWED = ['name', 'category', 'address', 'postal_code', 'city', 'latitude', 'longitude'];
    private const LINKS = ['website', 'instagram', 'facebook', 'booking_url', 'menu_url'];

    public function show(Request $request): Response
    {
        return Response::success(self::present(Pro::place($request)));
    }

    public function update(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $place = Pro::place($request);
        $current = self::data($place);
        $next = self::validate($request->json(), $current, Pro::limits(Pro::business($request))['photos']);
        // Nouvelle adresse sans coordonnées : géocodage (Base Adresse Nationale)
        if (($next['latitude'] === null || $next['longitude'] === null) && $next['address'] !== '') {
            [$next['latitude'], $next['longitude']] = (new Geocoder())->address($next['address'] . ', ' . $next['postal_code'] . ' ' . $next['city']) ?? [null, null];
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($place['pro_status'] === 'brouillon') {
            Database::execute('UPDATE places SET pro = :j, updated_at = :d WHERE id = :id', ['j' => self::json($next), 'd' => $now, 'id' => $place['id']]);
            return Response::success(self::present(Pro::place($request)));
        }
        // Fiche en ligne : les champs vérifiés attendent l'équipe, le reste est publié tout de suite
        $live = self::liveSnapshot($place);
        $changed = array_filter(self::REVIEWED, static fn (string $k) => is_float($live[$k])
            ? ($next[$k] === null || abs($next[$k] - $live[$k]) > 0.000001) : (string) $next[$k] !== $live[$k]);
        $pending = $changed === [] ? null : array_intersect_key($next, array_flip(self::REVIEWED));
        $applied = $pending === null ? $next : array_replace($next, $live);
        Database::execute('UPDATE places SET pro = :j, pro_pending = :p, pro_status = :s, updated_at = :d WHERE id = :id', [
            'j' => self::json($next), 'p' => $pending ? self::json($pending) : null, 's' => $pending ? 'en_revue' : 'publiee', 'd' => $now, 'id' => $place['id'],
        ]);
        self::sync((int) $place['id'], $applied, $place['status'] === 'published');

        return Response::success(self::present(Pro::place($request)));
    }

    public function submit(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        Verification::require($request);
        $place = Pro::place($request);
        $d = self::data($place);
        $errors = [];
        if (mb_strlen($d['name']) < 2) {
            $errors['name'] = 'Le nom doit faire au moins 2 caractères.';
        }
        if (mb_strlen($d['description']) < 80) {
            $errors['description'] = 'La description doit faire au moins 80 caractères.';
        }
        if ($d['address'] === '' || $d['latitude'] === null || $d['longitude'] === null) {
            $errors['address'] = 'Indique l\'adresse complète, placée sur la carte.';
        }
        if (array_filter($d['hours'], static fn ($slots) => $slots !== []) === []) {
            $errors['hours'] = 'Indique au moins un jour d\'ouverture.';
        }
        if ($d['photos'] === []) {
            $errors['photos'] = 'Ajoute au moins une photo.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($place['pro_status'] === 'brouillon') {
            Database::execute("UPDATE places SET pro_status = 'en_revue', updated_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $place['id']]);
        }

        return Response::success(self::present(Pro::place($request)));
    }

    /** Fiche créée à l'inscription, en brouillon. @return int places.id */
    public static function createDraft(int $businessId, array $s): int
    {
        $category = in_array($s['activity'], ['restaurant', 'cafe', 'fastFood', 'boulangerie', 'culture', 'sport'], true) ? $s['activity'] : 'lieu';
        [$lat, $lon] = [$s['latitude'], $s['longitude']];
        if ($lat === null || $lon === null) {
            [$lat, $lon] = (new Geocoder())->address($s['address'] . ', ' . $s['postal_code'] . ' ' . $s['city']) ?? [null, null];
        }
        $data = array_replace(self::defaults(), ['name' => $s['name'], 'category' => $category, 'address' => $s['address'], 'postal_code' => $s['postal_code'],
                                      'city' => $s['city'], 'latitude' => $lat, 'longitude' => $lon, 'phone' => $s['phone']]);
        $now = gmdate('Y-m-d H:i:s');
        // Identifiant de l'app, définitif : « nom sans accents@lat,lon » (clé des favoris et des avis)
        $appId = self::appId($s['name'], $lat ?? 45.7772, $lon ?? 3.0870);
        for ($i = 2; Database::fetch('SELECT 1 FROM places WHERE app_id = :a', ['a' => $appId]); $i++) {
            $appId = self::appId($s['name'] . ' ' . $i, $lat ?? 45.7772, $lon ?? 3.0870);
        }

        return Database::insert(
            "INSERT INTO places (uid, app_id, category_id, app_category, name, latitude, longitude, address, postal_code, city, phone, business_id, pro_status, pro, status, created_at, updated_at)
             VALUES (:u, :a, :c, :ac, :n, :lat, :lon, :ad, :pc, :city, :ph, :b, 'brouillon', :pro, 'draft', :cr, :up)",
            ['u' => Crypto::uuid4(), 'a' => $appId, 'c' => self::categoryId($category), 'ac' => $category, 'n' => mb_substr($s['name'], 0, 120),
             'lat' => $lat ?? 45.7772, 'lon' => $lon ?? 3.0870, 'ad' => $s['address'] ?: null, 'pc' => preg_match('/^\d{5}$/', $s['postal_code']) ? $s['postal_code'] : null,
             'city' => $s['city'] ?: 'Clermont-Ferrand', 'ph' => $s['phone'] ?: null, 'b' => $businessId, 'pro' => self::json($data), 'cr' => $now, 'up' => $now],
        );
    }

    /** Validation par l'équipe (back-office) : la fiche, ou sa modification en attente, passe en ligne. */
    public static function approve(int $placeId): void
    {
        $place = Database::fetch('SELECT * FROM places WHERE id = :id', ['id' => $placeId]) ?? throw new \DomainException('Fiche introuvable.');
        $data = self::data($place);
        Database::execute("UPDATE places SET pro_status = 'publiee', pro_pending = NULL, status = 'published', updated_at = :d WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $placeId]);
        self::sync($placeId, $data, true);
    }

    /** Refus : un brouillon y retourne ; une fiche en ligne garde sa version publiée. */
    public static function reject(int $placeId): void
    {
        $place = Database::fetch('SELECT * FROM places WHERE id = :id', ['id' => $placeId]) ?? throw new \DomainException('Fiche introuvable.');
        if ($place['status'] === 'published') {
            $data = array_replace(self::data($place), array_intersect_key(self::liveSnapshot($place), array_flip(self::REVIEWED)));
            Database::execute("UPDATE places SET pro_status = 'publiee', pro_pending = NULL, pro = :j WHERE id = :id", ['j' => self::json($data), 'id' => $placeId]);
        } else {
            Database::execute("UPDATE places SET pro_status = 'brouillon' WHERE id = :id", ['id' => $placeId]);
        }
    }

    /** Recopie la fiche pro dans les colonnes lues par l'app (carte, recherche, fiche détaillée, Clermont IA). */
    public static function sync(int $placeId, array $d, bool $published): void
    {
        $business = Database::fetch('SELECT plan FROM pro_businesses WHERE place_id = :p', ['p' => $placeId]);
        $cover = null;
        foreach ($d['photos'] as $photo) {
            if ($cover === null || $photo['id'] === $d['cover_id']) {
                $cover = $photo['src'];
            }
        }
        $hours = [];
        foreach (['lun' => 'mon', 'mar' => 'tue', 'mer' => 'wed', 'jeu' => 'thu', 'ven' => 'fri', 'sam' => 'sat', 'dim' => 'sun'] as $fr => $en) {
            $hours[$en] = array_map(static fn (array $s) => [$s['open'], $s['close']], $d['hours'][$fr] ?? []);
        }
        Database::execute(
            'UPDATE places SET name = :n, app_category = :ac, category_id = :c, subtitle = :st, tagline = :tg, description = :ds, address = :ad, postal_code = :pc, city = :ci,
                    latitude = :lat, longitude = :lon, phone = :ph, website_url = :w, tip = :tip, highlights = :hl, cover_url = :cv, opening_hours = :oh,
                    is_partner = :partner, updated_at = :d WHERE id = :id',
            ['n' => mb_substr($d['name'], 0, 120), 'ac' => $d['category'], 'c' => self::categoryId($d['category']), 'st' => $d['subtitle'] ?: null, 'tg' => $d['tagline'] ?: null,
             'ds' => $d['description'] ?: null, 'ad' => $d['address'] ?: null, 'pc' => preg_match('/^\d{5}$/', $d['postal_code']) ? $d['postal_code'] : null,
             'ci' => $d['city'] ?: 'Clermont-Ferrand', 'lat' => $d['latitude'] ?? 45.7772, 'lon' => $d['longitude'] ?? 3.0870, 'ph' => $d['phone'] ?: null,
             'w' => $d['website'] ?: null, 'tip' => $d['tip'] ?: null,
             'hl' => $d['highlights'] ? json_encode(array_map(static fn ($h) => ['icon' => $h['icon'], 'title' => $h['title'], 'text' => $h['text']], $d['highlights']), JSON_UNESCAPED_UNICODE) : null,
             'cv' => $cover, 'oh' => json_encode($hours), 'partner' => (int) (($business['plan'] ?? '') === 'partenaire'), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $placeId],
        );
        if ($published) {
            \App\Admin\CacheInvalidator::afterWrite('places', ['id' => $placeId], null);
        }
    }

    /** Place de l'espace pro. */
    public static function present(array $place): array
    {
        $d = self::data($place);

        return ['id' => $place['app_id'] ?? $place['uid']] + $d + [
            'status' => $place['pro_status'] ?? 'brouillon', 'updated_at' => Pro::iso($place['updated_at']),
            // Avis de la team MyClermont (2399ca9a) : lecture seule, publié uniquement ; ignoré par PUT /pro/place
            'team_review' => \App\Modules\Staff\TeamReviewController::published((int) $place['id']),
        ];
    }

    /** Version en ligne pour l'app (GET /places/{id}/sheet) : les champs en vérification gardent leur valeur publiée. */
    public static function published(array $place): array
    {
        $d = self::data($place);

        return $place['pro_pending'] !== null ? array_replace($d, self::liveSnapshot($place)) : $d;
    }

    /** Données de la fiche (places.pro), complétées des valeurs par défaut. */
    public static function data(array $place): array
    {
        $d = json_decode((string) $place['pro'], true) ?: [];

        return array_replace(self::defaults(), array_intersect_key($d, self::defaults()));
    }

    private static function defaults(): array
    {
        return ['name' => '', 'category' => 'lieu', 'subtitle' => '', 'tagline' => '', 'description' => '', 'address' => '', 'postal_code' => '', 'city' => '',
                'latitude' => null, 'longitude' => null, 'phone' => '', 'email' => '', 'website' => '', 'instagram' => '', 'facebook' => '', 'booking_url' => '', 'menu_url' => '',
                'price_level' => 0, 'attributes' => [], 'hours' => array_fill_keys(self::DAYS, []), 'exceptional' => [], 'photos' => [], 'cover_id' => null,
                'highlights' => [], 'tip' => ''];
    }

    /** Version actuellement en ligne des champs vérifiés (colonnes de places). */
    private static function liveSnapshot(array $place): array
    {
        return ['name' => $place['name'], 'category' => $place['app_category'] ?? 'lieu', 'address' => (string) $place['address'], 'postal_code' => (string) $place['postal_code'],
                'city' => (string) $place['city'], 'latitude' => (float) $place['latitude'], 'longitude' => (float) $place['longitude']];
    }

    private static function validate(array $j, array $current, int $maxPhotos): array
    {
        $errors = [];
        $d = $current;
        foreach (['name' => [60, 'Le nom'], 'subtitle' => [40, 'Le sous-titre'], 'tagline' => [70, 'L\'accroche'], 'description' => [600, 'La description'],
                  'address' => [200, 'L\'adresse'], 'postal_code' => [5, 'Le code postal'], 'city' => [100, 'La ville'], 'phone' => [30, 'Le téléphone'],
                  'email' => [191, 'L\'e-mail'], 'tip' => [200, 'Le conseil'], 'website' => [500, 'Le site'], 'instagram' => [500, 'Le lien Instagram'],
                  'facebook' => [500, 'Le lien Facebook'], 'booking_url' => [500, 'Le lien de réservation'], 'menu_url' => [500, 'Le lien du menu']] as $k => [$max, $label]) {
            $v = Pro::str($j, $k, $max, $errors, $label);
            if ($v !== null) {
                $d[$k] = $v;
            }
        }
        foreach (self::LINKS as $k) {
            if (!Pro::url($d[$k])) {
                $errors[$k] = 'Lien invalide : il doit commencer par http:// ou https://.';
            }
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (array_key_exists('category', $j)) {
            in_array($j['category'], self::CATEGORIES, true) ? $d['category'] = $j['category'] : $errors['category'] = 'Catégorie inconnue.';
        }
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $k => [$min, $max]) {
            if (array_key_exists($k, $j)) {
                $j[$k] === null || (is_numeric($j[$k]) && $j[$k] >= $min && $j[$k] <= $max)
                    ? $d[$k] = $j[$k] === null ? null : round((float) $j[$k], 6) : $errors[$k] = 'Coordonnée invalide.';
            }
        }
        // Adresse modifiée sans nouvelles coordonnées : elles seront recalculées
        if ((($j['address'] ?? null) !== null && $d['address'] !== $current['address']) && !array_key_exists('latitude', $j)) {
            $d['latitude'] = $d['longitude'] = null;
        }
        if (array_key_exists('price_level', $j)) {
            in_array($j['price_level'], [0, 1, 2, 3, 4], true) ? $d['price_level'] = $j['price_level'] : $errors['price_level'] = 'Niveau de prix : 0 à 4.';
        }
        if (array_key_exists('attributes', $j)) {
            is_array($j['attributes']) && !array_diff($j['attributes'], self::ATTRIBUTES)
                ? $d['attributes'] = array_values(array_unique($j['attributes'])) : $errors['attributes'] = 'Caractéristique inconnue.';
        }
        if (array_key_exists('hours', $j)) {
            $hours = array_fill_keys(self::DAYS, []);
            foreach (is_array($j['hours']) ? $j['hours'] : [] as $day => $slots) {
                if (!in_array($day, self::DAYS, true) || !is_array($slots) || ($slots = self::slots($slots)) === null) {
                    $errors['hours'] = 'Horaires invalides (format 09:00 – 18:30).';
                    break;
                }
                $hours[$day] = $slots;
            }
            $d['hours'] = $hours;
        }
        if (array_key_exists('exceptional', $j)) {
            $out = [];
            foreach (is_array($j['exceptional']) ? $j['exceptional'] : [] as $e) {
                $slots = is_array($e['slots'] ?? null) ? self::slots($e['slots']) : [];
                if (!is_array($e) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['date'] ?? '')) || $slots === null) {
                    $errors['exceptional'] = 'Horaire exceptionnel invalide.';
                    break;
                }
                $out[] = ['id' => is_string($e['id'] ?? null) && $e['id'] !== '' ? mb_substr($e['id'], 0, 40) : Crypto::uuid4(), 'date' => $e['date'],
                          'closed' => ($e['closed'] ?? false) === true, 'slots' => $slots, 'note' => mb_substr(trim((string) ($e['note'] ?? '')), 0, 120)];
            }
            $d['exceptional'] = $out;
        }
        if (array_key_exists('photos', $j)) {
            $photos = [];
            foreach (is_array($j['photos']) ? $j['photos'] : [] as $p) {
                if (!is_array($p) || !is_string($p['src'] ?? null) || !Pro::url($p['src']) || $p['src'] === '') {
                    $errors['photos'] = 'Photo invalide.';
                    break;
                }
                if (mb_strlen((string) ($p['caption'] ?? '')) > 60) {
                    $errors['photos'] = 'Légende de photo : 60 caractères au plus.';
                }
                $photos[] = ['id' => is_string($p['id'] ?? null) && $p['id'] !== '' ? mb_substr($p['id'], 0, 40) : Crypto::uuid4(), 'src' => $p['src'],
                             'caption' => trim((string) ($p['caption'] ?? ''))];
            }
            if (count($photos) > $maxPhotos) {
                throw Pro::planLimit('photos', "Ta formule permet {$maxPhotos} photos.");
            }
            $d['photos'] = $photos;
        }
        if (array_key_exists('cover_id', $j)) {
            $d['cover_id'] = is_string($j['cover_id']) ? $j['cover_id'] : null;
        }
        if ($d['cover_id'] !== null && !in_array($d['cover_id'], array_column($d['photos'], 'id'), true)) {
            $d['cover_id'] = $d['photos'][0]['id'] ?? null;
        }
        if (array_key_exists('highlights', $j)) {
            $out = [];
            foreach (is_array($j['highlights']) ? $j['highlights'] : [] as $h) {
                if (!is_array($h) || !in_array($h['icon'] ?? null, self::ICONS, true) || mb_strlen((string) ($h['title'] ?? '')) > 40 || mb_strlen((string) ($h['text'] ?? '')) > 90
                    || trim((string) ($h['title'] ?? '')) === '') {
                    $errors['highlights'] = 'Atout invalide (icône connue, titre 40 et texte 90 caractères au plus).';
                    break;
                }
                $out[] = ['id' => is_string($h['id'] ?? null) && $h['id'] !== '' ? mb_substr($h['id'], 0, 40) : Crypto::uuid4(), 'icon' => $h['icon'],
                          'title' => trim($h['title']), 'text' => trim((string) ($h['text'] ?? ''))];
            }
            count($out) > 4 ? $errors['highlights'] = '4 atouts au plus.' : $d['highlights'] = $out;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $d;
    }

    /** @return list<array{open: string, close: string}>|null */
    private static function slots(array $slots): ?array
    {
        $out = [];
        foreach ($slots as $s) {
            if (!is_array($s) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($s['open'] ?? '')) || !preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', (string) ($s['close'] ?? ''))) {
                return null;
            }
            $out[] = ['open' => $s['open'], 'close' => $s['close']];
        }

        return $out;
    }

    public static function appId(string $name, float $lat, float $lon): string
    {
        $plain = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($name, \Normalizer::FORM_D) ?: $name));
        $ascii = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\'’ -]+/u', ' ', strtr($plain, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss'])))) ?: 'lieu';

        return mb_substr($ascii, 0, 90) . '@' . number_format($lat, 4, '.', '') . ',' . number_format($lon, 4, '.', '');
    }

    private static function categoryId(string $category): int
    {
        static $ids = null;
        $ids ??= array_column(Database::fetchAll('SELECT id, slug FROM place_categories'), 'id', 'slug');

        return (int) ($ids[self::CATEGORY_SLUGS[$category] ?? 'shopping'] ?? reset($ids));
    }

    private static function json(array $d): string
    {
        return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
