<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Lit les données structurées schema.org (JSON-LD) publiées par les sites partenaires :
 *  - offres d'emploi (JobPosting) : présentes sur la quasi-totalité des sites carrière et ATS
 *    (Welcome to the Jungle, Teamtailor, Flatchr, Taleez, sites d'entreprises…) car Google for Jobs les exige ;
 *  - logements (Apartment, House, SingleFamilyResidence, Accommodation, RealEstateListing + Offer) : sites d'agences.
 *
 * À utiliser uniquement sur les sites qui l'autorisent (partenaire, accord écrit). Robot respectueux :
 * robots.txt appliqué, une page à la fois avec une pause entre deux pages, nombre de pages plafonné.
 *
 * Options (config/imports.php) :
 *   kind         jobs | housing
 *   urls         pages de liste ou sitemaps XML à parcourir
 *   follow       expression régulière des liens de fiches à ouvrir (ex. '#/offres/[\w-]+$#')
 *   max_pages    plafond de pages ouvertes par import (défaut 200)
 *   delay_ms     pause entre deux pages du même site (défaut 1000)
 *   defaults     valeurs par défaut fusionnées dans chaque élément (ex. ['company_name' => 'Michelin'], ['transaction' => 'rent'])
 */
final class JsonLdImporter implements Importer
{
    private const JOB_TYPES = ['JobPosting'];
    private const HOUSING_TYPES = ['Apartment', 'House', 'SingleFamilyResidence', 'Accommodation', 'Residence', 'RealEstateListing', 'Room', 'Product', 'Offer'];

    /** @var array<string, list<string>> règles Disallow par hôte */
    private array $robots = [];

    public function __construct(private ?HttpClient $http = null)
    {
    }

    public function fetch(array $options): iterable
    {
        $kind = $options['kind'] ?? 'jobs';
        if (!in_array($kind, ['jobs', 'housing'], true)) {
            throw new \InvalidArgumentException('kind doit valoir jobs ou housing');
        }
        $this->http ??= new HttpClient(timeout: 20, retries: 1, maxBytes: 5_000_000, delayMs: (int) ($options['delay_ms'] ?? 1000));
        $follow = $options['follow'] ?? null;
        $maxPages = (int) ($options['max_pages'] ?? 200);
        $defaults = (array) ($options['defaults'] ?? []);

        $queue = array_values((array) ($options['urls'] ?? []));
        $visited = [];
        $seen = [];
        while ($queue !== [] && count($visited) < $maxPages) {
            $url = array_shift($queue);
            if (isset($visited[$url]) || !$this->allowed($url)) {
                continue;
            }
            $visited[$url] = true;
            try {
                $body = $this->http->get($url, ['Accept: text/html,application/xhtml+xml,application/xml'])['body'];
            } catch (\RuntimeException) {
                continue; // une page en erreur n'arrête pas l'import
            }

            // Sitemap : on suit ses <loc> qui correspondent au motif
            if (str_contains(substr($body, 0, 500), '<urlset') || str_contains(substr($body, 0, 500), '<sitemapindex')) {
                preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $m);
                foreach ($m[1] as $loc) {
                    $loc = html_entity_decode($loc);
                    if (str_ends_with($loc, '.xml') || ($follow && preg_match($follow, $loc))) {
                        $queue[] = $loc;
                    }
                }
                continue;
            }

            foreach (self::extract($body) as $node) {
                $item = $kind === 'jobs' ? self::mapJob($node, $url) : self::mapHousing($node, $url);
                if ($item !== null && !isset($seen[$item['external_id']])) {
                    $seen[$item['external_id']] = true;
                    yield array_replace($defaults, array_filter($item, static fn ($v) => $v !== null && $v !== '' && $v !== []));
                }
            }
            if ($follow) {
                foreach (self::links($body, $url) as $link) {
                    if (preg_match($follow, $link) && !isset($visited[$link])) {
                        $queue[] = $link;
                    }
                }
            }
        }
    }

    /**
     * Objets JSON-LD d'une page HTML (blocs multiples, tableaux et @graph aplatis).
     *
     * @return list<array<string, mixed>>
     */
    public static function extract(string $html): array
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        $nodes = [];
        $walk = static function (mixed $data) use (&$walk, &$nodes): void {
            if (!is_array($data)) {
                return;
            }
            if (array_is_list($data)) {
                array_map($walk, $data);
                return;
            }
            if (isset($data['@graph'])) {
                $walk($data['@graph']);
            }
            if (isset($data['@type'])) {
                $nodes[] = $data;
            }
        };
        foreach ($m[1] as $json) {
            $walk(json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true));
        }

        return $nodes;
    }

    public static function mapJob(array $n, string $pageUrl): ?array
    {
        if (!self::isType($n, self::JOB_TYPES) || empty($n['title'])) {
            return null;
        }
        $org = is_array($n['hiringOrganization'] ?? null) ? $n['hiringOrganization'] : ['name' => $n['hiringOrganization'] ?? null];
        $location = self::first($n['jobLocation'] ?? null);
        $address = is_array($location['address'] ?? null) ? $location['address'] : [];
        $description = Text::fromHtml(is_string($n['description'] ?? null) ? $n['description'] : null);
        $types = array_map('strtoupper', (array) ($n['employmentType'] ?? []));
        $haystack = mb_strtolower($n['title'] . ' ' . mb_substr((string) $description, 0, 2000));
        $contract = match (true) {
            str_contains($haystack, 'alternance') || str_contains($haystack, 'apprenti') => 'alternance',
            in_array('INTERN', $types, true) || preg_match('/\bstage\b/u', $haystack) => 'stage',
            in_array('CONTRACTOR', $types, true) || str_contains($haystack, 'freelance') => 'freelance',
            str_contains($haystack, 'intérim') || str_contains($haystack, 'interim') => 'interim',
            str_contains($haystack, 'saisonn') => 'saisonnier',
            in_array('TEMPORARY', $types, true) || preg_match('/\bcdd\b/u', $haystack) => 'cdd',
            default => 'cdi',
        };
        $salary = self::salary($n['baseSalary'] ?? null);
        $geo = is_array($location['geo'] ?? null) ? $location['geo'] : [];
        $remote = ($n['jobLocationType'] ?? '') === 'TELECOMMUTE';
        $url = self::https($n['url'] ?? null) ?? self::https($pageUrl);
        $valid = isset($n['validThrough']) ? strtotime((string) $n['validThrough']) : false;

        return [
            'external_id'      => self::id($n, $url),
            'title'            => mb_substr(trim(html_entity_decode((string) $n['title'])), 0, 160),
            'company_name'     => is_string($org['name'] ?? null) ? trim($org['name']) : null,
            'company_logo_url' => self::https(is_array($org['logo'] ?? null) ? ($org['logo']['url'] ?? null) : ($org['logo'] ?? null)),
            'contract'         => $contract,
            'work_mode'        => $remote ? 'remote' : (str_contains($haystack, 'télétravail') || str_contains($haystack, 'hybride') ? 'hybrid' : 'onsite'),
            'summary'          => Text::summary($description),
            'description'      => $description,
            'city'             => is_string($address['addressLocality'] ?? null) ? $address['addressLocality'] : null,
            'latitude'         => is_numeric($geo['latitude'] ?? null) ? (float) $geo['latitude'] : null,
            'longitude'        => is_numeric($geo['longitude'] ?? null) ? (float) $geo['longitude'] : null,
            'salary_min_cents' => $salary[0],
            'salary_max_cents' => $salary[1],
            'salary_period'    => $salary[2],
            'expires_at'       => $valid ? gmdate('Y-m-d', $valid) : null,
            'url'              => $url,
            'application_url'  => $url,
        ];
    }

    public static function mapHousing(array $n, string $pageUrl): ?array
    {
        if (!self::isType($n, self::HOUSING_TYPES)) {
            return null;
        }
        // RealEstateListing / Offer décrivent l'annonce, le bien est dans about / itemOffered / mainEntity
        $offer = self::first($n['offers'] ?? null) ?? (self::isType($n, ['Offer']) ? $n : []);
        $place = self::first($n['about'] ?? $n['mainEntity'] ?? $offer['itemOffered'] ?? null) ?? $n;
        if (!self::isType($place, ['Apartment', 'House', 'SingleFamilyResidence', 'Accommodation', 'Residence', 'Room', 'Place', 'Product', 'RealEstateListing'])) {
            return null;
        }
        $price = Text::cents($offer['price'] ?? ($offer['priceSpecification']['price'] ?? null));
        $name = $n['name'] ?? $place['name'] ?? null;
        if ($price === null || !is_string($name)) {
            return null;
        }
        $url = self::https($n['url'] ?? $offer['url'] ?? null) ?? self::https($pageUrl);
        $function = (string) ($offer['businessFunction'] ?? '');
        $hay = mb_strtolower($name . ' ' . $url);
        $transaction = match (true) {
            str_contains($hay, 'colocation') => 'colocation',
            str_contains($function, 'LeaseOut') || str_contains($hay, 'location') || str_contains($hay, 'louer') => 'rent',
            str_contains($function, 'Sell') || str_contains($hay, 'vente') || str_contains($hay, 'acheter') => 'sale',
            default => null, // à fournir dans defaults.transaction
        };
        $types = (array) ($place['@type'] ?? []);
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $geo = is_array($place['geo'] ?? null) ? $place['geo'] : [];
        $floor = self::number($place['floorSize'] ?? null);
        $images = array_values(array_filter(array_map(
            static fn ($i) => self::https(is_array($i) ? ($i['url'] ?? $i['contentUrl'] ?? null) : $i),
            (array) ($n['image'] ?? $place['image'] ?? []),
        )));

        return [
            'external_id'   => self::id($n + $place, $url),
            'transaction'   => $transaction,
            'property_type' => match (true) {
                in_array('House', $types, true) || in_array('SingleFamilyResidence', $types, true) => 'house',
                in_array('Room', $types, true) => 'room',
                str_contains($hay, 'studio') => 'studio',
                default => 'apartment',
            },
            'title'         => mb_substr(trim(html_entity_decode($name)), 0, 160),
            'description'   => Text::fromHtml(is_string($n['description'] ?? $place['description'] ?? null) ? ($n['description'] ?? $place['description']) : null),
            'price_cents'   => $price,
            'surface_m2'    => $floor,
            'rooms'         => is_numeric($place['numberOfRooms'] ?? null) ? (int) $place['numberOfRooms'] : null,
            'bedrooms'      => is_numeric($place['numberOfBedrooms'] ?? null) ? (int) $place['numberOfBedrooms'] : null,
            'city'          => is_string($address['addressLocality'] ?? null) ? $address['addressLocality'] : null,
            'postal_code'   => is_string($address['postalCode'] ?? null) ? $address['postalCode'] : null,
            'latitude'      => is_numeric($geo['latitude'] ?? null) ? round((float) $geo['latitude'], 3) : null,   // ~100 m : jamais l'adresse exacte
            'longitude'     => is_numeric($geo['longitude'] ?? null) ? round((float) $geo['longitude'], 3) : null,
            'images'        => array_slice($images, 0, 20),
            'url'           => $url,
        ];
    }

    /** @return list<string> liens absolus de la page */
    public static function links(string $html, string $base): array
    {
        preg_match_all('#<a\s[^>]*href=["\']([^"\'\#]+)#i', $html, $m);
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $links = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            $abs = match (true) {
                str_starts_with($href, 'https://') => $href,
                str_starts_with($href, '//') => 'https:' . $href,
                str_starts_with($href, '/') => $origin . $href,
                default => null,
            };
            // On reste sur le site d'origine
            if ($abs !== null && parse_url($abs, PHP_URL_HOST) === ($parts['host'] ?? null)) {
                $links[$abs] = true;
            }
        }

        return array_keys($links);
    }

    /** robots.txt du site (règles « User-agent: * » et « myClermont »). */
    private function allowed(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!isset($this->robots[$host])) {
            $this->robots[$host] = [];
            try {
                $r = $this->http->request('GET', 'https://' . $host . '/robots.txt');
                $this->robots[$host] = $r['status'] === 200 ? self::disallowRules($r['body']) : [];
            } catch (\RuntimeException) {
            }
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        foreach ($this->robots[$host] as $rule) {
            if ($rule !== '' && str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function disallowRules(string $robots): array
    {
        $rules = [];
        $applies = false;
        $previousWasAgent = false;
        foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                $match = $v === '*' || stripos($v, 'myclermont') !== false;
                $applies = $previousWasAgent ? ($applies || $match) : $match;
                $previousWasAgent = true;
                continue;
            }
            $previousWasAgent = false;
            if ($applies && $k === 'disallow') {
                $rules[] = str_replace('*', '', $v);
            }
        }

        return $rules;
    }

    private static function isType(array $n, array $types): bool
    {
        return (bool) array_intersect((array) ($n['@type'] ?? []), $types);
    }

    private static function first(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }

        return array_is_list($v) ? (is_array($v[0] ?? null) ? $v[0] : null) : $v;
    }

    private static function id(array $n, ?string $url): string
    {
        $id = $n['identifier'] ?? null;
        $id = is_array($id) ? ($id['value'] ?? null) : $id;
        $id = is_scalar($id) && $id !== '' ? (string) $id : ($url ?? json_encode($n));

        // external_id est en ASCII, 191 caractères max : les URL longues sont résumées
        return preg_match('/^[\x21-\x7E]{1,191}$/', $id) ? $id : 'h:' . sha1($id);
    }

    /** @return array{0: ?int, 1: ?int, 2: ?string} */
    private static function salary(mixed $s): array
    {
        if (!is_array($s)) {
            return is_string($s) ? Text::salary($s) : [null, null, null];
        }
        $value = $s['value'] ?? $s;
        $unit = strtoupper((string) ($value['unitText'] ?? $s['unitText'] ?? 'YEAR'));
        $period = ['YEAR' => 'year', 'MONTH' => 'month', 'HOUR' => 'hour'][$unit] ?? null;
        $min = Text::cents($value['minValue'] ?? $value['value'] ?? null);
        $max = Text::cents($value['maxValue'] ?? null);

        return [$min, $max !== $min ? $max : null, $min !== null ? $period : null];
    }

    private static function number(mixed $v): ?float
    {
        $v = is_array($v) ? ($v['value'] ?? null) : $v;
        $v = is_string($v) ? str_replace(',', '.', (string) preg_replace('/[^\d,.]/', '', $v)) : $v;

        return is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
