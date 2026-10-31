# Spécification v1 : ce qui est en place

Ce document suit la « Spécification de l'API back-end v1 » de l'équipe app. Le texte d'origine est dans [SPEC_V1.txt](../docs/SPEC_V1.txt).

Il recense ce qui est livré, les écarts, et ce qu'il faut encore fournir.

Les trois lots sont en place, avec PHP 8.3 et MySQL / MariaDB à la place de Next.js et PostgreSQL. Les écarts sont listés plus bas, avec les questions à trancher.

## Décisions

| Sujet | Spec | Décision |
|---|---|---|
| Serveur, base | Next.js, PostgreSQL, Redis, S3 | PHP 8.3, MySQL 8 / MariaDB 11, cache APCu, fichiers sur le serveur (`public/media`) |
| Adresse | `https://myclermont.app/api/v1` | `https://api.myclermont.app/api/v1` (proxy vers le conteneur, port 8088). L'API répond aussi sous `/v1/…` |
| Signature des requêtes | non prévue | **Conservée** sur toutes les routes, en plus du `Bearer` ([SECURITE.md](../docs/SECURITE.md)) |
| Contrat JSON | camelCase, sans enveloppe, `{ items, nextCursor }`, erreurs `{ error: { code, message, details } }` | **Appliqué à toute l'API** (`src/Core/Contract.php`) |
| Dates | ISO 8601 sans millisecondes | Partout : `…Z` (UTC) ; événements en heure de Paris (`+01:00`) |

Quatre exceptions à la signature, toutes non signées :
- les routes de compatibilité du build 16 (`/evenements.json`, `/photos/manifest.json`, `/api/trafic`) ;
- `/health` ;
- l'URL d'envoi de fichiers, protégée par son jeton ;
- le webhook App Store, vérifié par la signature d'Apple.

Côté contrat JSON :
- **Champs reçus** : les champs camelCase envoyés par l'app (`refreshToken`, `maxPrice`…) sont convertis automatiquement.
- **Code d'erreur** : le motif précis reste lisible dans `details.reason`.
- **Champs en plus** : certains objets portent des champs que la spec ne prévoit pas (lien d'origine d'une offre, source, `isFavorite`…). Un décodeur Swift les ignore.

## Routes

✅ = en place et testé de bout en bout sur la base de test.

### Lot 1

| Route | État |
|---|---|
| `POST /auth/apple`, `/auth/google`, `/auth/email/start`, `/auth/email/verify` | ✅ Jeton d'identité vérifié (clés publiques Apple / Google, `aud`, `iss`, `nonce` haché). Le nom Apple n'est enregistré qu'à la création. Le code e-mail : 6 chiffres, 10 min, 5 essais |
| `POST /auth/refresh`, `/auth/logout` | ✅ Jeton opaque `mc_rt_…` valable 60 jours, renouvelé à chaque usage. Réutiliser un ancien jeton révoque toute la famille |
| `GET / PATCH / DELETE /me` | ✅ `displayName` unique sans tenir compte des accents ni de la casse (409 sinon), `bio` 160 caractères, avatar symbole ou photo envoyée. `DELETE` → 202, accès Apple révoqué, effacement à 30 jours (`account:purge`) |
| `GET /meta` | ✅ |
| `GET /places`, `/places/{id}`, `/places/{id}/photos`, `/partners` | ✅ Format MapSpot et PlaceDetail. Liste filtrable par `bbox`, `category`, `partner`, `updatedSince`, mise en cache |
| `GET /events`, `/events/{id}` · `POST /events/submissions` · `POST / PATCH / DELETE /events` | ✅ Détail ci-dessous |
| `/evenements.json`, `/photos/manifest.json`, `/api/trafic` | ✅ Compatibilité build 16 |
| `GET /traffic/incidents` | ✅ TomTom Orbis Maps Traffic API v2 : un appel pour toute la métropole, cache 300 s, données anciennes servies 30 min en cas de panne. Paramètres `lat`, `lon`, `r` (2 500 m par défaut). `kind` : `accident`, `jam`, `roadworks`, `closure`, `laneClosed`, `hazard`, `weather`, `breakdown`, `other` ; `magnitude` 0 à 4. Clé en service, `features.traffic: true` dans `/meta` |
| `GET / PUT / DELETE /me/favorites…`, `POST /me/favorites/import` | ✅ Clés locales `spot-…`, `place-<nom>`, `event-<slug>` converties |

Événements :
- **Identifiant** : le slug « titre-date », jamais modifié.
- **Adresse** : géocodée avec le géocodeur national (IGN / BAN). Une adresse hors de la métropole est refusée.
- **Propositions** des utilisateurs : statut `pending`.
- **Publication directe** : comptes `partner` et `admin`.
- **Archivage** des événements terminés depuis 90 jours.

### Lot 2

| Route | État |
|---|---|
| `POST /uploads` → `PUT uploadUrl` → `POST /uploads/{id}/confirm` | ✅ Détail ci-dessous |
| `GET /jobs`, `/housing`, `/deals`, `/services`, `/community/posts`, `/classifieds` (+ fiches) | ✅ Objets de la spec |
| `POST /jobs/{id}/applications`, `/housing/{id}/contacts`, `/services/{id}/requests` | ✅ |
| `POST /deals/{id}/redeem`, `POST /deals/redemptions/{code}/validate` | ✅ Un code par compte, plafond respecté, `plus_required`, validation par le gérant du lieu |
| Communauté : publier, supprimer, aimer, voter, commenter | ✅ Texte 1 à 2 000, sondage 2 à 4 réponses, commentaire 500, filtre automatique |
| `GET /places/{id}/reviews`, `PUT / DELETE …/reviews/me`, `POST /me/reviews/import` | ✅ La note compte tout de suite ; un texte filtré attend la modération |
| `POST /reports`, `POST / DELETE /me/blocks/{userId}` | ✅ |
| `POST / PATCH / DELETE /devices` | ✅ Détail ci-dessous |
| `/admin/…` (rôles `moderator`, `admin`) | ✅ CRUD sur places, place_photos, events, deals, jobs, housing, services, reviews. File de signalements, résolution, bannissement, rôle, notification à un thème |

Envois de fichiers :
- **Limites** : images de 10 Mo maximum, PDF de 5 Mo, vidéos de 60 Mo et 60 s.
- **Traitement des images** : réduites à 1 600 px de large et réencodées, ce qui retire la position GPS.

Appareils et notifications :
- **Thèmes** suivis par appareil : `topics`.
- **Envoi** : APNs pour iOS, FCM pour Android.
- **Heures calmes** : pas d'annonce entre 22 h et 8 h.
- **Regroupement** des réponses par 15 min.
- **Jeton refusé** par Apple ou Google : supprimé.

### Lot 3

| Route | État |
|---|---|
| `POST /assistant/answer` | ✅ codé ; test réel à faire quand une clé Anthropic sera configurée (aujourd'hui : `503 not_configured`) |
| `GET /fuel/stations` | ✅ Flux officiel data.economie.gouv.fr, cache 10 min, prix par carburant avec date de relevé |
| `GET /me/subscription`, `POST /subscriptions/apple/verify`, `POST /webhooks/apple/app-store` | ✅ Détail ci-dessous |
| `GET /me/export` | ✅ Archive JSON de toutes les données du compte |
| `POST /live-activities` | Jeton enregistré ; l'envoi « ça commence dans 30 min » reste à faire |

Clermont IA :
- **Outils** sur la base : sorties, lieux, bons plans, emploi, logement, services, carburants, météo, `nearby_search`.
- **Cartes** construites par le serveur à partir des références renvoyées par les outils : une référence inventée est ignorée.
- **Quotas** : 1 question découverte sans My Clermont+, 100 par jour avec, 10 par minute dans tous les cas.
- **Cache** : même question dans la même zone, 10 min.
- **Journal** : questions gardées 30 jours, sans la position.

My Clermont+ (StoreKit 2) :
- **Vérification** de la chaîne de certificats jusqu'à Apple Root CA G3, du `bundleId`, du `productId` et de l'`appAccountToken`.
- **Notifications d'Apple** : renouvellement, délai de grâce, expiration, remboursement, révocation.

Durées de conservation (RGPD, `rgpd:purge`) : questions IA 30 jours, candidatures et demandes de contact 6 mois.

## Questions pour l'équipe app

Ce sont les points où l'implémentation a dû choisir :

1. **Signature des requêtes.** Toutes les routes l'exigent (en-têtes `X-Device-Id`, `X-Device-Token`, `X-Timestamp`, `X-Nonce`, `X-Signature` — voir [INTEGRATION_MOBILE.md](SIGNATURE.md)). L'appareil s'enregistre une fois avec `POST /devices/register`, signé avec la clé application. `POST /devices` de la spec met ensuite à jour le jeton push et les thèmes.
2. **Montants.** Choix faits :
   - `Housing.price` et `Ad.price` en euros entiers ;
   - `Deal.original` et `Deal.reduced` en euros décimaux (`3.5`) ;
   - libellés prêts à afficher dans `priceLabel` et `discount`.

   À confirmer avec les types Swift.
3. **Contrats.** Les offres importées (France Travail, agrégateurs) peuvent valoir « Intérim » ou « Saisonnier », en plus des 5 valeurs de la spec. Soit l'app accepte une valeur inconnue, soit on ramène ces deux cas à « CDD ».
4. **Icônes.** `Job.icon` et `Service.icon` sont des clés de catégorie (`phone`, `briefcase`…), pas des noms SF Symbols. Les cartes de Clermont IA, elles, utilisent des noms SF Symbols.
5. **Médias d'un post.** `media[]` est une liste d'URL. Le détail (type, largeur, hauteur) est dans `mediaItems[]`.
6. **Sondage.** `votes[]` est toujours rempli ; `myVote` est l'index de mon choix, ou `null`. Pour voter : `POST …/vote { "optionIndex": 1 }`.
7. **HEIC.** Le serveur ne sait pas convertir le HEIC (extension Imagick absente) et répond 415. L'app doit envoyer du JPEG, ce que fait `UIImage.jpegData`.
8. **URL d'envoi.** `uploadUrl` pointe vers l'API elle-même (`PUT /v1/uploads/{id}/blob?t=…`), pas vers un stockage S3. Le principe est le même : URL signée, valable 10 minutes.
9. **Modèle de Clermont IA.** Par défaut `claude-opus-5-5`, effort `low`, 600 jetons maximum. La spec proposait un modèle intermédiaire : il suffit de régler `ANTHROPIC_MODEL=claude-sonnet-5-5`. La réponse en flux (SSE, lot 3 facultatif) n'est pas faite.
10. **Android.** Google Play Billing et les notifications RTDN ne sont pas faits ; la table `subscriptions` est prête (`store = google`).

## À fournir (variables d'environnement)

| Variable | Pour |
|---|---|
| `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` (.p8 « Sign in with Apple ») | Révoquer l'accès Apple à la suppression d'un compte. La connexion fonctionne sans |
| `GOOGLE_CLIENT_ID` | Connexion Google (Android) |
| `APNS_KEY_ID`, `APNS_KEY` (.p8 APNs), `APNS_SANDBOX` | Notifications iOS |
| `FCM_SERVICE_ACCOUNT` (chemin du JSON Firebase) | Notifications Android |
| `TOMTOM_API_KEY` | Info trafic |
| `ANTHROPIC_API_KEY` (+ `ANTHROPIC_MODEL`) | Clermont IA |
| `MIN_APP_VERSION_IOS`, `MIN_APP_VERSION_ANDROID`, `FEATURE_*` | `/meta` |
| `SMTP_*` | Codes de connexion par e-mail, candidatures, demandes de contact |

## Exemples de réponses

Le dossier `fixtures/` contient des réponses réelles de la base de test, une par route (`places.json`, `event.json`, `me.json`…).

Elles servent aux tests de décodage Swift demandés par la spec : dates sans millisecondes, champs optionnels à `null`, aucune adresse e-mail. Elles contiennent des données de démonstration.

Le fichier `openapi.yaml` n'est pas encore régénéré pour ce contrat : c'est la prochaine étape de la documentation.
