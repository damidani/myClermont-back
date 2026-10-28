<?php

declare(strict_types=1);

use App\Content\Import\FranceTravailImporter;
use App\Content\Import\HttpClient;
use App\Content\Import\JsonLdImporter;
use App\Content\Import\PolirisImporter;
use App\Content\Import\Text;
use App\Core\Request;

/*
 * Importeurs automatiques (emploi, immobilier) et améliorations HTTP communes, sans réseau :
 * HttpClient::fake() remplace les appels HTTP.
 */

/** Ligne Poliris : valeurs placées à leur numéro de colonne (1 = première). */
function poliris_line(array $values): string
{
    $row = array_fill(0, 180, '');
    foreach ($values as $col => $v) {
        $row[$col - 1] = $v;
    }

    return '"' . implode('"!#"', $row) . '"';
}

return [
    'salaire France Travail : mensuel avec fourchette' => function () {
        assert_same([200000, 250000, 'month'], Text::salary('Mensuel de 2000.00 Euros à 2500.00 Euros sur 12 mois'));
        assert_same([3500000, null, 'year'], Text::salary('Annuel de 35000 Euros'));
        assert_same([1188, null, 'hour'], Text::salary('Horaire de 11.88 Euros'));
        assert_same([null, null, null], Text::salary('Selon profil'));
    },

    'montants : formats français' => function () {
        assert_same(125050, Text::cents('1 250,50'));
        assert_same(125050, Text::cents('1.250,50'));
        assert_same(65000, Text::cents('650'));
        assert_same(null, Text::cents('0'));
    },

    'HTML → texte lisible' => function () {
        assert_same("Le poste\n\n• Accueil\n• Caisse", Text::fromHtml('<p>Le poste</p><ul><li>Accueil</li><li>Caisse</li></ul>'));
    },

    'France Travail : conversion d\'une offre' => function () {
        $item = FranceTravailImporter::map([
            'id' => '183XKPL', 'intitule' => 'Développeur web (H/F)', 'description' => "Vous développerez nos applis.\nTélétravail 2 j/semaine.",
            'romeCode' => 'M1805', 'typeContrat' => 'CDD', 'typeContratLibelle' => 'CDD - 6 Mois', 'natureContrat' => 'Contrat travail',
            'lieuTravail' => ['libelle' => '63 - CLERMONT FERRAND', 'latitude' => 45.77, 'longitude' => 3.08],
            'entreprise' => ['nom' => 'ACME'], 'salaire' => ['libelle' => 'Mensuel de 2200 Euros'],
            'competences' => [['libelle' => 'PHP']], 'origineOffre' => ['urlOrigine' => 'https://candidat.francetravail.fr/offres/recherche/detail/183XKPL'],
        ]);
        assert_same('183XKPL', $item['external_id']);
        assert_same('Développeur web', $item['title']);
        assert_same('cdd', $item['contract']);
        assert_same('tech', $item['category']);
        assert_same('hybrid', $item['work_mode']);
        assert_same('6 mois', $item['duration']);
        assert_same(220000, $item['salary_min_cents']);
        assert_same('month', $item['salary_period']);
        assert_true(str_contains($item['profile'], '• PHP'));
    },

    'France Travail : alternance et intérim reconnus' => function () {
        assert_same('alternance', FranceTravailImporter::map(['id' => '1', 'intitule' => 'x', 'typeContrat' => 'CDD', 'alternance' => true])['contract']);
        assert_same('interim', FranceTravailImporter::map(['id' => '2', 'intitule' => 'x', 'typeContrat' => 'MIS'])['contract']);
        assert_same('hotellerie', FranceTravailImporter::category('G1602'));
        assert_same('culture', FranceTravailImporter::category('G1201'));
        assert_same('autre', FranceTravailImporter::category(''));
    },

    'France Travail : jeton OAuth2 puis pagination' => function () {
        $calls = [];
        HttpClient::fake(function (string $method, string $url) use (&$calls) {
            $calls[] = $method . ' ' . strtok($url, '?');
            if ($method === 'POST') {
                return [200, [], json_encode(['access_token' => 'tok', 'expires_in' => 1499])];
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            [$start] = explode('-', $q['range']);
            $offers = $start === '0'
                ? array_map(static fn ($i) => ['id' => "A{$i}", 'intitule' => 'Offre', 'typeContrat' => 'CDI'], range(1, 150))
                : [['id' => 'B1', 'intitule' => 'Offre', 'typeContrat' => 'CDI']];
            return [206, ['content-range' => "offres {$start}-" . ($start + 149) . '/151'], json_encode(['resultats' => $offers])];
        });
        try {
            $items = iterator_to_array((new FranceTravailImporter())->fetch(['client_id' => 'id', 'client_secret' => 's']), false);
        } finally {
            HttpClient::fake(null);
        }
        assert_same(151, count($items));
        assert_same(3, count($calls), 'un jeton + deux pages');
    },

    'Poliris : location en zone, hors zone et bureaux ignorés' => function () {
        $csv = implode("\r\n", [
            poliris_line([2 => 'REF-1', 3 => 'Location', 4 => 'Appartement', 5 => '63000', 6 => 'CLERMONT-FERRAND', 9 => 'Jaude',
                          11 => '650', 13 => 'OUI', 16 => '45,5', 18 => '2', 19 => '1', 21 => "Beau T2\nlumineux", 22 => '01/09/2026',
                          23 => '40', 26 => 'OUI', 42 => 'OUI', 85 => 'https://cdn.agence.fr/1.jpg', 177 => 'C']),
            poliris_line([2 => 'REF-2', 3 => 'Vente', 4 => 'Maison', 5 => '75011', 6 => 'PARIS', 11 => '500000']),
            poliris_line([2 => 'REF-3', 3 => 'Location', 4 => 'Bureaux', 5 => '63000', 6 => 'CLERMONT', 11 => '900']),
        ]);
        $file = tempnam(sys_get_temp_dir(), 'pol');
        file_put_contents($file, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
        try {
            $items = iterator_to_array((new PolirisImporter())->fetch(['url' => 'file://' . $file, 'geocode' => false]), false);
        } finally {
            unlink($file);
        }
        assert_same(1, count($items));
        $i = $items[0];
        assert_same('rent', $i['transaction']);
        assert_same('apartment', $i['property_type']);
        assert_same(65000, $i['price_cents']);
        assert_same(4000, $i['charges_cents']);
        assert_same(true, $i['charges_included']);
        assert_same(45.5, $i['surface_m2']);
        assert_same('2026-09-01', $i['available_from']);
        assert_same('C', $i['energy_class']);
        assert_same(['elevator'], $i['features']);
        assert_same('Clermont-Ferrand', $i['city']);
        assert_same(['https://cdn.agence.fr/1.jpg'], $i['images']);
        assert_same("Beau T2\nlumineux", $i['description']);
        assert_true(str_starts_with($i['title'], 'Appartement 2 pièces 46 m²'));
    },

    'JSON-LD : JobPosting avec @graph et salaire structuré' => function () {
        $html = '<html><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"x"},'
              . '{"@type":"JobPosting","title":"Stage marketing","identifier":{"value":"JOB-9"},"description":"<p>Super stage</p>",'
              . '"hiringOrganization":{"@type":"Organization","name":"Volvic"},"employmentType":"INTERN",'
              . '"jobLocation":{"@type":"Place","address":{"addressLocality":"Clermont-Ferrand"}},'
              . '"baseSalary":{"@type":"MonetaryAmount","value":{"minValue":700,"maxValue":900,"unitText":"MONTH"}},'
              . '"validThrough":"2026-12-31T00:00:00+01:00","url":"https://jobs.exemple.fr/9"}]}</script></html>';
        $nodes = JsonLdImporter::extract($html);
        $job = JsonLdImporter::mapJob($nodes[1], 'https://jobs.exemple.fr/9');
        assert_same('JOB-9', $job['external_id']);
        assert_same('stage', $job['contract']);
        assert_same('Volvic', $job['company_name']);
        assert_same([70000, 90000, 'month'], [$job['salary_min_cents'], $job['salary_max_cents'], $job['salary_period']]);
        assert_same('2026-12-30', $job['expires_at']);
    },

    'JSON-LD : robot qui respecte robots.txt et suit les fiches' => function () {
        $pages = [
            'https://agence.exemple.fr/robots.txt' => "User-agent: *\nDisallow: /prive/\n",
            'https://agence.exemple.fr/biens' => '<a href="/biens/1">1</a><a href="/prive/2">2</a><a href="https://autre.fr/biens/3">3</a>',
            'https://agence.exemple.fr/biens/1' => '<script type="application/ld+json">{"@type":"RealEstateListing","name":"T3 Location Jaude",'
                . '"offers":{"@type":"Offer","price":"820","businessFunction":"http://purl.org/goodrelations/v1#LeaseOut"},'
                . '"about":{"@type":"Apartment","numberOfRooms":3,"floorSize":{"value":"64"},"address":{"addressLocality":"Clermont-Ferrand","postalCode":"63000"},'
                . '"geo":{"latitude":45.777123,"longitude":3.081234}}}</script>',
        ];
        $asked = [];
        HttpClient::fake(function ($m, string $url) use ($pages, &$asked) {
            $asked[] = $url;
            return isset($pages[$url]) ? [200, [], $pages[$url]] : [404, [], ''];
        });
        try {
            $items = iterator_to_array((new JsonLdImporter())->fetch(['kind' => 'housing', 'urls' => ['https://agence.exemple.fr/biens'], 'follow' => '#/biens/\d+$#']), false);
        } finally {
            HttpClient::fake(null);
        }
        assert_same(1, count($items));
        assert_same('rent', $items[0]['transaction']);
        assert_same(82000, $items[0]['price_cents']);
        assert_same(64.0, $items[0]['surface_m2']);
        assert_same(45.777, $items[0]['latitude'], 'position arrondie');
        assert_true(!in_array('https://agence.exemple.fr/prive/2', $asked, true), 'robots.txt respecté');
        assert_true(!in_array('https://autre.fr/biens/3', $asked, true), 'reste sur le site');
    },

    'HTTP : HTTPS obligatoire pour les imports' => function () {
        try {
            (new HttpClient())->request('GET', 'http://exemple.fr');
            throw new LogicException('aurait dû échouer');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'HTTPS'));
        }
    },

    'API : ETag automatique et 304' => function () {
        $first = app()->handle(new Request('GET', '/v1/health', '', [], '', '127.0.0.1'));
        $etag = $first->headers['ETag'] ?? null;
        assert_true($etag !== null && str_starts_with($etag, 'W/"'));
        assert_same('private, no-cache', $first->headers['Cache-Control']);
        assert_true(isset($first->headers['X-Request-Id'], $first->headers['Server-Timing']));
    },

    'API : X-Request-Id fourni par l\'app repris' => function () {
        $res = app()->handle(new Request('GET', '/v1/nope', '', ['x-request-id' => 'ios-abc12345'], '', '127.0.0.1'));
        assert_same('ios-abc12345', $res->headers['X-Request-Id']);
        assert_true(!isset($res->headers['ETag']), 'pas d\'ETag sur une erreur');
    },
];
