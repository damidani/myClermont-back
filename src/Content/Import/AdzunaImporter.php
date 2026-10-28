<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Offres d'emploi agrégées par Adzuna (Indeed, HelloWork, Meteojob, sites carrière… dédoublonnés) → cible jobs.
 *
 * API officielle et gratuite : https://developer.adzuna.com (app_id + app_key).
 * Chaque offre garde son lien de redirection Adzuna (`redirect_url`) : « Postuler » renvoie l'utilisateur
 * vers l'offre d'origine. Mention obligatoire : « Offres via Adzuna » (attribution de la source).
 *
 * Options : app_id, app_key, where (défaut Clermont-Ferrand), distance (km, défaut 15), max_pages (défaut 10 × 50 offres),
 *           what (mots-clés facultatifs), max_days_old (défaut 30)
 */
final class AdzunaImporter implements Importer
{
    private const URL = 'https://api.adzuna.com/v1/api/jobs/fr/search/';

    private const CATEGORIES = [
        'it-jobs' => 'tech', 'pr-advertising-marketing-jobs' => 'communication', 'creative-design-jobs' => 'communication',
        'sales-jobs' => 'commerce', 'retail-jobs' => 'commerce', 'customer-services-jobs' => 'commerce',
        'hospitality-catering-jobs' => 'hotellerie', 'healthcare-nursing-jobs' => 'sante', 'social-work-jobs' => 'sante',
        'teaching-jobs' => 'education', 'manufacturing-jobs' => 'industrie', 'engineering-jobs' => 'industrie', 'energy-oil-gas-jobs' => 'industrie',
        'trade-construction-jobs' => 'btp', 'maintenance-jobs' => 'btp', 'logistics-warehouse-jobs' => 'transport',
        'admin-jobs' => 'administratif', 'accounting-finance-jobs' => 'administratif', 'hr-jobs' => 'administratif',
        'legal-jobs' => 'administratif', 'consultancy-jobs' => 'administratif', 'travel-jobs' => 'culture',
    ];

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 30, retries: 2, delayMs: 2500))
    {
    }

    public function fetch(array $options): iterable
    {
        if (empty($options['app_id']) || empty($options['app_key'])) {
            throw new \RuntimeException('Identifiants Adzuna manquants (ADZUNA_APP_ID / ADZUNA_APP_KEY).');
        }
        $maxPages = (int) ($options['max_pages'] ?? 10);
        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->http->getJson(self::URL . $page . '?' . http_build_query(array_filter([
                'app_id' => $options['app_id'], 'app_key' => $options['app_key'], 'results_per_page' => 50,
                'where' => $options['where'] ?? 'Clermont-Ferrand', 'distance' => (int) ($options['distance'] ?? 15),
                'what' => $options['what'] ?? null, 'max_days_old' => (int) ($options['max_days_old'] ?? 30), 'sort_by' => 'date',
            ])));
            $results = (array) ($data['results'] ?? []);
            foreach ($results as $job) {
                if (is_array($job) && ($item = self::map($job)) !== null) {
                    yield $item;
                }
            }
            if (count($results) < 50 || $page * 50 >= (int) ($data['count'] ?? 0)) {
                break;
            }
        }
    }

    public static function map(array $j): ?array
    {
        $url = is_string($j['redirect_url'] ?? null) && str_starts_with($j['redirect_url'], 'https://') ? $j['redirect_url'] : null;
        if ($url === null || empty($j['id']) || empty($j['title'])) {
            return null; // sans lien, impossible de renvoyer l'utilisateur vers l'offre
        }
        $title = trim(html_entity_decode(strip_tags((string) $j['title'])));
        $description = Text::fromHtml((string) ($j['description'] ?? ''));
        $default = match ($j['contract_type'] ?? null) { 'contract' => 'cdd', default => 'cdi' };
        $predicted = (string) ($j['salary_is_predicted'] ?? '0') === '1'; // salaire estimé par Adzuna : jamais affiché
        $min = !$predicted ? Text::cents($j['salary_min'] ?? null) : null;
        $max = !$predicted ? Text::cents($j['salary_max'] ?? null) : null;
        $area = (array) ($j['location']['area'] ?? []);

        return array_filter([
            'external_id'      => 'adz-' . $j['id'],
            'title'            => mb_substr((string) preg_replace('/\s*\(?\s*[HF]\s*\/\s*[HF]\s*\)?\s*$/iu', '', $title), 0, 160),
            'company_name'     => trim((string) ($j['company']['display_name'] ?? '')) ?: 'Entreprise non communiquée',
            'contract'         => Text::contract($title . ' ' . $description, $default),
            'category'         => self::CATEGORIES[$j['category']['tag'] ?? ''] ?? 'autre',
            'work_mode'        => str_contains(mb_strtolower((string) $description), 'télétravail') ? 'hybrid' : 'onsite',
            'summary'          => Text::summary($description),
            'description'      => $description,
            'city'             => is_string(end($area)) && end($area) !== '' ? end($area) : null,
            'latitude'         => is_numeric($j['latitude'] ?? null) ? (float) $j['latitude'] : null,
            'longitude'        => is_numeric($j['longitude'] ?? null) ? (float) $j['longitude'] : null,
            'salary_min_cents' => $min,
            'salary_max_cents' => $max !== $min ? $max : null,
            'salary_period'    => $min !== null ? ($min < 1000_00 ? 'hour' : ($min < 10000_00 ? 'month' : 'year')) : null,
            'url'              => $url,
            'application_url'  => $url,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
