<?php

declare(strict_types=1);

namespace App\Content\Import;

use App\Cache\Cache;
use App\Core\Config;

/**
 * Offres d'emploi de France Travail (API « Offres d'emploi v2 », francetravail.io) → cible jobs.
 *
 * Source officielle et gratuite : créer une application sur https://francetravail.io, y ajouter l'API
 * « Offres d'emploi v2 », puis renseigner FRANCE_TRAVAIL_CLIENT_ID / FRANCE_TRAVAIL_CLIENT_SECRET.
 *
 * Options (config/imports.php) :
 *   client_id, client_secret   identifiants de l'application
 *   commune                    code INSEE (défaut 63113 = Clermont-Ferrand)
 *   distance                   rayon en km autour de la commune (défaut 15)
 *   queries                    liste de filtres supplémentaires, une recherche par entrée (défaut [[]]),
 *                              ex. [['typeContrat' => 'CDI'], ['typeContrat' => 'CDD,MIS,SAI']] pour rester sous 3 150 offres par recherche
 *   max_per_query              plafond par recherche (l'API n'en sert pas plus de 3 150)
 */
final class FranceTravailImporter implements Importer
{
    private const TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire';
    private const SEARCH_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search';
    private const PAGE = 150;
    private const API_MAX = 3150;

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 30, retries: 3, delayMs: 350))
    {
    }

    public function fetch(array $options): iterable
    {
        $token = $this->token((string) ($options['client_id'] ?? ''), (string) ($options['client_secret'] ?? ''));
        $base = array_filter([
            'commune'  => (string) ($options['commune'] ?? '63113'),
            'distance' => (int) ($options['distance'] ?? 15),
            'sort'     => 1, // les plus récentes d'abord
        ], static fn ($v) => $v !== '' && $v !== null);
        $max = min((int) ($options['max_per_query'] ?? self::API_MAX), self::API_MAX);
        $seen = [];

        foreach ((array) ($options['queries'] ?? [[]]) as $filters) {
            for ($start = 0; $start < $max; $start += self::PAGE) {
                $end = min($start + self::PAGE, $max) - 1;
                $url = (Config::get('imports.france_travail.search_url') ?: self::SEARCH_URL) . '?'
                     . http_build_query($base + (array) $filters + ['range' => "{$start}-{$end}"]);
                $r = $this->http->request('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
                if ($r['status'] === 204) {
                    break; // aucune offre
                }
                if ($r['status'] !== 200 && $r['status'] !== 206) {
                    throw new \RuntimeException("France Travail : HTTP {$r['status']} " . mb_substr($r['body'], 0, 200));
                }
                $offers = json_decode($r['body'], true, 64, JSON_THROW_ON_ERROR)['resultats'] ?? [];
                foreach ($offers as $offer) {
                    if (is_array($offer) && isset($offer['id']) && !isset($seen[$offer['id']])) {
                        $seen[$offer['id']] = true;
                        yield self::map($offer);
                    }
                }
                // Content-Range : « offres 0-149/523 »
                $total = preg_match('#/(\d+)$#', $r['headers']['content-range'] ?? '', $m) ? (int) $m[1] : 0;
                if (count($offers) < self::PAGE || $end + 1 >= $total) {
                    break;
                }
            }
        }
    }

    /** Offre France Travail → format normalisé jobs (docs/IMPORTS.md). */
    public static function map(array $o): array
    {
        $description = Text::fromHtml($o['description'] ?? null);
        $nature = mb_strtolower((string) ($o['natureContrat'] ?? ''));
        $contract = match (true) {
            !empty($o['alternance']) || str_contains($nature, 'apprentissage') || str_contains($nature, 'professionnalisation') => 'alternance',
            default => match (strtoupper((string) ($o['typeContrat'] ?? ''))) {
                'CDI', 'DIN'        => 'cdi',
                'MIS', 'TTI'        => 'interim',
                'SAI'               => 'saisonnier',
                'LIB', 'FRA', 'REP' => 'freelance',
                default             => 'cdd',
            },
        };
        [$min, $max, $period] = Text::salary($o['salaire']['libelle'] ?? null);
        $place = $o['lieuTravail'] ?? [];
        $city = preg_replace('/^\d{2,3}\s*-\s*/', '', (string) ($place['libelle'] ?? ''));
        $city = $city !== '' ? mb_convert_case(mb_strtolower($city), MB_CASE_TITLE) : null;
        $duration = preg_match('/-\s*(\d+\s*(?:mois|jours?|ans?))/iu', (string) ($o['typeContratLibelle'] ?? ''), $m) ? mb_strtolower($m[1]) : null;

        $profile = array_filter([
            isset($o['experienceLibelle']) ? 'Expérience : ' . $o['experienceLibelle'] : null,
            ($f = array_filter(array_map(static fn ($x) => trim(($x['niveauLibelle'] ?? '') . ' ' . ($x['domaineLibelle'] ?? '')), (array) ($o['formations'] ?? []))))
                ? 'Formation : ' . implode(', ', $f) : null,
            ($c = array_filter(array_column((array) ($o['competences'] ?? []), 'libelle'))) ? "Compétences :\n• " . implode("\n• ", $c) : null,
            ($q = array_filter(array_column((array) ($o['qualitesProfessionnelles'] ?? []), 'libelle'))) ? 'Qualités : ' . implode(', ', $q) : null,
            ($p = array_filter(array_column((array) ($o['permis'] ?? []), 'libelle'))) ? 'Permis : ' . implode(', ', $p) : null,
        ]);
        $origin = $o['origineOffre']['urlOrigine'] ?? null;
        $detail = 'https://candidat.francetravail.fr/offres/recherche/detail/' . rawurlencode((string) $o['id']);
        $text = mb_strtolower(($o['intitule'] ?? '') . ' ' . $description);

        return array_filter([
            'external_id'      => (string) $o['id'],
            'title'            => self::cleanTitle((string) ($o['intitule'] ?? '')),
            'company_name'     => trim((string) ($o['entreprise']['nom'] ?? '')) ?: 'Entreprise non communiquée',
            'company_logo_url' => self::https($o['entreprise']['logo'] ?? null),
            'contract'         => $contract,
            'category'         => self::category((string) ($o['romeCode'] ?? '')),
            'work_mode'        => str_contains($text, '100% télétravail') || str_contains($text, 'full remote') ? 'remote'
                                  : (str_contains($text, 'télétravail') ? 'hybrid' : 'onsite'),
            'summary'          => Text::summary($description),
            'description'      => $description,
            'profile'          => $profile ? implode("\n", $profile) : null,
            'city'             => $city,
            'latitude'         => isset($place['latitude']) ? (float) $place['latitude'] : null,
            'longitude'        => isset($place['longitude']) ? (float) $place['longitude'] : null,
            'salary_min_cents' => $min,
            'salary_max_cents' => $max,
            'salary_period'    => $period,
            'salary_label'     => $min === null ? self::short($o['salaire']['libelle'] ?? $o['salaire']['commentaire'] ?? null, 80) : null,
            'duration'         => $duration,
            'url'              => self::https($origin) ?? $detail,
            'application_url'  => self::https($o['contact']['urlPostulation'] ?? null) ?? self::https($origin) ?? $detail,
            'cover_letter'     => 'optional',
            'notice'           => isset($o['dureeTravailLibelle']) ? self::short('Temps de travail : ' . $o['dureeTravailLibelle'], 255) : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** Domaine du code ROME → catégorie myClermont (config/imports.php › france_travail.rome_categories). */
    public static function category(string $rome): string
    {
        $map = (array) Config::get('imports.france_travail.rome_categories', []);
        // La correspondance la plus précise gagne : M1805 > M18 > M
        for ($len = min(5, strlen($rome)); $len > 0; $len--) {
            if (isset($map[substr($rome, 0, $len)])) {
                return $map[substr($rome, 0, $len)];
            }
        }

        return 'autre';
    }

    private function token(string $id, string $secret): string
    {
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('Identifiants France Travail manquants (FRANCE_TRAVAIL_CLIENT_ID / FRANCE_TRAVAIL_CLIENT_SECRET).');
        }
        $key = 'import:ft-token:' . sha1($id);
        if (is_string($cached = Cache::store()->get($key))) {
            return $cached;
        }
        $r = $this->http->request('POST', Config::get('imports.france_travail.token_url') ?: self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret, 'scope' => 'api_offresdemploiv2 o2dsoffre',
        ]));
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException("France Travail : authentification refusée (HTTP {$r['status']} " . ($data['error'] ?? '') . "). Vérifier les identifiants et l'abonnement à l'API.");
        }
        Cache::store()->set($key, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 1499) - 60));

        return $data['access_token'];
    }

    /** « Développeur / Développeuse web (H/F) » → « Développeur / Développeuse web ». */
    private static function cleanTitle(string $title): string
    {
        return trim((string) preg_replace('/\s*[(\[]\s*[HF]\s*\/\s*[HF]\s*[)\]]\s*/iu', ' ', $title));
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private static function short(?string $s, int $max): ?string
    {
        return $s === null || trim($s) === '' ? null : mb_substr(trim($s), 0, $max);
    }
}
