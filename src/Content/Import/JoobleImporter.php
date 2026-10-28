<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Offres d'emploi agrégées par Jooble (des milliers de sites d'emploi, dont les grands portails) → cible jobs.
 *
 * API partenaire gratuite : https://jooble.org/api/about (clé envoyée par e-mail).
 * Chaque offre garde son lien Jooble (`link`) qui renvoie l'utilisateur vers l'offre d'origine.
 *
 * Options : key, location (défaut Clermont-Ferrand), radius (km, défaut 15), keywords, max_pages (défaut 10)
 */
final class JoobleImporter implements Importer
{
    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 30, retries: 2, delayMs: 1500))
    {
    }

    public function fetch(array $options): iterable
    {
        if (empty($options['key'])) {
            throw new \RuntimeException('Clé Jooble manquante (JOOBLE_API_KEY).');
        }
        $maxPages = (int) ($options['max_pages'] ?? 10);
        for ($page = 1; $page <= $maxPages; $page++) {
            $r = $this->http->request('POST', 'https://jooble.org/api/' . rawurlencode((string) $options['key']), ['Content-Type: application/json'], json_encode([
                'keywords' => (string) ($options['keywords'] ?? ''), 'location' => (string) ($options['location'] ?? 'Clermont-Ferrand'),
                'radius' => (string) ($options['radius'] ?? 15), 'page' => (string) $page, 'ResultOnPage' => '50',
            ]));
            if ($r['status'] !== 200) {
                throw new \RuntimeException("Jooble : HTTP {$r['status']}");
            }
            $jobs = (array) (json_decode($r['body'], true)['jobs'] ?? []);
            foreach ($jobs as $job) {
                if (is_array($job) && ($item = self::map($job)) !== null) {
                    yield $item;
                }
            }
            if (count($jobs) < 50) {
                break;
            }
        }
    }

    public static function map(array $j): ?array
    {
        $url = is_string($j['link'] ?? null) && str_starts_with($j['link'], 'https://') ? $j['link'] : null;
        if ($url === null || empty($j['id']) || empty($j['title'])) {
            return null;
        }
        $title = trim(html_entity_decode(strip_tags((string) $j['title'])));
        $description = Text::fromHtml((string) ($j['snippet'] ?? ''));
        [$min, $max, $period] = Text::salary(is_string($j['salary'] ?? null) ? $j['salary'] : null);
        $type = mb_strtolower((string) ($j['type'] ?? ''));

        return array_filter([
            'external_id'      => 'jbl-' . $j['id'],
            'title'            => mb_substr((string) preg_replace('/\s*\(?\s*[HF]\s*\/\s*[HF]\s*\)?\s*$/iu', '', $title), 0, 160),
            'company_name'     => trim((string) ($j['company'] ?? '')) ?: 'Entreprise non communiquée',
            'contract'         => Text::contract($type . ' ' . $title . ' ' . $description),
            'summary'          => Text::summary($description),
            'description'      => $description,
            'city'             => is_string($j['location'] ?? null) && $j['location'] !== '' ? trim(explode(',', $j['location'])[0]) : null,
            'salary_min_cents' => $min,
            'salary_max_cents' => $max,
            'salary_period'    => $period,
            'salary_label'     => $min === null && !empty($j['salary']) ? mb_substr((string) $j['salary'], 0, 80) : null,
            'notice'           => !empty($j['source']) ? mb_substr('Offre publiée sur ' . $j['source'], 0, 255) : null,
            'url'              => $url,
            'application_url'  => $url,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
