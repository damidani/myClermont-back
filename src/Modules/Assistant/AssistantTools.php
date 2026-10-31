<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Core\Database;
use App\Modules\Events\SpecEventController;
use App\Modules\Explore\WeatherService;
use App\Modules\Fuel\FuelService;
use App\Modules\Places\MapController;

/**
 * Outils de Clermont IA : ils lisent la base de My Clermont (et deux flux publics).
 * Chaque résultat porte une référence (« event:<slug> », « place:<uid> »…) que le modèle cite dans reply.cards ;
 * le serveur construit les cartes à partir de ces résultats, jamais à partir du texte du modèle.
 */
final class AssistantTools
{
    /** @var array<string, array> résultats vus pendant la question, par référence */
    public array $seen = [];

    /** @var list<array> recherches que l'app lancera avec Plans autour de l'utilisateur */
    public array $nearbySearches = [];

    public function __construct(private readonly float $lat, private readonly float $lon)
    {
    }

    /** Définitions envoyées au modèle (ordre et contenu fixes : le préfixe reste en cache). */
    public static function definitions(): array
    {
        $obj = static fn (array $props, array $required = []) => ['type' => 'object', 'properties' => (object) $props, 'required' => $required];

        return [
            ['name' => 'search_events', 'description' => 'Sorties et événements à Clermont et dans la métropole (concerts, soirées, expos, marchés, sport…).',
             'inputSchema' => $obj([
                 'when'  => ['type' => 'string', 'enum' => ['now', 'tonight', 'today', 'tomorrow', 'weekend', 'week', 'any'], 'description' => 'Période'],
                 'kind'  => ['type' => 'string', 'enum' => SpecEventController::KINDS],
                 'query' => ['type' => 'string', 'description' => 'Mots du titre ou du lieu'],
             ])],
            ['name' => 'search_places', 'description' => 'Lieux édités par My Clermont : incontournables, restaurants et cafés partenaires, culture, sport, spots nature (balade, panorama, baignade…), stations. Triés du plus proche au plus loin.',
             'inputSchema' => $obj([
                 'category'     => ['type' => 'string', 'enum' => MapController::CATEGORIES],
                 'query'        => ['type' => 'string'],
                 'partner_only' => ['type' => 'boolean'],
             ])],
            ['name' => 'list_deals', 'description' => 'Bons plans des commerçants partenaires en cours.', 'inputSchema' => $obj(['category' => ['type' => 'string']])],
            ['name' => 'list_jobs', 'description' => 'Offres d\'emploi, stages et alternances autour de Clermont.',
             'inputSchema' => $obj(['query' => ['type' => 'string'], 'contract' => ['type' => 'string', 'enum' => ['cdi', 'cdd', 'alternance', 'stage', 'freelance', 'interim', 'saisonnier']]])],
            ['name' => 'list_housing', 'description' => 'Annonces de logement : location, colocation, achat.',
             'inputSchema' => $obj(['type' => ['type' => 'string', 'enum' => ['rent', 'colocation', 'sale']], 'max_price' => ['type' => 'integer', 'description' => 'Euros']])],
            ['name' => 'list_services', 'description' => 'Professionnels et services locaux (artisans, dépannage, santé, beauté…).',
             'inputSchema' => $obj(['query' => ['type' => 'string'], 'category' => ['type' => 'string']])],
            ['name' => 'fuel_prices', 'description' => 'Prix des carburants des stations proches (flux officiel).',
             'inputSchema' => $obj(['fuel' => ['type' => 'string', 'enum' => FuelService::FUELS]])],
            ['name' => 'weather', 'description' => 'Météo actuelle à Clermont-Ferrand.', 'inputSchema' => $obj([])],
            ['name' => 'nearby_search', 'description' => 'Pour un commerce ou service courant autour de l\'utilisateur (pharmacie, coiffeur, boulangerie, distributeur, parking…) : l\'app le cherchera elle-même sur la carte. Ne renvoie aucun résultat.',
             'inputSchema' => $obj([
                 'query' => ['type' => 'string'], 'label' => ['type' => 'string'],
                 'icon'  => ['type' => 'string', 'description' => 'Nom SF Symbols, ex. cross.case.fill'],
                 'limit' => ['type' => 'integer'],
             ], ['query', 'label'])],
            ['name' => 'reply', 'strict' => true,
             'description' => 'Réponse finale à afficher. À appeler une seule fois, en dernier.',
             'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['text', 'cards', 'follow_ups'], 'properties' => [
                 'text'       => ['type' => 'string', 'description' => '120 mots maximum, tutoiement'],
                 'cards'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Références des résultats à afficher en cartes (ex. event:…, place:…), 6 maximum'],
                 'follow_ups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2 ou 3 relances courtes'],
             ]]],
        ];
    }

    public function run(string $name, array $in): array
    {
        return match ($name) {
            // Mots-clés trop précis du modèle (« belle vue », « truffade ») : sans résultat, on relance sans eux
            'search_events' => ($r = $this->events($in))['events'] === [] && !empty($in['query']) ? $this->events(['query' => null] + $in) : $r,
            'search_places' => ($r = $this->places($in))['places'] === [] && !empty($in['query']) ? $this->places(['query' => null] + $in) : $r,
            'list_deals'    => $this->rows('deal', "SELECT uid, title, merchant, discount_label, area, exclusive_plus FROM deals WHERE status = 'published' AND deleted_at IS NULL
                                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())" . (!empty($in['category']) ? ' AND category = :c' : '') . ' ORDER BY id DESC LIMIT 6',
                                !empty($in['category']) ? ['c' => $in['category']] : []),
            'list_jobs'     => $this->rows('job', "SELECT uid, title, company_name, contract, city FROM job_offers WHERE status = 'published'"
                                . (!empty($in['contract']) ? ' AND contract = :c' : '') . (!empty($in['query']) ? ' AND (title LIKE :q OR company_name LIKE :q2)' : '') . ' ORDER BY published_at DESC LIMIT 6',
                                array_filter(['c' => $in['contract'] ?? null, 'q' => !empty($in['query']) ? '%' . $in['query'] . '%' : null, 'q2' => !empty($in['query']) ? '%' . $in['query'] . '%' : null])),
            'list_housing'  => $this->rows('housing', "SELECT uid, title, transaction, price_cents, surface_m2, district_label FROM housing_listings WHERE status = 'published'"
                                . (!empty($in['type']) ? ' AND transaction = :t' : '') . (!empty($in['max_price']) ? ' AND price_cents <= :p' : '') . ' ORDER BY published_at DESC LIMIT 6',
                                array_filter(['t' => $in['type'] ?? null, 'p' => !empty($in['max_price']) ? (int) $in['max_price'] * 100 : null])),
            'list_services' => $this->rows('service', "SELECT sp.uid, sp.name, c.name AS category, sp.price_label FROM service_providers sp JOIN service_categories c ON c.id = sp.category_id
                                WHERE sp.status = 'published'" . (!empty($in['category']) ? ' AND c.slug = :c' : '') . (!empty($in['query']) ? ' AND (sp.name LIKE :q OR sp.keywords LIKE :q2)' : '') . ' LIMIT 6',
                                array_filter(['c' => $in['category'] ?? null, 'q' => !empty($in['query']) ? '%' . $in['query'] . '%' : null, 'q2' => !empty($in['query']) ? '%' . $in['query'] . '%' : null])),
            'fuel_prices'   => $this->fuel($in),
            'weather'       => ['weather' => (new WeatherService())->current()],
            'nearby_search' => $this->nearby($in),
            default         => ['error' => 'Outil inconnu.'],
        };
    }

    private function events(array $in): array
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable('now', $paris);
        [$from, $to] = match ($in['when'] ?? 'any') {
            'now'      => [$now, $now],
            'tonight'  => [$now, $now->setTime(23, 59)->modify('+5 hours')],
            'today'    => [$now, $now->setTime(23, 59)],
            'tomorrow' => [$now->modify('+1 day')->setTime(0, 0), $now->modify('+1 day')->setTime(23, 59)],
            'weekend'  => [$now->format('N') >= 6 ? $now : $now->modify('next saturday')->setTime(0, 0), $now->modify('sunday this week')->setTime(23, 59)],
            'week'     => [$now, $now->modify('+7 days')],
            default    => [$now, $now->modify('+30 days')],
        };
        $utc = new \DateTimeZone('UTC');
        $where = "e.status = 'published' AND e.deleted_at IS NULL AND e.ends_at >= :from AND e.starts_at <= :to";
        $params = ['from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'), 'to' => $to->setTimezone($utc)->format('Y-m-d H:i:s')];
        if (!empty($in['kind'])) {
            $where .= ' AND e.kind = :k';
            $params['k'] = $in['kind'];
        }
        if (!empty($in['query'])) {
            $where .= ' AND (e.title LIKE :q OR e.venue_name LIKE :q2)';
            $params['q'] = $params['q2'] = '%' . $in['query'] . '%';
        }
        $out = [];
        // D'abord ce qui commence dans la période (les soirées de ce soir), puis ce qui est déjà en cours (expositions longues)
        $params['from2'] = $params['from'];
        foreach (Database::fetchAll("SELECT e.slug, e.title, e.kind, e.venue_name, e.starts_at, e.ends_at, e.price_label, e.schedule_label FROM events e
                                     WHERE {$where} ORDER BY (e.starts_at >= :from2) DESC, TIMESTAMPDIFF(HOUR, e.starts_at, e.ends_at) <= 24 DESC, e.starts_at LIMIT 8", $params) as $e) {
            $ref = 'event:' . $e['slug'];
            $this->seen[$ref] = ['type' => 'event'] + $e;
            $out[] = ['ref' => $ref, 'title' => $e['title'], 'kind' => $e['kind'], 'venue' => $e['venue_name'],
                      'start' => SpecEventController::iso($e['starts_at']), 'end' => SpecEventController::iso($e['ends_at']),
                      'price' => $e['price_label'], 'schedule' => $e['schedule_label']];
        }

        return ['events' => $out];
    }

    private function places(array $in): array
    {
        $where = "p.status = 'published' AND p.deleted_at IS NULL";
        $params = ['lat' => $this->lat, 'lon' => $this->lon];
        if (!empty($in['category']) && in_array($in['category'], MapController::CATEGORIES, true)) {
            $where .= " AND COALESCE(p.app_category, IF(p.is_must_see = 1, 'incontournable', c.app_category)) = :cat";
            $params['cat'] = $in['category'];
        }
        if (!empty($in['query'])) {
            $where .= ' AND (p.name LIKE :q OR p.keywords LIKE :q2 OR p.tags LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $in['query'] . '%';
        }
        if (!empty($in['partner_only'])) {
            $where .= ' AND p.is_partner = 1';
        }
        $rows = Database::fetchAll(
            'SELECT ' . MapController::columns() . ', ROUND(111320 * SQRT(POW(p.latitude - :lat, 2) + POW((p.longitude - :lon) * 0.7, 2))) AS distance
             FROM places p JOIN place_categories c ON c.id = p.category_id WHERE ' . $where . ' ORDER BY distance LIMIT 6',
            $params,
        );
        $out = [];
        foreach ($rows as $p) {
            $ref = 'place:' . $p['uid'];
            $this->seen[$ref] = ['type' => 'place', 'spot' => MapController::place($p), 'distance' => (int) $p['distance']];
            $out[] = ['ref' => $ref, 'name' => $p['name'], 'category' => $p['app_category_value'], 'subtitle' => $p['subtitle'] ?? $p['tagline'],
                      'partner' => (bool) $p['is_partner_active'], 'distance_m' => (int) $p['distance']];
        }

        return ['places' => $out];
    }

    private function fuel(array $in): array
    {
        $fuel = in_array($in['fuel'] ?? null, FuelService::FUELS, true) ? $in['fuel'] : 'gazole';
        try {
            $stations = array_slice((new FuelService())->stations($this->lat, $this->lon, 5, $fuel)['items'], 0, 5);
        } catch (\Throwable) {
            return ['error' => 'Prix indisponibles pour le moment.'];
        }
        $out = [];
        foreach ($stations as $s) {
            $ref = 'fuel:' . $s['id'];
            $this->seen[$ref] = ['type' => 'fuel', 'fuel' => $fuel] + $s;
            $out[] = ['ref' => $ref, 'address' => $s['address'], 'city' => $s['city'], 'price' => $s['prices'][$fuel]['price'] ?? null, 'distance_m' => $s['distance']];
        }

        return ['fuel' => $fuel, 'stations' => $out];
    }

    private function nearby(array $in): array
    {
        if (count($this->nearbySearches) < 3 && !empty($in['query'])) {
            $this->nearbySearches[] = [
                'query' => mb_substr((string) $in['query'], 0, 60), 'label' => mb_substr((string) ($in['label'] ?? $in['query']), 0, 60),
                'icon'  => preg_match('/^[a-z0-9.]{2,60}$/', (string) ($in['icon'] ?? '')) ? $in['icon'] : 'mappin.circle.fill',
                'limit' => max(1, min(10, (int) ($in['limit'] ?? 4))),
            ];
        }

        return ['status' => 'L\'app affichera les résultats autour de l\'utilisateur.'];
    }

    private function rows(string $type, string $sql, array $params): array
    {
        $out = [];
        foreach (Database::fetchAll($sql, $params) as $r) {
            $ref = $type . ':' . $r['uid'];
            $this->seen[$ref] = ['type' => $type] + $r;
            $out[] = ['ref' => $ref] + array_diff_key($r, ['uid' => 1]);
        }

        return [$type . 's' => $out];
    }
}
