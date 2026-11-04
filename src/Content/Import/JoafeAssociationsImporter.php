<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Associations de Clermont-Ferrand (e3551d70) : annonces de création du Journal officiel (JOAFE, Licence Ouverte) → cible associations.
 * API ouverte sans clé (Opendatasoft DILA). Les associations dissoutes sont écartées.
 * Vie privée : le siège social est souvent le domicile d'un membre, donc ni adresse ni coordonnées, seulement la commune.
 *
 * Options : postal_codes (défaut 63000, 63100), since (défaut 2015-01-01)
 */
final class JoafeAssociationsImporter implements Importer
{
    private const URL = 'https://journal-officiel-datadila.opendatasoft.com/api/explore/v2.1/catalog/datasets/jo_associations/exports/json';

    /** Domaine JOAFE (début du libellé) → catégorie des filtres de l'app */
    private const DOMAINS = [
        'Sports' => 'Sport', 'chasse' => 'Sport',
        'culture' => 'Culture', 'préservation du patrimoine' => 'Culture', 'action socio-culturelle' => 'Culture', 'Tourisme' => 'Culture',
        'associations caritatives' => 'Solidarité', 'interventions sociales' => 'Solidarité', 'amicales' => 'Solidarité', 'aide à l\'emploi' => 'Solidarité',
        'services familiaux' => 'Solidarité', 'défense de droits' => 'Solidarité', 'logement' => 'Solidarité',
        'Environnement' => 'Environnement',
        'éducation formation' => 'Jeunesse',
        'santé' => 'Santé', 'services et établissements médico-sociaux' => 'Santé',
    ];

    /** Domaines Divers : mots de l'objet → catégorie */
    private const KEYWORDS = [
        'Sport' => '/\b(sport|football|rugby|basket|handball|tennis|judo|karat|danse|yoga|course|randonn|escalade|cyclis|vélo|natation|boxe|athl|gym)/iu',
        'Culture' => '/\b(culture|musique|musical|théâtre|art|artist|cinéma|festival|spectacle|patrimoine|littérat|livre|photograph|chant|concert)/iu',
        'Solidarité' => '/\b(solidarit|entraide|aide aux|humanitaire|précarit|insertion|réfugi|migrant|bénévol|caritati|accompagne)/iu',
        'Environnement' => '/\b(environnement|écolog|nature|climat|biodiversit|jardin|recycl|zéro déchet|animaux|animal)/iu',
        'Jeunesse' => '/\b(jeune|jeunesse|enfant|étudiant|élève|scolaire|parents d)/iu',
        'Santé' => '/\b(santé|malad|patient|soin|handicap|médic|bien-être|psych)/iu',
    ];

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 60, retries: 2, delayMs: 500))
    {
    }

    public function fetch(array $options): iterable
    {
        $codes = implode(',', array_map(static fn ($c) => '"' . preg_replace('/\D/', '', (string) $c) . '"', (array) ($options['postal_codes'] ?? ['63000', '63100'])));
        $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($options['since'] ?? '')) ? $options['since'] : '2015-01-01';
        $dissolved = [];
        foreach ((array) $this->http->getJson(self::URL . '?' . http_build_query([
            'where' => "codepostal_actuel in ({$codes}) and typeavis=\"Dissolution\"", 'select' => 'numero_rna'])) as $d) {
            $dissolved[(string) ($d['numero_rna'] ?? '')] = true;
        }
        $rows = (array) $this->http->getJson(self::URL . '?' . http_build_query([
            'where' => "codepostal_actuel in ({$codes}) and typeavis=\"Création\" and dateparution>=date'{$since}'",
            'select' => 'numero_rna,titre,objet,domaine_activite_libelle_categorise,commune_actuelle,siteweb,datedeclaration',
        ]));
        foreach ($rows as $row) {
            if (is_array($row) && !isset($dissolved[(string) ($row['numero_rna'] ?? '')]) && ($item = self::map($row)) !== null) {
                yield $item;
            }
        }
    }

    public static function map(array $r): ?array
    {
        $rna = trim((string) ($r['numero_rna'] ?? ''));
        $name = self::name((string) ($r['titre'] ?? ''));
        $objet = trim(preg_replace('/\s+/u', ' ', (string) ($r['objet'] ?? '')), " ;.\t");
        if ($rna === '' || $name === '' || mb_strlen($objet) < 15) {
            return null;
        }
        $domain = (string) (((array) ($r['domaine_activite_libelle_categorise'] ?? []))[0] ?? '');
        [$main, $sub] = array_pad(explode('/', $domain, 2), 2, '');
        $category = null;
        foreach (self::DOMAINS as $prefix => $cat) {
            if (str_starts_with(mb_strtolower($main), mb_strtolower($prefix))) {
                $category = $cat;
                break;
            }
        }
        if ($category === null) {
            foreach (self::KEYWORDS as $cat => $re) {
                if (preg_match($re, $name . ' ' . $objet)) {
                    $category = $cat;
                    break;
                }
            }
        }
        $activities = array_values(array_filter(array_map(static fn ($a) => self::upperFirst(trim($a)), preg_split('/,\s*|\s*\(|\)/u', $sub) ?: []),
            static fn ($a) => $a !== '' && mb_strlen($a) <= 40));
        $site = (string) ($r['siteweb'] ?? '');
        if ($site !== '' && !preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }

        return [
            'external_id' => $rna,
            'name' => $name,
            'category' => $category ?? 'Vie locale',
            'district' => (string) ($r['commune_actuelle'] ?? 'Clermont-Ferrand') ?: 'Clermont-Ferrand',
            'description' => self::upperFirst($objet) . '.',
            'activities' => array_slice(array_unique($activities), 0, 4),
            'website_url' => filter_var($site, FILTER_VALIDATE_URL) && str_starts_with(strtolower($site), 'https://') ? $site : null,
            'created_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['datedeclaration'] ?? '')) ? $r['datedeclaration'] : null,
        ];
    }

    /** « LES AMIS DU VIEUX CLERMONT. » → « Les Amis du Vieux Clermont » ; un nom déjà en casse mixte est gardé */
    private static function name(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t), " .\t");
        if ($t === '' || mb_strtoupper($t) !== $t) {
            return mb_substr($t, 0, 200);
        }
        $small = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'à', 'au', 'aux', 'pour', 'sur', 'par', 'd', 'l'];
        $words = preg_split('/(\s+|-|\')/u', mb_strtolower($t), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($words as $i => $w) {
            if ($w !== '' && !preg_match('/^(\s+|-|\')$/u', $w) && ($i === 0 || !in_array($w, $small, true))) {
                // Sigles courts sans voyelle (« ASM », « CHU ») gardés en capitales
                $words[$i] = preg_match('/^[bcdfghjklmnpqrstvwxz]{2,5}$/u', $w) ? mb_strtoupper($w) : self::upperFirst($w);
            }
        }

        return mb_substr(implode('', $words), 0, 200);
    }

    private static function upperFirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
