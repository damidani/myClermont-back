# Modules

Chaque fonctionnalité de myClermont est un **module** : un dossier `src/Modules/<Nom>/` qui déclare ses routes, ses écouteurs d'événements et son initialisation. Les modules actifs sont listés dans `config/modules.php`.

| Module | Rôle | Doc API |
|---|---|---|
| **Account** | Inscription, connexion, profil (pseudo, bio, quartier), mot de passe, parrainage | [API.md › Compte](API.md#compte) |
| **Gamification** | Points et badges attribués automatiquement | [API.md › Gamification](API.md#gamification) |
| **Favorites** | Favoris génériques (lieux, annonces… fournis par les autres modules) | [API.md › Favoris](API.md#favoris) |
| **Premium** | Statut premium, avantages, middleware `premium` | [API.md › Premium](API.md#premium) |
| **Events** | Agenda : liste par catégorie, fiche, programme, calendrier, « Je participe », favoris `event` | [API.md › Agenda](API.md#agenda) |
| **Housing** | Logement : annonces location / achat / colocation, filtres, carte, fiche, formulaire de contact | [API.md › Logement](API.md#logement) |
| **Jobs** | Emploi & stages : offres, filtres par contrat, fiche, candidature avec CV / lettre PDF | [API.md › Emploi & stages](API.md#emploi--stages) |
| **Community** | Fil d'actualité : texte, sondage, photos, vidéo ; likes, commentaires, votes, signalements | [API.md › Communauté](API.md#communauté) |
| **Services** | Annuaire de professionnels, demande de devis, avis vérifiés | [API.md › Services](API.md#services) |
| **Mobility** | Destinations rapides, transports en commun et info trafic (Navitia) | [API.md › Mobilité](API.md#mobilité) |
| **Classifieds** | Petites annonces : vendre, acheter, donner ; dépôt avec photos ; contact par messagerie | [API.md › Petites annonces](API.md#petites-annonces) |
| **Messaging** | Messagerie entre utilisateurs (générique), blocage, signalement, notifications push | [API.md › Messagerie](API.md#messagerie) |
| **Explore** | Accueil : salutation, météo, stories, événement flash, exclusivités, « À découvrir », recherche globale | [API.md › Explorer](API.md#explorer) |
| **Places** | Carte (repères compacts, ETag, flux SSE par distance) et fiches lieux, avis | [API.md › Carte & lieux](API.md#carte--lieux) |

## Communication par événements

Les modules ne s'appellent pas directement : ils **émettent des événements** que d'autres modules écoutent. Le module Compte ne sait pas que la gamification existe ; désactiver la gamification ne casse rien.

```
Account ── account.registered ──────► Gamification : +50 pts, badge « Bienvenue à Clermont »
        │                             └─► parrain : +100 pts, compteur « referrals » (→ Ambassadeur)
        ├─ account.logged_in ───────► Gamification : +5 pts (1 fois / jour)
        └─ account.profile_updated ─► Gamification : +20 pts si bio + quartier (1 seule fois)

Favorites ── favorite.added ────────► Gamification : +2 pts, compteurs (→ Curieux, Explorateur,
                                                     Passion volcan, Gourmand)
Events ── event.participation_added ► Gamification : +5 pts (1 fois par événement)
Premium ── premium.granted
```

| Événement | Données | Émis par |
|---|---|---|
| `account.registered` | `user_id`, `referred_by` (id du parrain ou null) | Account |
| `account.logged_in` | `user_id` | Account (inscription, connexion, refresh = ouverture de l'app) |
| `account.profile_updated` | `user_id`, `user` (ligne complète) | Account |
| `favorite.added` | `user_id`, `type`, `target_id`, `tags` | Favorites (uniquement si pas déjà en favori) |
| `favorite.removed` | `user_id`, `type`, `target_id` | Favorites |
| `event.participation_added` | `user_id`, `event_id`, `event_uid`, `category` | Events |
| `event.participation_removed` | `user_id`, `event_id` | Events |
| `premium.granted` | `user_id`, `days`, `source`, `until` | Premium |

Les événements sont **synchrones** (traités dans la même requête, quelques ms). Si un écouteur plante, l'erreur est journalisée et l'action principale réussit quand même.

## Gamification

### Fonctionnement

Toute récompense passe par `GamificationService::record($userId, $action, $uniqueKey, $stats)` :

1. **Idempotence** — si `$uniqueKey` a déjà été enregistré pour cet utilisateur (table `user_events`), rien ne se passe. C'est ce qui empêche de farmer les points en retirant / remettant un favori, ou de gagner deux fois la connexion du jour.
2. **Points** — `config/gamification.php › points[$action]`, × `premium_multiplier` si premium. Chaque gain est tracé dans `point_transactions` et `users.points` est mis à jour.
3. **Compteurs** — chaque stat de `$stats` prend +1 (`user_stats`).
4. **Badges** — seuls les badges dont un compteur vient de bouger sont réévalués. Un badge débloqué verse son bonus de points.

Tout se fait dans une transaction. Les gains sont ajoutés automatiquement à la réponse HTTP, dans `meta.rewards`, quelle que soit la route :

```json
{
  "success": true,
  "data": { "type": "place", "id": "puy-de-dome", "favorited": true },
  "meta": {
    "rewards": [
      {
        "action": "favorite.added",
        "points": 22,
        "badges": [
          { "code": "curious", "name": "Curieux", "description": "Ajouter 10 éléments en favoris.", "earned_at": "2026-10-27T22:10:00Z" }
        ]
      }
    ]
  }
}
```

→ L'app n'a qu'à surveiller `meta.rewards` sur toutes les réponses pour afficher « +22 points » et l'animation du badge.

### Règles actuelles (`config/gamification.php`)

| Action | Points |
|---|---|
| Inscription | 50 |
| Profil complété (bio + quartier) | 20, une fois |
| Visite quotidienne | 5, une fois par jour (UTC) |
| Parrainage (pour le parrain) | 100 par filleul |
| Ajout d'un favori | 2, une fois par élément |
| Participation à un événement | 5, une fois par événement |
| Publication dans la communauté | 5, première publication du jour |
| Vote à un sondage | 1, une fois par sondage |
| Petite annonce déposée | 5, première du jour |
| Objet donné (annonce gratuite marquée vendue) | 10 |

| Badge | Condition | Bonus |
|---|---|---|
| Bienvenue à Clermont | Créer son compte | 0 |
| Curieux | 10 éléments en favoris | 20 |
| Explorateur | 25 **lieux** en favoris | 50 |
| Ambassadeur | 3 filleuls inscrits | 100 |
| Passion volcan | 5 favoris avec le tag `volcan` | 30 |
| Gourmand | 10 favoris avec le tag `gourmand` | 30 |

Premium : tous les gains × 1,5.

Les compteurs de favoris comptent les éléments **ajoutés depuis toujours** (retirer un favori ne fait pas perdre un badge ni des points).

### Modifier les règles

- Changer un nombre de points ou un seuil : éditer `config/gamification.php`, puis `config:cache` + reload PHP-FPM en production.
- Après avoir **abaissé** un seuil, débloquer les badges des utilisateurs qui l'atteignent déjà : `php bin/console gamification:recheck`.
- Nouveau badge sur un compteur existant : ajouter une entrée dans `badges`.
- Nouveau badge sur une nouvelle action (ex. « publier 5 annonces ») : dans le module concerné, émettre un événement (`listing.published`) ; dans `GamificationListeners`, l'écouter et appeler `record($userId, 'listing.published', 'listing:'.$id, ['listings.published'])` ; déclarer l'écouteur dans `GamificationModule::listeners()` ; ajouter le badge avec `'rule' => ['stat' => 'listings.published', 'min' => 5]`.

## Favoris : brancher un nouveau type

Le module Favorites est **générique** : il ne connaît ni les lieux ni les annonces. Un type non enregistré répond 422 `Type inconnu`. Exemple réel : `src/Modules/Events/EventFavoriteProvider.php`.

Exemple avec le module **Lieux** (version simplifiée de ce qui existe dans `src/Modules/Places`) :

```php
// src/Modules/Places/PlaceFavoriteProvider.php
final class PlaceFavoriteProvider implements FavoriteProvider
{
    public function find(array $ids): array
    {
        // UNE requête pour tous les ids (pas de N+1)
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll("SELECT uid, name, cover_url, tags FROM places WHERE uid IN ($in) AND is_published = 1", $ids);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['uid']] = [
                'tags'    => explode(',', $row['tags']),            // ex. ['volcan', 'randonnee'] → badges
                'summary' => ['name' => $row['name'], 'cover_url' => $row['cover_url']],
            ];
        }
        return $result;
    }
}

// src/Modules/Places/PlacesModule.php
public function boot(): void
{
    FavoriteTypes::register('place', new PlaceFavoriteProvider());
}
```

Les **tags** retournés alimentent automatiquement les compteurs `favorites.tag.<tag>` : un lieu tagué `volcan` fait progresser « Passion volcan », un restaurant tagué `gourmand` fait progresser « Gourmand ». Type `place` → compteur `favorites.type.place` → « Explorateur ».

Types actifs : `place` (Places), `event` (Events), `housing` (Housing), `job` (Jobs), `service` (Services), `classified` (Classifieds), `offer` et `explore_item` (Explore). Prévus : `place` (lieux), `listing` (annonces).

## Premium

- Statut = `users.premium_until` dans le futur. Prolonger = repousser la date (`PremiumService::grant`).
- Avantages actuels : multiplicateur de points, routes réservées via le middleware `premium` (`['auth', 'premium']` → 402 `premium_required` sinon).
- Attribution actuelle : `php bin/console premium:grant <email> <jours> [--source=promo]`.
- **À faire** : validation des achats in-app (App Store Server API / Google Play Developer API) et notifications serveur-à-serveur pour les renouvellements / remboursements. À implémenter dans ce module en appelant `PremiumService::grant($userId, $days, 'apple'|'google')`.

## Créer un module

```
src/Modules/Places/
├── PlacesModule.php          implements App\Core\Module
├── Controllers/PlaceController.php
├── PlaceRepository.php
└── PlaceFavoriteProvider.php
database/migrations/003_places.sql
```

```php
final class PlacesModule implements Module
{
    public function routes(Router $r): void
    {
        // Déjà sous /v1, signature appareil et rate-limit appliqués
        $r->get('/places', [PlaceController::class, 'index']);
        $r->get('/places/{id}', [PlaceController::class, 'show']);
        $r->get('/places/hidden-gems', [PlaceController::class, 'gems'], ['auth', 'premium']);
    }

    public function listeners(): array
    {
        return ['account.registered' => [[PlaceListeners::class, 'onRegistered']]];
    }

    public function boot(): void
    {
        FavoriteTypes::register('place', new PlaceFavoriteProvider());
    }
}
```

Puis : ajouter la classe dans `config/modules.php`, `php bin/console migrate`, `composer dump-autoload -o`, documenter dans `docs/API.md` et `docs/openapi.yaml`.

Attention : dans les contraintes de route, éviter les accolades (`{id:\d{1,5}}` ne fonctionne pas) ; utiliser `+` et vérifier la longueur dans le contrôleur.

## Agenda (module Events)

- Tables : `event_categories` (9 catégories initiales), `events`, `event_schedule_items` (programme), `event_participants`.
- Statuts : `draft` (invisible), `published`, `cancelled` (reste consultable, participation refusée).
- Les dates sont stockées en UTC et renvoyées en heure de Paris ; les horaires du programme sont saisis en heure locale.
- Les compteurs par catégorie sont mis en cache 60 s (`config/events.php`).
- Données de démo : `php bin/console events:seed-demo` (7 événements relatifs à aujourd'hui, slugs `demo-*`).
- Il n'existe pas encore d'API d'administration : les événements s'ajoutent en SQL ou via la commande de démo. Un back-office (ou des routes admin) sera à prévoir.

## Contenus partagés : provenance et imports

Tout module de contenu (agenda, logement, et les suivants) s'appuie sur `src/Content/` :

- `ContentSources` : colonne `source_id` sur la table du module + `ContentSources::SELECT` dans les requêtes, `present()` pour le bloc `source` de l'API, `contact()` pour le bouton « Contacter ».
- `Import\ImportTarget` : à implémenter pour accepter des imports, puis `ImportTargets::register('<nom>', …)` dans `boot()`.

Voir [IMPORTS.md](IMPORTS.md). Pour un nouveau module de contenu : ajouter `source_id`, `external_id`, `external_url`, `content_hash`, `last_synced_at` et `UNIQUE (source_id, external_id)` à sa table.

## Documents envoyés par les utilisateurs

`src/Content/Documents/` : envoi de PDF (`POST /v1/documents`, corps brut signé), stockage dans `storage/private/` (hors du dossier public, à sauvegarder), utilisable uniquement par l'appareil qui l'a envoyé, purge automatique (24 h si non utilisé, 90 jours après une candidature : `config/jobs.php › documents`). Réutilisable par de futurs modules (pièces justificatives, photos…) en ajoutant un `kind`.

## Médias publics (photos, vidéos)

`src/Content/Media/` : `POST /v1/media` (corps brut signé), fichiers dans `public/media/` servis directement par Nginx (cache 30 jours, noms aléatoires), métadonnées JPEG/PNG (GPS…) supprimées, médias non publiés purgés après 24 h. Pour de gros volumes, déplacer ce stockage vers un stockage objet (S3, Scaleway…) + CDN : seule `MediaStore` est à adapter.

## Modération (communauté)

- Signalements par les utilisateurs, masquage automatique au 5ᵉ (`config/community.php`).
- `php bin/console community:moderation` liste les publications masquées ; `--restore=<id>` les rétablit, `--delete=<id>` les supprime.
- Les CGU de l'app doivent prévoir les règles de publication : Apple et Google exigent, pour du contenu publié par les utilisateurs, un moyen de signaler et de bloquer, et une modération.

## Messagerie

- **Générique** : une conversation porte sur un contexte (`context_type`, `context_id`). Pour qu'un autre module l'utilise (logement entre particuliers, services…) : ouvrir la conversation avec `MessagingService::open()` puis `send()`, et déclarer l'en-tête des conversations avec `MessagingContexts::register('<type>', …)` dans son `boot()` (exemple : `ClassifiedsModule`).
- **Performance** : non-lus stockés par participant (badge = une somme indexée), boîte de réception triée sur un index, nouveaux messages récupérés par `?after=<id>` (lecture d'index uniquement). Pas de WebSocket : si un jour le volume l'exige, un service temps réel (Mercure, Pusher, Ably…) pourra être ajouté sans changer le modèle de données.
- **Sécurité** : seuls les deux participants voient une conversation (sinon 404) ; blocage dans les deux sens ; signalement ; liens détectés et signalés à l'app (`has_link`) contre les arnaques au faux paiement ; caractères invisibles retirés.
- **Notifications push** : file `push_outbox` vidée chaque minute par `php bin/console push:send`. Le pilote actuel (`LogPushSender`) se contente de journaliser. **À faire avant la production** : écrire une classe implémentant `App\Modules\Messaging\Push\PushSender`, soit avec Firebase Cloud Messaging (un seul service pour Android et iOS, compte de service Google), soit APNs pour iOS (clé `.p8`, HTTP/2) + FCM pour Android, puis l'indiquer dans `config/messaging.php`. Les jetons invalides renvoyés par Apple / Google devront être effacés de `devices.push_token`.

## Explorer (contenu éditorial)

- Tables : `explore_sections` (puces), `explore_stories` + `explore_story_slides` (+ `explore_story_views`), `explore_highlights` (événement flash), `offers` + `offer_claims` (exclusivités), `explore_items` (« À découvrir »).
- Tout contenu a une période de diffusion facultative (`starts_at`, `ends_at`) et un statut `draft` / `published` : on peut préparer une story ou une offre à l'avance.
- Chaque élément renvoie vers n'importe quel module via `link_type` + `link_id` (voir `src/Modules/Explore/Link.php`).
- Exclusivités : stock (`max_claims`) décompté de façon atomique, limite par personne, réservées aux membres et/ou au premium, code révélé seulement après « Profiter de l'offre ».
- Recherche globale (`GlobalSearch`) : Explore est le seul module qui lit les tables des autres (c'est son rôle d'agrégateur) ; un module désactivé est ignoré automatiquement.
- Météo : Open-Meteo, une requête par quart d'heure pour tous les utilisateurs. ⚠️ Gratuit uniquement pour un usage **non commercial** : prévoir leur offre payante (ou un autre fournisseur, à brancher dans `WeatherService`) avant le lancement commercial.
- Pas encore de back-office : le contenu se saisit en SQL ou via `php bin/console explore:seed-demo` pour la démo.

## Carte (module Places) : pourquoi c'est rapide

- **Jeu de repères en mémoire** : à la première demande, les lieux publiés sont chargés une fois (id, position, catégorie, drapeaux, nom) et gardés en mémoire partagée (APCu). Servir la carte ne touche plus MySQL ; le jeu est invalidé dès qu'un lieu change (import, saisie).
- **Format compact** : tableaux plutôt qu'objets, coordonnées à 5 décimales (≈ 1 m), ~3 fois moins de données.
- **ETag / 304** : l'app garde les repères sur le téléphone ; tant que rien ne change, chaque ouverture de la carte coûte une réponse vide.
- **Le plus proche d'abord** : tri par distance côté serveur, et flux SSE par anneaux (1 km, 3 km, 10 km, reste) pour remplir la carte autour de l'utilisateur en premier.
- **Pas de connexion permanente** : le flux SSE se ferme dès que tout est envoyé (quelques millisecondes). Une connexion SSE gardée ouverte bloquerait un processus PHP-FPM par utilisateur : à éviter.
- Mesuré : 5 000 lieux filtrés et triés en ~8 ms. Au-delà de quelques dizaines de milliers de lieux (toute la région), il faudra découper par tuiles (zone visible uniquement).
