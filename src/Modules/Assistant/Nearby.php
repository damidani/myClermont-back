<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Content\Import\HttpClient;
use App\Core\Database;
use App\Modules\Places\MapController;

/**
 * Recherche de proximité de Clermont IA (demandes iOS 54d34637, afe694b8) : « le coiffeur le plus proche », « une pharmacie »,
 * « où manger près de moi »… Les commerces viennent d'OpenStreetMap, importés chaque semaine dans osm_pois
 * (l'API Overpass publique est trop souvent saturée pour être appelée en direct). Les lieux de la base passent en premier.
 */
final class Nearby
{
    /** Place de Jaude : point de départ sans position */
    public const JAUDE = [45.7772, 3.0870];

    /**
     * clé => [libellé, pluriel, icône SF Symbol, MapCategory, sélecteurs Overpass, mots de la question (sans accents)]
     * Ordre = priorité de détection (« pizzeria » avant « restaurant »).
     */
    public const KINDS = [
        'pharmacie'    => ['pharmacie', 'pharmacies', 'cross.case.fill', 'sante', ['nwr[amenity=pharmacy]'], 'pharmac'],
        'hopital'      => ['hôpital', 'hôpitaux', 'cross.case.fill', 'sante', ['nwr[amenity=hospital]'], 'hopita|urgences|clinique'],
        'dentiste'     => ['dentiste', 'dentistes', 'cross.fill', 'sante', ['nwr[amenity=dentist]', 'nwr[healthcare=dentist]'], 'dentist'],
        'medecin'      => ['médecin', 'médecins', 'stethoscope', 'sante', ['nwr[amenity=doctors]', 'nwr[healthcare=doctor]'], 'medecin|generaliste|docteur'],
        'veterinaire'  => ['vétérinaire', 'vétérinaires', 'pawprint.fill', 'sante', ['nwr[amenity=veterinary]'], 'veterinaire|\bveto\b'],
        'opticien'     => ['opticien', 'opticiens', 'eyeglasses', 'sante', ['nwr[shop=optician]'], 'opticien|lunettes'],
        'boulangerie'  => ['boulangerie', 'boulangeries', 'birthday.cake.fill', 'boulangerie', ['nwr[shop=bakery]', 'nwr[shop=pastry]'], 'boulang|patisser|\bpain\b|croissant|viennoiser'],
        'pizzeria'     => ['pizzeria', 'pizzerias', 'fork.knife', 'restaurant', [], 'pizz'],
        'kebab'        => ['kebab ou burger', 'kebabs et burgers', 'takeoutbag.and.cup.and.straw.fill', 'fastFood', [], 'kebab|burger|tacos'],
        'japonais'     => ['restaurant japonais', 'restaurants japonais', 'fork.knife', 'restaurant', [], 'japonais|sushi|ramen'],
        'brunch'       => ['brunch', 'brunchs', 'cup.and.saucer.fill', 'cafe', [], 'brunch'],
        'fastfood'     => ['restauration rapide', 'adresses sur le pouce', 'takeoutbag.and.cup.and.straw.fill', 'fastFood', ['nwr[amenity=fast_food]'], 'fast.?food|sandwich|sur le pouce|snack'],
        'restaurant'   => ['restaurant', 'restaurants', 'fork.knife', 'restaurant', ['nwr[amenity=restaurant]'], 'restau|\bresto|diner|dejeuner|manger|bouffer'],
        'cafe'         => ['café', 'cafés', 'cup.and.saucer.fill', 'cafe', ['nwr[amenity=cafe]'], '\bcafes?\b|coffee'],
        'bar'          => ['bar', 'bars', 'wineglass.fill', 'cafe', ['nwr[amenity=bar]', 'nwr[amenity=pub]'], '\bbars?\b|\bpubs?\b|boire un verre|une biere|un verre'],
        'coiffeur'     => ['coiffeur', 'coiffeurs', 'scissors', 'shopping', ['nwr[shop=hairdresser]'], 'coiff|barbier'],
        'supermarche'  => ['supermarché', 'supermarchés', 'cart.fill', 'courses', ['nwr[shop=supermarket]', 'nwr[shop=convenience]'], 'supermarche|superette|supérette|epicerie|faire (les|des|mes) courses'],
        'fleuriste'    => ['fleuriste', 'fleuristes', 'leaf.fill', 'shopping', ['nwr[shop=florist]'], 'fleur'],
        'librairie'    => ['librairie', 'librairies', 'book.fill', 'shopping', ['nwr[shop=books]'], 'librair|\blivres?\b'],
        'tabac'        => ['bureau de tabac', 'bureaux de tabac', 'newspaper.fill', 'shopping', ['nwr[shop=tobacco]'], 'tabac|buraliste'],
        'distributeur' => ['distributeur de billets', 'distributeurs de billets', 'banknote.fill', 'services', ['nwr[amenity=atm]', 'nwr[amenity=bank][atm=yes]'], 'distributeur|retirer (de l.)?argent|\bdab\b|billets'],
        'banque'       => ['banque', 'banques', 'building.columns.fill', 'services', ['nwr[amenity=bank]'], '\bbanques?\b'],
        'poste'        => ['bureau de poste', 'bureaux de poste', 'envelope.fill', 'services', ['nwr[amenity=post_office]'], 'bureau de poste|la poste|envoyer un (colis|courrier)'],
        'laverie'      => ['laverie', 'laveries', 'washer.fill', 'services', ['nwr[shop=laundry]', 'nwr[shop=dry_cleaning]'], 'laverie|pressing'],
        'garage'       => ['garage', 'garages', 'wrench.and.screwdriver.fill', 'services', ['nwr[shop=car_repair]'], 'garage|garagiste|mecanicien'],
        'serrurier'    => ['serrurier', 'serruriers', 'key.fill', 'services', ['nwr[craft=locksmith]', 'nwr[shop=locksmith]'], 'serrur'],
        'toilettes'    => ['toilettes publiques', 'toilettes publiques', 'toilet.fill', 'services', ['nwr[amenity=toilets][access!=private][access!=customers]'], 'toilettes|\bwc\b'],
        'parking'      => ['parking', 'parkings', 'parkingsign.circle.fill', 'mobilite', ['nwr[amenity=parking][access!=private][access!=customers][name]'], 'parking|me garer|stationner'],
        'recharge'     => ['borne de recharge', 'bornes de recharge', 'bolt.car.fill', 'mobilite', ['nwr[amenity=charging_station]'], 'borne|recharger? (ma|une|la) voiture'],
        'sport'        => ['salle de sport', 'salles de sport', 'dumbbell.fill', 'sport', ['nwr[leisure=fitness_centre]'], 'salle de (sport|muscu)|fitness|musculation'],
        'piscine'      => ['piscine', 'piscines', 'figure.pool.swim', 'sport', ['nwr[leisure=sports_centre][sport=swimming]', 'nwr[leisure=water_park]'], 'piscine|nager|natation'],
        'cinema'       => ['cinéma', 'cinémas', 'film.fill', 'culture', ['nwr[amenity=cinema]'], 'cinema|\bcine\b|voir un film'],
        'musee'        => ['musée', 'musées', 'building.columns.fill', 'culture', ['nwr[tourism=museum]'], 'musee'],
        'hotel'        => ['hôtel', 'hôtels', 'bed.double.fill', 'hebergement', ['nwr[tourism=hotel]'], 'hotel|dormir|hebergement'],
    ];

    /** Sous-ensembles d'un genre importé : cuisine OSM */
    private const CUISINE = ['pizzeria' => ['restaurant', 'pizza'], 'kebab' => ['fastfood', 'kebab|burger|tacos'], 'japonais' => ['restaurant', 'japanese|sushi|ramen'],
                             'brunch' => ['cafe', 'brunch']];

    /** « où manger » : restaurants, restauration rapide et cafés mélangés selon l'heure */
    private const EAT = '/\b(ou|quoi) (manger|dejeuner|diner|bruncher|grignoter)\b|\b(manger|grignoter) (un (bout|morceau)|pres|autour|vite|ce midi|ce soir)\b|\bj.ai faim\b/';

    /** Questions qui relèvent d'autres rubriques même si un mot ressemble à un commerce */
    private const NOT_NEARBY = '/\b(emploi|job|offre|recrut|stage|alternance|logement|appart|colocation|louer|bon plan|promo|reduction|concert|expo|evenement|soiree|spectacle|festival|meteo|horaires? d)/';

    private const MIRRORS = ['https://overpass-api.de/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];

    /** Zone importée (sud, ouest, nord, est) : la métropole avec 8 km de marge autour du centre */
    private const BBOX = [45.68, 2.95, 45.87, 3.25];

    /** Genre recherché dans la question (« eat » pour « où manger »), ou null */
    public static function detect(string $question): ?string
    {
        $q = self::plain($question);
        if (preg_match(self::NOT_NEARBY, $q)) {
            return null;
        }
        if (preg_match(self::EAT, $q)) {
            return 'eat';
        }
        foreach (self::KINDS as $kind => $k) {
            if (preg_match('/' . $k[5] . '/u', $q)) {
                return $kind;
            }
        }

        return null;
    }

    /** Genre d'une requête libre du modèle (« coiffeur », « pharmacie de garde ») */
    public static function kindOf(string $query): ?string
    {
        return self::detect($query) ?? (isset(self::KINDS[$k = self::plain($query)]) ? $k : null);
    }

    /**
     * Lieux les plus proches : 3 km, élargi à 8 km si rien ; les lieux de la base qui correspondent d'abord.
     *
     * @return list<array{type: string, spot: array, distance: int, kind: string, opening_hours: ?string, address: string}>
     */
    public static function search(string $kind, float $lat, float $lon, int $limit, ?string $nameQuery = null): array
    {
        $kinds = $kind === 'eat' ? self::eatKinds() : [$kind];
        foreach ([3000, 8000] as $radius) {
            $found = array_merge(self::fromPlaces($kinds, $lat, $lon, $radius, $nameQuery), self::fromOsm($kinds, $lat, $lon, $radius, $limit * 3, $nameQuery));
            if ($found !== []) {
                break;
            }
        }
        // Base d'abord (partenaires en tête), puis OSM par distance ; un lieu présent des deux côtés n'apparaît qu'une fois
        $out = [];
        foreach ($found as $r) {
            foreach ($out as $o) {
                if (self::same($o, $r)) {
                    continue 2;
                }
            }
            $out[] = $r;
        }
        usort($out, static fn ($a, $b) => [$a['source'] !== 'place' || !$a['spot']['is_partner'], $a['source'] !== 'place', $a['distance']]
                                         <=> [$b['source'] !== 'place' || !$b['spot']['is_partner'], $b['source'] !== 'place', $b['distance']]);
        // Base au plus pour moitié, pour garder les plus proches
        $base = array_slice(array_values(array_filter($out, static fn ($r) => $r['source'] === 'place')), 0, intdiv($limit, 2));
        $osm = array_values(array_filter($out, static fn ($r) => $r['source'] === 'osm'));
        if ($kind === 'eat') {
            // « où manger » : les genres du moment en alternance, chacun par distance
            $byKind = [];
            foreach ($osm as $r) {
                $byKind[$r['kind']][] = $r;
            }
            $osm = [];
            for ($i = 0; count($osm) < $limit && $byKind !== []; $i++) {
                foreach ($byKind as $k => $rows) {
                    isset($rows[$i]) ? $osm[] = $rows[$i] : null;
                }
                $byKind = array_filter($byKind, static fn ($rows) => isset($rows[$i + 1]));
            }
        }
        $list = array_slice(array_merge($base, $osm), 0, $limit);
        if ($kind !== 'eat') {
            usort($list, static fn ($a, $b) => [!$a['spot']['is_partner'], $a['distance']] <=> [!$b['spot']['is_partner'], $b['distance']]);
        }

        return $list;
    }

    /**
     * Réponse complète sans passer par le modèle : texte factuel, cartes place (4 en chat, 8 sur la carte) et une carte mapFilter.
     *
     * @return array{text: string, cards: list<array>, follow_ups: list<string>, results: list<array>}
     */
    public static function reply(string $kind, float $lat, float $lon, bool $located, string $screen, string $question): array
    {
        $wantOpen = (bool) preg_match('/\bouvert/u', self::plain($question));
        $results = self::search($kind, $lat, $lon, $screen === 'map' ? 8 : 4);
        if ($wantOpen) {
            // « une boulangerie ouverte » : celles ouvertes d'après OSM d'abord, les horaires inconnus ensuite, les fermées jamais
            $results = array_values(array_filter($results, static fn ($r) => self::openNow($r['opening_hours']) !== false));
            usort($results, static fn ($a, $b) => [self::openNow($a['opening_hours']) !== true, $a['distance']] <=> [self::openNow($b['opening_hours']) !== true, $b['distance']]);
        }
        [$label, $plural] = $kind === 'eat' ? ['adresse pour manger', 'adresses pour manger'] : self::KINDS[$kind];
        $where = $located ? 'de toi' : 'de la place de Jaude';
        $mapCategory = $kind === 'eat' ? 'restaurant' : self::KINDS[$kind][3];
        $filter = self::mapFilterCard($mapCategory);
        if ($results === []) {
            return ['text' => 'Je n\'ai trouvé aucun' . (self::feminine($label) ? 'e ' : ' ') . $label . ($wantOpen ? ' ouvert' . (self::feminine($label) ? 'e' : '') . ' en ce moment' : '') . ' autour ' . $where . ($located ? '' : ' (je n\'ai pas accès à ta position)') . '.',
                    'cards' => [$filter], 'follow_ups' => self::followUps($kind), 'results' => []];
        }
        $first = $results[0];
        $the = match (true) {
            $kind === 'eat' => 'L\'adresse la plus proche',
            self::feminine($label) => (self::startsWithVowel($label) ? 'L\'' : 'La ') . $label . ' la plus proche',
            default => (self::startsWithVowel($label) ? 'L\'' : 'Le ') . $label . ' le plus proche',
        };
        $text = $the . ', c\'est ' . $first['spot']['name'] . ', à ' . self::distanceLabel($first['distance']) . ' ' . $where . ' (' . self::travel($first['distance']) . ').';
        if (!$located) {
            $text .= ' Je n\'ai pas accès à ta position, je suis donc parti de la place de Jaude.';
        }
        if ($first['address'] !== '') {
            $text .= ' Adresse : ' . $first['address'] . '.';
        }
        $open = self::openNow($first['opening_hours']);
        if ($open !== null) {
            $text .= $open ? ' C\'est ouvert en ce moment d\'après OpenStreetMap.' : ' D\'après OpenStreetMap, c\'est fermé en ce moment.';
        }
        if (count($results) > 1) {
            $text .= ' J\'ai aussi trouvé ' . (count($results) - 1) . ' autre' . (count($results) > 2 ? 's' : '') . ' ' . (count($results) > 2 ? $plural : $label) . ' à proximité.';
        }
        if ($kind === 'hopital') {
            $text .= ' En cas d\'urgence vitale, appelle le 15 ou le 112.';
        }
        if ($kind === 'pharmacie') {
            $text .= ' La nuit et le dimanche, la pharmacie de garde est au 3237.';
        }

        return ['text' => $text, 'cards' => array_merge(array_map([self::class, 'card'], $results), [$filter]), 'follow_ups' => self::followUps($kind), 'results' => $results];
    }

    /** Carte place au format AssistantCard de l'app */
    public static function card(array $r): array
    {
        $kind = $r['kind'];
        $open = self::openNow($r['opening_hours']);

        return [
            'icon' => $kind === 'eat' ? 'fork.knife' : self::KINDS[$kind][2],
            'colors' => ['#F5215C', '#FA735C'],
            'kicker' => mb_strtoupper(self::KINDS[$kind][0]) . ' · ' . self::distanceLabel($r['distance']),
            'title' => $r['spot']['name'],
            'detail' => $r['address'] !== '' ? $r['address'] : 'Clermont-Ferrand',
            'badge' => $r['spot']['is_partner'] ? 'Partenaire' : ($open === true ? 'Ouvert · ' : ($open === false ? 'Fermé · ' : '')) . self::travel($r['distance']),
            'is_partner' => $r['spot']['is_partner'],
            'link' => ['type' => 'place', 'spot' => $r['spot']],
        ];
    }

    public static function mapFilterCard(string $category): array
    {
        $titles = ['restaurant' => 'Voir tous les restos', 'fastFood' => 'Voir la restauration rapide', 'cafe' => 'Voir les cafés et bars', 'boulangerie' => 'Voir les boulangeries',
                   'sante' => 'Voir la santé sur la carte', 'shopping' => 'Voir les boutiques', 'courses' => 'Voir les commerces de courses', 'services' => 'Voir les services',
                   'mobilite' => 'Voir parkings et recharge', 'sport' => 'Voir le sport', 'culture' => 'Voir la culture', 'hebergement' => 'Voir les hébergements'];

        return ['icon' => 'map.fill', 'colors' => ['#F5215C', '#FA735C'], 'kicker' => 'CARTE', 'title' => $titles[$category] ?? 'Ouvrir la carte',
                'detail' => 'Tous les lieux autour de toi', 'badge' => null, 'is_partner' => false, 'link' => ['type' => 'mapFilter', 'category' => $category]];
    }

    /** Résumé des résultats gardé dans la mémoire de conversation (« ses horaires » juste après) */
    public static function memo(array $results): string
    {
        return '[Lieux montrés en cartes, dans l\'ordre : ' . implode(' | ', array_map(static fn ($r) => $r['spot']['name']
            . ($r['address'] !== '' ? ', ' . $r['address'] : '') . ', ' . self::distanceLabel($r['distance'])
            . ', horaires OSM : ' . ($r['opening_hours'] ?? 'inconnus') . ($r['spot']['phone'] ? ', tél. ' . $r['spot']['phone'] : ''), array_slice($results, 0, 8))) . ']';
    }

    private static function followUps(string $kind): array
    {
        return match ($kind) {
            'eat', 'restaurant', 'fastfood', 'pizzeria', 'kebab', 'japonais' => ['Un café sympa', 'Les bons plans du moment', 'Que faire ce soir ?'],
            'pharmacie', 'medecin', 'hopital', 'dentiste' => ['La pharmacie de garde', 'Un médecin près de moi', 'Ses horaires'],
            default => ['Ses horaires', 'Itinéraire vers le plus proche', 'Où manger près de moi ?'],
        };
    }

    private static function feminine(string $label): bool
    {
        return (bool) preg_match('/^(pharmacie|boulangerie|pizzeria|banque|laverie|librairie|piscine|salle|borne|adresse|restauration)/u', $label);
    }

    private static function startsWithVowel(string $label): bool
    {
        return (bool) preg_match('/^[aeiouhéèô]/u', $label);
    }

    /**
     * Question de suite sur un lieu déjà montré (iOS ce801f2a) : « ses horaires », « son numéro », « comment y aller », « le deuxième ».
     * Renvoie [intention, index du lieu] ou null si la question ne porte pas sur les résultats précédents.
     */
    public static function followUp(string $question): ?array
    {
        $q = self::plain($question);
        // « meilleur moment pour y aller », « c'est cher ? » : pas une question d'horaires ni d'itinéraire, le modèle y répond (4034bb03)
        if (preg_match('/meilleur moment|quand (y )?aller|quelle (periode|saison|heure)|affluence|du monde|bondee?|\bcher\b|\bprix\b|tarif|meteo/', $q)) {
            return null;
        }
        $intent = match (true) {
            (bool) preg_match('/horaire|ouvert|ferme|ouvre|heure|dimanche|lundi|mardi|mercredi|jeudi|vendredi|samedi|ce soir|demain/', $q) => 'hours',
            (bool) preg_match('/numero|telephone|\btel\b|appeler|joindre/', $q) => 'phone',
            (bool) preg_match('/itineraire|y aller|aller la-?bas|comment (y )?aller|chemin|a pied|combien de temps/', $q) => 'route',
            (bool) preg_match('/\bsite\b|internet|web|menu|reserver|reservation/', $q) => 'website',
            (bool) preg_match('/adresse|ou (est|se trouve)|c.est ou|situe/', $q) => 'address',
            default => null,
        };
        $refers = (bool) preg_match('/\b(ses|son|sa|leurs?|il|elle|y|la-?bas|celui|celle|premier|premiere|deuxieme|second|seconde|troisieme|quatrieme|dernier|2e|3e|4e|et le|et la|et pour)\b/', $q);
        if ($intent === null || (!$refers && mb_strlen($q) > 40)) {
            return null;
        }
        $index = match (true) {
            (bool) preg_match('/\b(deuxieme|second|seconde|2e|2eme)\b/', $q) => 1,
            (bool) preg_match('/\b(troisieme|3e|3eme)\b/', $q) => 2,
            (bool) preg_match('/\b(quatrieme|4e|4eme)\b/', $q) => 3,
            (bool) preg_match('/\bdernier|derniere\b/', $q) => -1,
            default => 0,
        };

        return [$intent, $index];
    }

    /**
     * Réponse factuelle sur un lieu des derniers résultats (lastResults de l'app ou mémoire du serveur) :
     * horaires OSM ou de la fiche, jamais inventés ; carte place avec le même spot.id et les mêmes coordonnées.
     */
    public static function detail(array $place, string $intent, float $lat, float $lon, bool $located): array
    {
        $name = (string) $place['name'];
        $pLat = (float) $place['latitude'];
        $pLon = (float) $place['longitude'];
        // Données à jour : la fiche d'un lieu de la base, sinon OpenStreetMap (même nom, à moins de 150 m)
        $osm = null;
        if (preg_match('/^osm-(node|way|relation)-(\d+)$/', (string) $place['id'], $m)) {
            $osm = Database::fetch('SELECT * FROM osm_pois WHERE id = :id', ['id' => $m[1] . '-' . $m[2]]);
        }
        $osm ??= Database::fetch(
            'SELECT *, ROUND(111320 * SQRT(POW(latitude - :lat, 2) + POW((longitude - :lon) * 0.7, 2))) AS d FROM osm_pois
             WHERE latitude BETWEEN :a1 AND :a2 AND longitude BETWEEN :o1 AND :o2 AND name = :n ORDER BY d LIMIT 1',
            ['lat' => $pLat, 'lon' => $pLon, 'a1' => $pLat - 0.0015, 'a2' => $pLat + 0.0015, 'o1' => $pLon - 0.002, 'o2' => $pLon + 0.002, 'n' => $name],
        );
        $base = Database::fetch("SELECT hours_label, opening_hours, phone, website_url FROM places WHERE (uid = :id OR app_id = :id2 OR name = :n) AND status = 'published' AND deleted_at IS NULL
                                 AND ABS(latitude - :lat) < 0.002 AND ABS(longitude - :lon) < 0.003 LIMIT 1",
            ['id' => (string) $place['id'], 'id2' => (string) $place['id'], 'n' => $name, 'lat' => $pLat, 'lon' => $pLon]);
        $phone = $place['phone'] ?? null ?: ($base['phone'] ?? null) ?: ($osm['phone'] ?? null);
        $website = $place['website'] ?? null ?: ($base['website_url'] ?? null) ?: ($osm['website'] ?? null);
        $address = trim((string) ($place['detail'] ?? '')) ?: (string) ($osm['address'] ?? '');
        if (!preg_match('/\d/', $address)) {
            $address = (string) ($osm['address'] ?? ''); // « Clermont-Ferrand » seul : pas une adresse
        }
        $hours = $osm['opening_hours'] ?? null;
        $distance = (int) round(111320 * sqrt(($pLat - $lat) ** 2 + (($pLon - $lon) * 0.7) ** 2));

        $call = $phone ? " Le mieux est d'appeler pour vérifier : {$phone}." : '';
        $text = match ($intent) {
            'hours' => match (true) {
                $base !== null && !empty($base['hours_label']) => "Horaires de {$name} : {$base['hours_label']}." . $call,
                $hours !== null => "D'après OpenStreetMap, {$name} est ouvert " . self::hoursFr($hours) . '.'
                    . (($open = self::openNow($hours)) === null ? '' : ($open ? ' C\'est ouvert en ce moment.' : ' C\'est fermé en ce moment.'))
                    . ' Les horaires peuvent changer les jours fériés.',
                default => "Je n'ai pas les horaires de {$name}." . ($phone ? " Tu peux appeler pour les connaître : {$phone}." : ' Je te conseille de vérifier sur place ou en ligne.'),
            },
            'phone' => $phone ? "Le numéro de {$name} : {$phone}." : "Je n'ai pas le numéro de {$name}." . ($website ? " Son site : {$website}." : ''),
            'website' => $website ? "Le site de {$name} : {$website}." : "Je n'ai pas de site pour {$name}." . ($phone ? " Tu peux appeler : {$phone}." : ''),
            'route' => "{$name} est à " . self::distanceLabel($distance) . ' ' . ($located ? 'de toi' : 'de la place de Jaude') . ' (' . self::travel($distance) . ').'
                . ($address !== '' ? " Adresse : {$address}." : '') . ' Touche la carte puis « Itinéraire » pour lancer le guidage.',
            default => $address !== '' ? "{$name} se trouve {$address}, à " . self::distanceLabel($distance) . ' ' . ($located ? 'de toi' : 'de la place de Jaude') . '.'
                                        : "{$name} est à " . self::distanceLabel($distance) . ' ' . ($located ? 'de toi' : 'de la place de Jaude') . ', je n\'ai pas son adresse exacte.',
        };
        $spot = ['id' => (string) $place['id'], 'name' => $name, 'category' => in_array($place['category'] ?? null, self::MAP_CATEGORIES, true) ? $place['category'] : 'lieu',
                 'latitude' => $pLat, 'longitude' => $pLon, 'subtitle' => $address, 'phone' => $phone, 'website' => $website, 'is_partner' => false,
                 'demo_name' => $name, 'nature_kind' => null];
        $kind = $osm['kind'] ?? null;
        $card = [
            'icon' => $kind !== null ? self::KINDS[$kind][2] : 'mappin.circle.fill', 'colors' => ['#F5215C', '#FA735C'],
            'kicker' => ($kind !== null ? mb_strtoupper(self::KINDS[$kind][0]) : 'LIEU') . ' · ' . self::distanceLabel($distance),
            'title' => $name, 'detail' => $address !== '' ? $address : 'Clermont-Ferrand',
            'badge' => (($open = self::openNow($hours)) === true ? 'Ouvert · ' : ($open === false ? 'Fermé · ' : '')) . self::travel($distance),
            'is_partner' => false, 'link' => ['type' => 'place', 'spot' => $spot],
        ];

        return ['text' => $text, 'cards' => [$card], 'follow_ups' => array_values(array_diff(['Ses horaires', 'Son numéro ?', 'Comment y aller ?'],
            [['hours' => 'Ses horaires', 'phone' => 'Son numéro ?', 'route' => 'Comment y aller ?'][$intent] ?? '']))];
    }

    /** Valeurs de MapCategory connues de l'app */
    public const MAP_CATEGORIES = ['incontournable', 'evenement', 'spot', 'restaurant', 'fastFood', 'cafe', 'boulangerie', 'culture', 'nature', 'sport', 'carburant', 'lieu',
                                   'tram', 'sante', 'courses', 'shopping', 'hebergement', 'services', 'mobilite', 'etudes'];

    /** « Mo-Fr 09:00-19:00; Sa 09:00-12:00 » → « du lundi au vendredi de 9 h à 19 h, le samedi de 9 h à 12 h » (forme brute si trop complexe) */
    public static function hoursFr(string $hours): string
    {
        if (trim($hours) === '24/7') {
            return '24 h/24, 7 j/7';
        }
        $days = ['Mo' => 'lundi', 'Tu' => 'mardi', 'We' => 'mercredi', 'Th' => 'jeudi', 'Fr' => 'vendredi', 'Sa' => 'samedi', 'Su' => 'dimanche', 'PH' => 'les jours fériés'];
        $time = static fn (string $t) => (int) substr($t, 0, 2) . ' h' . (substr($t, 3, 2) !== '00' ? ' ' . substr($t, 3, 2) : '');
        $parts = [];
        foreach (array_filter(array_map('trim', explode(';', $hours))) as $rule) {
            if (!preg_match('/^((?:(?:Mo|Tu|We|Th|Fr|Sa|Su|PH)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?,?)+)?\s*(off|closed|(?:\d\d:\d\d-\d\d:\d\d,?\s*)+)$/', $rule, $m)) {
                return $hours;
            }
            $dayText = $m[1] === '' ? 'tous les jours' : implode(' et ', array_map(static function ($range) use ($days) {
                $p = explode('-', $range);
                return count($p) === 2 ? "du {$days[$p[0]]} au {$days[$p[1]]}" : ($p[0] === 'PH' ? $days['PH'] : "le {$days[$p[0]]}");
            }, explode(',', rtrim($m[1], ','))));
            if ($m[2] === 'off' || $m[2] === 'closed') {
                $parts[] = "fermé {$dayText}";
                continue;
            }
            preg_match_all('/(\d\d:\d\d)-(\d\d:\d\d)/', $m[2], $spans, PREG_SET_ORDER);
            $parts[] = $dayText . ' ' . implode(' et ', array_map(static fn ($sp) => 'de ' . $time($sp[1]) . ' à ' . $time($sp[2]), $spans));
        }

        return implode(', ', $parts);
    }

    /** Genres d'un repas selon l'heure de Paris */
    private static function eatKinds(): array
    {
        $h = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('G');

        return match (true) {
            $h < 11 => ['cafe', 'boulangerie', 'restaurant'],
            $h < 15 => ['restaurant', 'fastfood', 'cafe'],
            $h < 18 => ['cafe', 'boulangerie', 'fastfood'],
            default => ['restaurant', 'fastfood'],
        };
    }

    private static function fromPlaces(array $kinds, float $lat, float $lon, int $radius, ?string $nameQuery): array
    {
        $cats = array_values(array_intersect(array_map(static fn ($k) => self::KINDS[$k][3], array_filter($kinds, static fn ($k) => !isset(self::CUISINE[$k]))),
            ['restaurant', 'fastFood', 'cafe', 'boulangerie']));
        if ($cats === [] && $nameQuery === null) {
            return [];
        }
        $params = ['lat' => $lat, 'lon' => $lon, 'r' => $radius];
        $where = "p.status = 'published' AND p.deleted_at IS NULL";
        if ($nameQuery !== null) {
            $where .= ' AND p.name LIKE :q';
            $params['q'] = '%' . $nameQuery . '%';
        } else {
            $in = [];
            foreach ($cats as $i => $c) {
                $in[] = ':c' . $i;
                $params['c' . $i] = $c;
            }
            $where .= ' AND COALESCE(p.app_category, c.app_category) IN (' . implode(',', $in) . ')';
        }
        $rows = Database::fetchAll(
            'SELECT ' . MapController::columns() . ', ROUND(111320 * SQRT(POW(p.latitude - :lat, 2) + POW((p.longitude - :lon) * 0.7, 2))) AS distance
             FROM places p JOIN place_categories c ON c.id = p.category_id WHERE ' . $where . ' HAVING distance <= :r ORDER BY distance LIMIT 6',
            $params,
        );
        $out = [];
        foreach ($rows as $p) {
            $kind = null;
            foreach ($nameQuery !== null ? array_keys(self::KINDS) : $kinds as $k) {
                $kind ??= self::KINDS[$k][3] === $p['app_category_value'] ? $k : null;
            }
            $kind ??= $nameQuery !== null ? 'restaurant' : null;
            if ($kind !== null) {
                $out[] = ['type' => 'place', 'source' => 'place', 'spot' => MapController::place($p), 'distance' => (int) $p['distance'],
                          'kind' => $kind, 'opening_hours' => null, 'address' => (string) ($p['subtitle'] ?? '')];
            }
        }

        return $out;
    }

    private static function fromOsm(array $kinds, float $lat, float $lon, int $radius, int $limit, ?string $nameQuery): array
    {
        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * 0.7);
        $params = ['lat' => $lat, 'lon' => $lon, 'r' => $radius, 'la1' => $lat - $dLat, 'la2' => $lat + $dLat, 'lo1' => $lon - $dLon, 'lo2' => $lon + $dLon];
        $where = 'latitude BETWEEN :la1 AND :la2 AND longitude BETWEEN :lo1 AND :lo2';
        if ($nameQuery !== null) {
            $where .= ' AND name LIKE :q';
            $params['q'] = '%' . $nameQuery . '%';
        } else {
            $or = [];
            foreach ($kinds as $i => $k) {
                [$base, $cuisine] = self::CUISINE[$k] ?? [$k, null];
                $params['k' . $i] = $base;
                $or[] = $cuisine === null ? "kind = :k{$i}" : "(kind = :k{$i} AND (cuisine REGEXP :cu{$i} OR name REGEXP :cn{$i}))";
                if ($cuisine !== null) {
                    $params['cu' . $i] = $params['cn' . $i] = $cuisine;
                }
            }
            $where .= ' AND (' . implode(' OR ', $or) . ')';
        }
        $rows = Database::fetchAll(
            "SELECT *, ROUND(111320 * SQRT(POW(latitude - :lat, 2) + POW((longitude - :lon) * 0.7, 2))) AS distance FROM osm_pois
             WHERE {$where} HAVING distance <= :r ORDER BY distance LIMIT " . max(1, min(60, $limit)),
            $params,
        );

        return array_map(static function ($p) use ($kinds, $nameQuery) {
            $kind = $nameQuery !== null || in_array($p['kind'], $kinds, true) ? $p['kind'] : ($kinds[0] ?? $p['kind']);
            if ($nameQuery === null && count($kinds) === 1 && isset(self::CUISINE[$kinds[0]])) {
                $kind = $kinds[0];
            }

            return ['type' => 'place', 'source' => 'osm', 'distance' => (int) $p['distance'], 'kind' => $kind, 'opening_hours' => $p['opening_hours'], 'address' => $p['address'],
                'spot' => [
                    'id' => 'osm-' . $p['id'], 'name' => $p['name'], 'category' => self::KINDS[$p['kind']][3] ?? 'lieu',
                    'latitude' => round((float) $p['latitude'], 6), 'longitude' => round((float) $p['longitude'], 6), 'subtitle' => $p['address'],
                    'phone' => $p['phone'], 'website' => $p['website'], 'is_partner' => false, 'demo_name' => $p['name'], 'nature_kind' => null,
                ]];
        }, $rows);
    }

    private static function same(array $a, array $b): bool
    {
        $d = 111320 * sqrt(($a['spot']['latitude'] - $b['spot']['latitude']) ** 2 + (($a['spot']['longitude'] - $b['spot']['longitude']) * 0.7) ** 2);

        return $d < 200 && self::plain($a['spot']['name']) === self::plain($b['spot']['name']);
    }

    /** « ≈ 5 min à pied » jusqu'à 2,5 km, sinon « ≈ 12 min en voiture » */
    public static function travel(int $m): string
    {
        return $m <= 2500 ? '≈ ' . max(1, (int) round($m / 80)) . ' min à pied' : '≈ ' . max(2, (int) round($m / 400 + 3)) . ' min en voiture';
    }

    public static function distanceLabel(int $m): string
    {
        return $m < 1000 ? (int) (max(50, round($m / 50) * 50)) . ' m' : str_replace('.', ',', (string) round($m / 1000, 1)) . ' km';
    }

    /**
     * Ouvert maintenant d'après opening_hours OSM (formes courantes « Mo-Fr 09:00-19:00; Sa 09:00-12:00 », « 24/7 ») ;
     * null si les horaires manquent ou sont trop complexes : jamais deviné.
     */
    public static function openNow(?string $hours, ?\DateTimeImmutable $now = null): ?bool
    {
        if ($hours === null || trim($hours) === '') {
            return null;
        }
        if (trim($hours) === '24/7') {
            return true;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $days = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 7];
        $today = (int) $now->format('N');
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $result = false;
        foreach (array_filter(array_map('trim', explode(';', $hours))) as $rule) {
            if (!preg_match('/^((?:(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?,?)+)\s+(off|closed|(?:\d\d:\d\d-\d\d:\d\d,?\s*)+)$/', $rule, $m)) {
                if (preg_match('/^(?:\d\d:\d\d-\d\d:\d\d,?\s*)+$/', $rule)) {
                    $m = [0, 'Mo-Su', $rule];
                } else {
                    return null;
                }
            }
            $match = false;
            foreach (explode(',', rtrim($m[1], ',')) as $range) {
                $p = explode('-', $range);
                $from = $days[$p[0]];
                $to = $days[$p[1] ?? $p[0]];
                $match = $match || ($from <= $to ? $today >= $from && $today <= $to : $today >= $from || $today <= $to);
            }
            if (!$match) {
                continue;
            }
            $result = false; // règle plus loin = prioritaire pour ce jour
            if ($m[2] === 'off' || $m[2] === 'closed') {
                continue;
            }
            preg_match_all('/(\d\d):(\d\d)-(\d\d):(\d\d)/', $m[2], $spans, PREG_SET_ORDER);
            foreach ($spans as $s) {
                $a = (int) $s[1] * 60 + (int) $s[2];
                $b = (int) $s[3] * 60 + (int) $s[4];
                $result = $result || ($b > $a ? $minutes >= $a && $minutes < $b : $minutes >= $a || $minutes < $b);
            }
        }

        return $result;
    }

    /** Minuscules sans accents, pour la détection */
    public static function plain(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }

        return str_replace(['’', 'œ'], ["'", 'oe'], $s);
    }

    /** Import OSM de la zone (bin/console osm:import) : remplace le contenu de chaque genre importé avec succès */
    public static function import(?\Closure $log = null): int
    {
        $selectors = [];
        foreach (self::KINDS as $kind => $k) {
            foreach ($k[4] as $sel) {
                $selectors[] = [$kind, $sel];
            }
        }
        [$s, $w, $n, $e] = self::BBOX;
        $query = "[out:json][timeout:180][bbox:{$s},{$w},{$n},{$e}];(" . implode('', array_unique(array_map(static fn ($x) => $x[1] . ';', $selectors))) . ');out center tags;';
        $http = new HttpClient(timeout: 200, retries: 1);
        $data = null;
        foreach (self::MIRRORS as $url) {
            $r = $http->request('POST', $url, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], 'data=' . rawurlencode($query));
            $data = $r['status'] === 200 ? json_decode($r['body'], true) : null;
            if (is_array($data['elements'] ?? null) && $data['elements'] !== []) {
                break;
            }
            $log && $log("Overpass {$url} : HTTP {$r['status']}, miroir suivant");
            $data = null;
        }
        if ($data === null) {
            throw new \RuntimeException('Aucun serveur Overpass disponible, import reporté.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($data['elements'] as $el) {
            $t = $el['tags'] ?? [];
            $kind = self::kindOfTags($t);
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lon = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($kind === null || $lat === null) {
                continue;
            }
            $name = trim((string) ($t['name'] ?? $t['brand'] ?? ''));
            if ($name === '') {
                $name = match ($kind) {
                    'distributeur' => 'Distributeur' . (isset($t['operator']) ? ' ' . $t['operator'] : ''),
                    'toilettes' => 'Toilettes publiques',
                    'recharge' => 'Borne de recharge' . (isset($t['operator']) ? ' ' . $t['operator'] : ''),
                    default => '',
                };
            }
            if ($name === '') {
                continue; // commerce sans nom : inutilisable dans une réponse
            }
            $street = trim(($t['addr:housenumber'] ?? '') . ' ' . ($t['addr:street'] ?? ''));
            $city = $t['addr:city'] ?? null;
            $rows[$el['type'] . '-' . $el['id']] = [
                'id' => $el['type'] . '-' . $el['id'], 'kind' => $kind, 'name' => mb_substr($name, 0, 160),
                'latitude' => round((float) $lat, 6), 'longitude' => round((float) $lon, 6),
                'address' => mb_substr(implode(', ', array_filter([$street, $street !== '' ? ($city ?? 'Clermont-Ferrand') : $city])), 0, 255),
                'phone' => isset($t['phone']) || isset($t['contact:phone']) ? mb_substr(trim(explode(';', $t['phone'] ?? $t['contact:phone'])[0]), 0, 40) : null,
                'website' => ($u = $t['website'] ?? $t['contact:website'] ?? null) !== null && preg_match('#^https?://#', $u) ? mb_substr($u, 0, 255) : null,
                'opening_hours' => isset($t['opening_hours']) ? mb_substr($t['opening_hours'], 0, 255) : null,
                'cuisine' => isset($t['cuisine']) ? mb_substr($t['cuisine'], 0, 120) : null,
                'updated_at' => $now,
            ];
        }
        if (count($rows) < 200) {
            throw new \RuntimeException('Import OSM incomplet (' . count($rows) . ' lieux), données conservées.');
        }
        Database::transaction(static function () use ($rows): void {
            Database::execute('DELETE FROM osm_pois');
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $i => $r) {
                    $values[] = '(' . implode(',', array_map(static fn ($c) => ":{$c}{$i}", array_keys($r))) . ')';
                    foreach ($r as $c => $v) {
                        $params["{$c}{$i}"] = $v;
                    }
                }
                Database::execute('INSERT INTO osm_pois (' . implode(',', array_keys($chunk[0])) . ') VALUES ' . implode(',', $values), $params);
            }
        });

        return count($rows);
    }

    /** Genre d'un objet OSM d'après ses tags (premier sélecteur de KINDS qui correspond) */
    public static function kindOfTags(array $t): ?string
    {
        foreach (self::KINDS as $kind => $k) {
            foreach ($k[4] as $sel) {
                preg_match_all('/\[([a-z_:]+)(!?=)([a-z_]+)\]|\[([a-z_:]+)\]/', $sel, $conds, PREG_SET_ORDER);
                $ok = true;
                foreach ($conds as $c) {
                    if (!empty($c[4])) {
                        $ok = $ok && isset($t[$c[4]]);
                    } elseif ($c[2] === '=') {
                        $ok = $ok && in_array($c[3], explode(';', (string) ($t[$c[1]] ?? '')), true);
                    } else {
                        $ok = $ok && ($t[$c[1]] ?? null) !== $c[3];
                    }
                }
                if ($ok && $conds !== []) {
                    return $kind;
                }
            }
        }

        return null;
    }
}
