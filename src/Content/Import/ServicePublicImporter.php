<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Services publics de Clermont-Ferrand (Annuaire de l'administration, Service-Public.fr, Licence Ouverte 2.0) → cible services.
 * API ouverte sans clé : https://api-lannuaire.service-public.fr (jeu « api-lannuaire-administration »).
 * Seuls les guichets utiles aux habitants sont repris (TYPES) ; « Contacter » ouvre la fiche officielle Service-Public.fr.
 *
 * Options : commune (code INSEE, défaut 63113 = Clermont-Ferrand)
 */
final class ServicePublicImporter implements Importer
{
    private const URL = 'https://api-lannuaire.service-public.fr/api/explore/v2.1/catalog/datasets/api-lannuaire-administration/records';

    /** type_service_local → [libellé court, spécialités] */
    private const TYPES = [
        'mairie' => ['Mairie', ['État civil', 'Papiers d\'identité', 'Élections']],
        'epci' => ['Métropole', ['Déchets', 'Eau', 'Voirie']],
        'prefecture' => ['Préfecture', ['Titres de séjour', 'Permis', 'Cartes grises']],
        'point_accueil_numerique' => ['Démarches en ligne', ['Aide numérique', 'ANTS']],
        'france_services' => ['France services', ['Démarches', 'Accompagnement', 'Numérique']],
        'caf' => ['Caf', ['Allocations', 'APL', 'Prime d\'activité']],
        'cpam' => ['Assurance maladie', ['Carte Vitale', 'Remboursements', 'Droits santé']],
        'carsat' => ['Retraite', ['Retraite', 'Action sociale']],
        'msa' => ['MSA', ['Protection sociale agricole']],
        'urssaf' => ['Urssaf', ['Cotisations', 'Auto-entrepreneurs']],
        'france_travail' => ['France Travail', ['Recherche d\'emploi', 'Indemnisation']],
        'mission_locale' => ['Mission locale', ['16-25 ans', 'Emploi', 'Formation']],
        'apec' => ['Apec', ['Emploi cadres']],
        'cij' => ['Info Jeunes', ['Jeunes', 'Logement', 'Jobs']],
        'crous' => ['Crous', ['Bourses', 'Logement étudiant', 'Restauration']],
        'cio' => ['Orientation', ['Orientation scolaire']],
        'ccas' => ['CCAS', ['Aide sociale', 'Seniors', 'Solidarité']],
        'mds' => ['Solidarités', ['Aide sociale', 'Enfance', 'Insertion']],
        'pmi' => ['PMI', ['Petite enfance', 'Suivi des grossesses']],
        'clic' => ['Seniors', ['Personnes âgées', 'Maintien à domicile']],
        'maison_handicapees' => ['MDPH', ['Handicap', 'AAH', 'Carte mobilité']],
        'adil' => ['Logement', ['Droit du logement', 'Location', 'Achat']],
        'fr_renov' => ['France Rénov\'', ['Rénovation énergétique', 'Aides']],
        'anah' => ['Anah', ['Aides habitat']],
        'cidf' => ['Droits des femmes', ['Accès au droit', 'Violences']],
        'mjd' => ['Accès au droit', ['Conseil juridique gratuit']],
        'bav' => ['Aide aux victimes', ['Victimes', 'Accompagnement']],
        'pcb' => ['Conseil budget', ['Budget', 'Surendettement']],
        'sip' => ['Impôts', ['Impôt sur le revenu', 'Taxe foncière']],
        'commissariat_police' => ['Police', ['Plaintes', 'Sécurité']],
        'police_municipale' => ['Police municipale', ['Tranquillité', 'Objets trouvés']],
        'tgi' => ['Tribunal', ['Justice']],
        'chu' => ['Hôpital', ['Urgences', 'Consultations']],
        'bibli' => ['Bibliothèque', ['Prêt', 'Culture']],
        'ofii' => ['OFII', ['Intégration', 'Immigration']],
    ];

    private const DAYS = ['Lundi' => 'lun.', 'Mardi' => 'mar.', 'Mercredi' => 'mer.', 'Jeudi' => 'jeu.', 'Vendredi' => 'ven.', 'Samedi' => 'sam.', 'Dimanche' => 'dim.'];

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 30, retries: 2, delayMs: 300))
    {
    }

    public function fetch(array $options): iterable
    {
        $commune = (string) ($options['commune'] ?? '63113');
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $r = $this->http->getJson(self::URL . '?' . http_build_query(['where' => 'code_insee_commune="' . $commune . '"', 'limit' => 100, 'offset' => $offset]));
            $rows = (array) ($r['results'] ?? []);
            foreach ($rows as $row) {
                if (is_array($row) && ($item = self::map($row)) !== null) {
                    yield $item;
                }
            }
            if (count($rows) < 100) {
                break;
            }
        }
    }

    public static function map(array $o): ?array
    {
        $type = (self::json($o['pivot'])[0]['type_service_local'] ?? null);
        if (!is_string($type) || !isset(self::TYPES[$type]) || empty($o['id']) || empty($o['nom'])) {
            return null;
        }
        $addr = self::json($o['adresse'])[0] ?? [];
        if (!is_numeric($addr['latitude'] ?? null) || !is_numeric($addr['longitude'] ?? null)) {
            return null;
        }
        [$label, $tags] = self::TYPES[$type];
        $phones = self::json($o['telephone']);
        $site = self::json($o['site_internet'])[0]['valeur'] ?? null;
        $street = trim(implode(', ', array_filter([$addr['numero_voie'] ?? '', $addr['complement1'] ?? ''])));
        $mission = str_replace("\r", "", (string) Text::fromHtml(is_string($o['mission'] ?? null) ? $o['mission'] : null)) ?: null;

        return [
            'external_id' => (string) $o['id'],
            'category'    => 'services-publics',
            'name'        => (string) $o['nom'],
            'summary'     => $label . ($street !== '' ? ' · ' . $street : ''),
            'description' => trim(($mission ? $mission . "\n\n" : '') . ($street !== '' ? "Adresse : {$street}, " . ($addr['code_postal'] ?? '') . ' ' . ($addr['nom_commune'] ?? '') . "\n" : '')
                             . implode("\n", array_map(static fn ($p) => 'Tél. ' . $p['valeur'] . (!empty($p['description']) ? ' (' . $p['description'] . ')' : ''), array_filter($phones, static fn ($p) => !empty($p['valeur']))))),
            'services'    => $tags,
            'keywords'    => array_merge([$label, 'service public', 'administration'], $tags),
            'hours'       => self::hours(self::json($o['plage_ouverture'])),
            'price_label' => 'Service public',
            'city'        => (string) ($addr['nom_commune'] ?? 'Clermont-Ferrand'),
            'latitude'    => (float) $addr['latitude'],
            'longitude'   => (float) $addr['longitude'],
            'phone'       => isset($phones[0]['valeur']) ? mb_substr((string) $phones[0]['valeur'], 0, 30) : null,
            'website_url' => is_string($site) && str_starts_with($site, 'https://') ? $site : null,
            'url'         => (string) ($o['url_service_public'] ?? ''),
            'contact_email' => is_string($o['adresse_courriel'] ?? null) ? $o['adresse_courriel'] : null,
            'notice'      => 'Informations officielles Service-Public.fr (Licence Ouverte). Vérifie les horaires avant de te déplacer.',
        ];
    }

    /** « lun.–ven. 8:00–12:00, 13:30–17:00 ; sam. 9:00–12:00 », 120 caractères au plus */
    private static function hours(array $slots): ?string
    {
        $parts = [];
        foreach ($slots as $s) {
            $from = self::DAYS[$s['nom_jour_debut'] ?? ''] ?? null;
            $to = self::DAYS[$s['nom_jour_fin'] ?? ''] ?? null;
            if ($from === null) {
                continue;
            }
            $t = static fn (?string $a, ?string $b) => $a && $b ? substr($a, 0, 5) . '–' . substr($b, 0, 5) : null;
            $times = array_filter([$t($s['valeur_heure_debut_1'] ?? null, $s['valeur_heure_fin_1'] ?? null), $t($s['valeur_heure_debut_2'] ?? null, $s['valeur_heure_fin_2'] ?? null)]);
            if ($times !== []) {
                $parts[] = ($to !== null && $to !== $from ? "{$from}–{$to}" : $from) . ' ' . implode(', ', $times);
            }
        }
        $label = implode(' ; ', $parts);

        return $label === '' ? null : (mb_strlen($label) > 120 ? mb_substr($label, 0, 119) . '…' : $label);
    }

    private static function json(mixed $v): array
    {
        return is_string($v) ? (json_decode($v, true) ?: []) : (is_array($v) ? $v : []);
    }
}
