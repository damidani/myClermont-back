# Provenance des contenus et imports automatiques

Tout contenu de l'application (événement, annonce de logement, et demain les lieux, offres d'emploi…) peut venir de trois origines :

| `kind` | Qui | Exemple | Bouton « Contacter » |
|---|---|---|---|
| `internal` | L'équipe myClermont | Événements saisis par nous | Formulaire myClermont |
| `partner` | Un partenaire qui nous fournit ses contenus | Agence immobilière, office de tourisme, salle de concert | Formulaire myClermont (par défaut) ou lien vers son site |
| `import` | Récupération automatique d'une source externe | Flux open data, API publique | Lien vers le site d'origine (par défaut) |

Chaque source est une ligne de `content_sources`. Ses réglages s'appliquent à tous ses contenus :

| Champ | Effet |
|---|---|
| `name`, `logo_url`, `attribution` | Mention affichée dans l'app (« Annonce issue de … ») |
| `contact_mode` | `internal` = formulaire myClermont, le message est transmis par e-mail à l'annonceur ; `external` = le bouton ouvre `external_url` (l'annonce sur le site d'origine) |
| `auto_publish` | `1` = en ligne immédiatement ; `0` = arrive en brouillon, à valider (recommandé pour une nouvelle source) |
| `is_active` | `0` = les imports de cette source sont suspendus |

## Ce que reçoit l'app

Chaque contenu porte un bloc `source` (`null` pour nos propres contenus) et, pour le logement, un bloc `contact` :

```json
"source": {
  "slug": "agence-dupont", "kind": "partner", "name": "Agence Dupont",
  "logo_url": "https://…/logo.png", "attribution": "Annonce proposée par l'Agence Dupont"
},
"contact": { "mode": "external", "url": "https://agence-dupont.fr/annonce/123", "label": "Contacter sur Agence Dupont" }
```

| `contact.mode` | Action de l'app |
|---|---|
| `form` | Ouvrir le formulaire « Contacter l'annonceur » → `POST /v1/housing/{id}/contact` |
| `external` | Ouvrir `contact.url` dans le navigateur (ou l'app de la source si elle est installée) |
| `none` | Annonce archivée : bouton désactivé |

## Déclarer une source

```bash
# Partenaire qui nous envoie un flux, contacts via notre formulaire, validation manuelle
php bin/console source:add agence-dupont "Agence Dupont" partner --attribution="Annonce proposée par l'Agence Dupont"

# Source importée, contacts sur le site d'origine, publication automatique
php bin/console source:add exemple "Exemple" import --contact=external --auto-publish --attribution="Annonce issue de Exemple"
```

## Importer automatiquement

1. Déclarer la source (ci-dessus).
2. La configurer dans `config/imports.php` :

```php
'agence-dupont' => [
    'target'   => 'housing',                                     // ou 'events'
    'importer' => App\Content\Import\JsonFeedImporter::class,
    'options'  => [
        'url'     => 'https://agence-dupont.fr/export/myclermont.json',
        'headers' => ['Authorization: Bearer ' . Env::get('FEED_DUPONT_TOKEN', '')],
    ],
],
```

3. Lancer : `php bin/console import:run agence-dupont` (puis automatiquement toutes les heures via `import:all` en cron, voir `deploy/crontab`).

Fonctionnement d'un import :

- **Dédoublonnage** par `(source, external_id)` : relancer un import met à jour, ne duplique jamais.
- **Empreinte** : un élément inchangé n'est pas réécrit (imports rapides, pas d'écriture inutile).
- **Disparus** : un élément absent du flux est **archivé** (logement) ou **annulé** (événement), jamais supprimé : il reste cohérent dans les favoris.
- **Garde-fous** : si le flux est vide ou contient plus de 20 % d'éléments invalides, rien n'est dépublié (une panne chez le partenaire ne vide pas l'app).
- **Verrou** : deux imports de la même source ne tournent jamais en même temps.
- **Historique** : table `import_runs` (reçus, créés, mis à jour, inchangés, dépubliés, erreurs, messages).

### Ajouter un autre format (API, XML, CSV…)

Écrire une classe qui implémente `App\Content\Import\Importer` et renvoie les éléments au format normalisé ci-dessous :

```php
final class AgenceXmlImporter implements Importer
{
    public function fetch(array $options): iterable
    {
        $xml = simplexml_load_string(/* … */);
        foreach ($xml->annonce as $a) {
            yield [
                'external_id' => (string) $a->reference,
                'transaction' => (string) $a->type === 'Location' ? 'rent' : 'sale',
                'title'       => (string) $a->titre,
                'price_cents' => (int) $a->prix * 100,
                // …
            ];
        }
    }
}
```

## Importeurs fournis

| Importeur | Pour | Source | Cible |
|---|---|---|---|
| `FranceTravailImporter` | Offres d'emploi | API officielle « Offres d'emploi v2 » de France Travail | `jobs` |
| `PolirisImporter` | Annonces immobilières | Export **Poliris** des logiciels d'agences (Hektor, Apimo, AC3, Netty…) | `housing` |
| `JsonLdImporter` | Emploi et immobilier | Données schema.org (`JobPosting`, `Apartment`, `RealEstateListing`…) des sites partenaires | `jobs`, `housing` |
| `JsonFeedImporter` | Tout | Flux JSON au format normalisé ci-dessous | toutes |

Commandes :

```bash
php bin/console import:preview <slug> 3   # affiche les 3 premiers éléments récupérés, n'écrit rien : à faire avant d'activer une source
php bin/console import:run <slug>         # lance un import maintenant
php bin/console import:status             # dernier passage de chaque source
php bin/console import:all [--force]      # cron toutes les 5 min : ne lance que les sources « dues »
```

Chaque source a sa fréquence (`every`, en minutes, défaut 60). Une source en échec est retentée au bout de 15 min. `enabled => false` la met en pause.

### Offres d'emploi France Travail

1. Créer un compte sur https://francetravail.io, puis une application, et l'abonner à l'API **Offres d'emploi v2** (gratuit).
2. Renseigner dans `.env` (ou `.env.docker`) :
   ```ini
   FRANCE_TRAVAIL_CLIENT_ID=PAR_myclermont_…
   FRANCE_TRAVAIL_CLIENT_SECRET=…
   FRANCE_TRAVAIL_COMMUNE=63113     # Clermont-Ferrand
   FRANCE_TRAVAIL_DISTANCE=15       # km autour
   ```
3. `php bin/console migrate` (crée la source `france-travail`), puis `php bin/console import:preview france-travail`.

La source est active dès que les identifiants sont présents, et tourne toutes les heures.

Conversion d'une offre :

| France Travail | myClermont |
|---|---|
| Contrat | `CDI` → cdi ; `MIS` → intérim ; `SAI` → saisonnier ; alternance (apprentissage, professionnalisation) → alternance ; sinon cdd |
| Code ROME | Catégorie, via `config/imports.php › france_travail.rome_categories` (`M18` → tech, `G16` → hôtellerie…) |
| Salaire | « Mensuel de 2000 Euros à 2500 Euros » → fourchette en centimes et période. Libellé repris tel quel s'il n'est pas lisible |
| « Postuler » | Lien de candidature de l'offre, sinon offre d'origine |
| Lieu | Ville et coordonnées fournies par France Travail |

- **(H/F)** est retiré des titres.
- **Profil recherché** : expérience, formation, compétences, qualités et permis.
- **Coordonnées du recruteur** : jamais importées. La candidature se fait sur France Travail (source en `contact_mode = external`).
- **Plafond** : l'API ne renvoie pas plus de 3 150 offres par recherche. Au-delà, découper avec `queries`, par exemple `[['typeContrat' => 'CDI'], ['typeContrat' => 'CDD,MIS,SAI']]`.
- **Offres retirées** : une offre retirée chez France Travail est archivée au passage suivant.

### Agences immobilières (Poliris)

Toutes les agences utilisent un logiciel qui diffuse leurs annonces vers les portails au format **Poliris**. Le format vient de SeLoger et il est devenu le standard du secteur : un fichier `annonces.csv`, souvent dans un ZIP avec les photos.

Pour qu'une agence partenaire apparaisse dans l'app :

1. L'agence ajoute une passerelle « myClermont » dans son logiciel. Le fichier est alors déposé par FTP chez nous, ou publié à une URL.
2. Déclarer la source :
   ```bash
   php bin/console source:add agence-dupont "Agence Dupont" partner --attribution="Annonce proposée par l'Agence Dupont"
   ```
3. Ajouter dans `config/imports.php` :
   ```php
   'agence-dupont' => [
       'target'   => 'housing',
       'importer' => App\Content\Import\PolirisImporter::class,
       'every'    => 360,
       'options'  => [
           'url'          => 'file:///var/www/myclermont/storage/imports/agence-dupont/annonces.zip',  // ou https://…
           'postal_codes' => ['63'],     // biens hors du Puy-de-Dôme ignorés
       ],
   ],
   ```

Comportement :

| Élément | Traitement |
|---|---|
| Encodage | Fichiers en ISO-8859-1 ou Windows-1252 convertis automatiquement |
| Retenus | Location, vente, colocation |
| Ignorés | Bureaux, parkings, terrains, fonds de commerce, viager, location de vacances |
| Photos | URL HTTPS reprises telles quelles. Photos du ZIP vérifiées (vraies images, 8 Mo max) puis copiées dans `public/media/imports/<source>/`, une seule fois par photo |
| Position | Pas de coordonnées dans Poliris : le bien est placé au centre de son quartier ou de sa commune (géocodeur national IGN, sans clé, résultats mis en cache 30 jours). Jamais l'adresse exacte |
| Contacts | Nom, e-mail et téléphone de l'agence importés pour transmettre les demandes du formulaire. Jamais affichés |

**Colonnes** : les numéros de colonnes suivent Poliris 4.x (`PolirisImporter::COLUMNS`). Si le fichier d'un logiciel s'en écarte, les corriger dans `options.columns`, par exemple `['energy_class' => 176]`. Vérifier avec `import:preview`.

### Sites partenaires (schema.org / JSON-LD)

Les sites carrière et les logiciels de recrutement publient leurs offres en `JobPosting`, parce que Google for Jobs l'exige : Welcome to the Jungle, Teamtailor, Flatchr, Taleez, sites d'entreprises… Beaucoup de sites d'agences publient aussi leurs biens en schema.org.

```php
'volvic-carrieres' => [
    'target'   => 'jobs',
    'importer' => App\Content\Import\JsonLdImporter::class,
    'every'    => 720,
    'options'  => [
        'kind'      => 'jobs',                                    // ou housing
        'urls'      => ['https://carrieres.exemple.fr/sitemap.xml'],  // pages de liste ou sitemaps
        'follow'    => '#/offres/[\w-]+$#',                       // liens de fiches à ouvrir
        'max_pages' => 300,
        'defaults'  => ['company_name' => 'Volvic'],              // complète ce que le site ne fournit pas
    ],
],
```

Le robot est respectueux du site :

- il lit `robots.txt` et respecte ses règles ;
- il reste sur le site de départ ;
- il ouvre une page à la fois, avec une pause d'une seconde (`delay_ms`) ;
- il s'arrête après `max_pages` pages ;
- il s'identifie comme `myClermont-importer`.

Une page en erreur n'arrête pas l'import. Pour le logement, la position fournie est arrondie à environ 100 m.

> **À réserver aux sites qui l'autorisent** : un partenaire, ou un accord écrit. Ce robot n'est pas destiné aux portails d'annonces (voir plus bas).

## Format normalisé — logement (`target: housing`)

C'est le format à transmettre aux partenaires : `{"items": [ … ]}`.

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | Identifiant stable chez le partenaire |
| `transaction` | `rent` \| `sale` \| `colocation` | ✔ | |
| `title` | string ≤ 160 | ✔ | |
| `price_cents` | entier > 0 | ✔ | Loyer mensuel ou prix de vente, **en centimes** |
| `url` | URL HTTPS | | Annonce d'origine (bouton Contacter si la source est en mode `external`) |
| `property_type` | `apartment`, `house`, `studio`, `room`, `loft`, `other` | | défaut `apartment` |
| `description` | string | | |
| `charges_cents`, `charges_included` | entier, booléen | | |
| `surface_m2`, `rooms`, `bedrooms`, `floor` | nombres | | |
| `furnished` | booléen | | |
| `energy_class` | `A`–`G` | | |
| `available_from` | `AAAA-MM-JJ` | | |
| `features` | liste de codes | | `GET /v1/housing/filters` ; codes inconnus ignorés |
| `district`, `city`, `postal_code` | string | | `city` défaut Clermont-Ferrand |
| `latitude`, `longitude` | nombres | | Position **approximative** |
| `images` | liste d'URL HTTPS | | 20 max, la première = couverture |
| `contact_name`, `contact_email`, `contact_phone` | string | | Destinataire des demandes du formulaire (jamais affiché) |

## Format normalisé — événements (`target: events`)

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | |
| `title` | string ≤ 160 | ✔ | |
| `category` | slug | ✔ | `GET /v1/events/categories` |
| `starts_at` | date ISO 8601 | ✔ | Sans fuseau = heure de Paris |
| `ends_at` | date ISO 8601 | | défaut : début + 2 h |
| `venue_name` | string | ✔ | |
| `address`, `city`, `latitude`, `longitude` | | | |
| `description`, `image_url`, `image_caption` | | | |
| `price_label`, `price_min_cents`, `ticket_url` | | | |
| `url` | URL | | Page d'origine (`external_url`) |
| `tags` | liste | | ex. `["volcan"]` → gamification |
| `programme` | liste `{time: "18:00", label}` | | |
| `notice` | string | | |

## Format normalisé — emploi (`target: jobs`)

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | |
| `title` | string ≤ 160 | ✔ | |
| `company_name` | string | ✔ | |
| `contract` | `cdi`, `cdd`, `alternance`, `stage`, `freelance`, `interim`, `saisonnier` | ✔ | |
| `category` | slug | | `GET /v1/jobs/filters` ; défaut `autre` |
| `work_mode` | `onsite`, `hybrid`, `remote` | | défaut `onsite` |
| `summary` | string ≤ 300 | | Texte de la carte |
| `description`, `profile` | string | | « Le poste », « Profil recherché » |
| `missions` | liste de chaînes | | « Tes missions » (20 max) |
| `city`, `latitude`, `longitude` | | | |
| `salary_min_cents`, `salary_max_cents` | entiers (centimes) | | |
| `salary_period` | `year`, `month`, `hour` | | |
| `salary_label` | string | | Remplace le libellé calculé (« Selon profil ») |
| `start_date`, `expires_at` | `AAAA-MM-JJ` | | L'offre est archivée après `expires_at` |
| `duration` | string | | « 6 mois » |
| `company_logo_url`, `images` | URL HTTPS | | Galerie « Découvre l'entreprise » (10 max) |
| `url` | URL HTTPS | | Offre d'origine |
| `application_url` | URL HTTPS | | Si présent, « Postuler » ouvre ce lien (site carrière / ATS) au lieu du formulaire |
| `cover_letter` | `none`, `optional`, `required` | | défaut `optional` |
| `contact_email` | e-mail | | Destinataire des candidatures (jamais affiché) |

Les offres France Travail arrivent automatiquement : voir [Importeurs fournis](#importeurs-fournis).

## Format normalisé — services (`target: services`)

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | |
| `name` | string ≤ 160 | ✔ | |
| `category` | slug | ✔ | `GET /v1/services/categories` |
| `summary`, `description` | string | | |
| `services` | liste de chaînes | | « Prestations proposées » |
| `keywords` | liste de chaînes | | Mots-clés métier pour la recherche (« plombier », « fuite »…) |
| `hours`, `price_label` | string | | |
| `city`, `latitude`, `longitude`, `service_radius_km` | | | Zone d'intervention |
| `phone`, `website_url`, `logo_url`, `cover_url`, `url` | | | |
| `rating`, `rating_label` | 0–5, string | | Note de la source : affichée comme **indicative**, jamais comme avis vérifiés |
| `contact_email` | e-mail | | Destinataire des demandes de devis (jamais affiché) |

## Format normalisé — petites annonces (`target: classifieds`)

Pour des sources autorisées (ressourcerie, recyclerie, association…). Ces annonces n'ont pas de vendeur myClermont : déclarer la source avec `--contact=external` pour que « Contacter » ouvre l'annonce d'origine.

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | |
| `title` | string ≤ 100 | ✔ | |
| `category` | slug | ✔ | `GET /v1/classifieds/filters` |
| `description` | string | | |
| `price_cents`, `is_free` | entier, booléen | | 0 ou `is_free` = don |
| `condition` | `new`, `very_good`, `good`, `fair`, `for_parts` | | défaut `good` |
| `location` | string | | « Quartier / commune » |
| `images` | liste d'URL HTTPS | | 6 max |
| `url` | URL HTTPS | | Annonce d'origine |

## Format normalisé — lieux (`target: places`)

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `external_id` | string | ✔ | |
| `name` | string ≤ 120 | ✔ | |
| `category` | slug | ✔ | `monuments`, `restaurants`, `cafes`, `patisseries`, `bars`, `culture`, `sport`, `parcs`, `volcans`, `shopping`, `carburant`, `sante`, `services-publics` |
| `latitude`, `longitude` | nombres | ✔ | |
| `tagline`, `description`, `cover_url` | | | |
| `address`, `postal_code`, `city` | | | |
| `hours` | string | | Texte affiché (« Lun–Sam · 9 h–19 h ») |
| `opening_hours` | objet | | `{"always_open": true}` ou `{"mon": [["09:00","19:00"]], …, "sun": []}` → « Ouvert maintenant » |
| `access`, `parking`, `price_label`, `phone`, `website_url` | | | Infos pratiques |
| `facts`, `highlights`, `timeline` | listes | | En chiffres, À ne pas manquer, Repères |
| `tip`, `sources` | string | | Conseil My Clermont, sources affichées |
| `tags`, `keywords` | listes | | Recherche et gamification (`volcan`, `gourmand`) |
| `is_partner`, `is_must_see` | booléens | | Filtres de la carte |

Sources autorisées pour peupler la carte : **OpenStreetMap** (licence ODbL : réutilisation libre avec la mention « © les contributeurs d'OpenStreetMap » et partage des améliorations de la base), les données ouvertes de la métropole et de l'office de tourisme (data.gouv.fr), et vos partenaires. Pour OSM, écrire un `Importer` qui interroge l'API Overpass sur la zone de Clermont (restaurants, cafés, stations-service, musées, parcs…) et convertit les éléments vers ce format.

## ⚠️ À propos de leboncoin (et des sites d'annonces en général)

Récupérer automatiquement les annonces de leboncoin (scraping) **n'est pas une bonne option** :

- **Juridique** : les conditions d'utilisation de leboncoin l'interdisent, et ses annonces forment une base de données protégée. Leboncoin a déjà obtenu en justice la condamnation d'un site qui reprenait ses annonces. Les annonces contiennent aussi des données personnelles d'annonceurs qui n'ont pas consenti à être republiés ailleurs (RGPD).
- **Technique** : le site est protégé contre les robots ; un scraper casse sans prévenir, et l'IP du serveur finit bloquée.
- **Image** : afficher les annonces d'un concurrent sans accord expose l'app à un retrait des stores ou à une mise en demeure.

Le système est prêt pour toutes les sources **autorisées**, et les importeurs fournis couvrent les cas courants :

- **Partenariats** avec des agences immobilières, résidences étudiantes et bailleurs sociaux. Leur logiciel exporte déjà en Poliris : `PolirisImporter`, sans développement.
- **Emploi** : France Travail, l'API officielle qui couvre la majorité des offres locales (`FranceTravailImporter`), et les sites carrière des entreprises partenaires (`JsonLdImporter`).
- **Accords officiels** avec un portail qui propose une API ou un programme partenaire.
- **Open data** (pour l'agenda : données publiques des collectivités et offices de tourisme, sous licence ouverte).

Avec une source autorisée en `contact_mode = external`, le comportement voulu est exactement celui-là : la fiche s'affiche dans myClermont et le bouton « Contacter » renvoie vers l'annonce d'origine.
