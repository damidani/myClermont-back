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

Source d'offres autorisée à étudier : l'**API Offres d'emploi de France Travail** (francetravail.io), gratuite après inscription, qui permet de filtrer par commune. Il suffira d'écrire l'`Importer` correspondant (authentification OAuth2 + conversion vers le format ci-dessus), avec la source en `contact_mode = external` pour que « Postuler » renvoie vers l'offre d'origine.

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

Le système est prêt pour toutes les sources **autorisées** :

- **Partenariats** avec des agences immobilières, résidences étudiantes, bailleurs sociaux : la plupart exportent déjà leurs annonces via leur logiciel (flux XML/JSON vers les portails) ; il suffit d'écrire l'importer de leur format, ou de leur demander notre format normalisé.
- **Accords officiels** avec un portail qui propose une API ou un programme partenaire.
- **Open data** (pour l'agenda : données publiques des collectivités et offices de tourisme, sous licence ouverte).

Avec une source autorisée en `contact_mode = external`, le comportement voulu est exactement celui-là : la fiche s'affiche dans myClermont et le bouton « Contacter » renvoie vers l'annonce d'origine.
