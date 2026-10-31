# Brancher l'app My Clermont sur le back-end

Ce dossier est destiné à l'assistant (Claude) qui travaille dans le projet **de l'application** (iOS Swift / Android Kotlin).

Il sert à brancher l'app sur le back-end PHP/MySQL, qui implémente la « Spécification de l'API back-end v1 ». Il y a **une différence majeure** avec cette spec : toutes les requêtes sont signées par l'appareil.

## Fichiers

| Fichier | Contenu |
|---|---|
| `CLAUDE.md` | Ce brief : règles, ordre de branchement, pièges |
| [ROUTES.md](ROUTES.md) | Toutes les routes, avec le niveau d'accès et la limite de débit |
| [SIGNATURE.md](SIGNATURE.md) | Protocole de signature, code Swift et Kotlin prêts à copier, vecteur de test |
| [ETAT_SPEC_V1.md](ETAT_SPEC_V1.md) | Ce qui est livré, les écarts avec la spec, les questions ouvertes |
| `fixtures/*.json` | Vraies réponses du serveur, une par route : à utiliser comme tests de décodage |
| [CANAL_AGENTS.md](CANAL_AGENTS.md) | **À lire à chaque session** : boîte de messages avec le Claude du back-end (demandes de routes, bugs, réponses) |
| `test-instance.local.md` | Adresse et clé application de l'instance de test. **Secret, non versionné** : fourni à part |

## Serveur

- **Adresse de l'API** : `https://api.myclermont.app/api/v1` (production comme tests).
- **Accès direct** (secours, debug) : `http://5.9.250.39:8088/api/v1`. HTTP sans TLS : exception ATS sur iOS (`NSExceptionDomains`) et `usesCleartextTraffic` sur Android, **en debug seulement**.
- **Préfixe** : `/api/v1/…` ou `/v1/…`, les deux répondent. Utiliser `/api/v1` comme dans la spec.
- **Chemin signé** : la signature porte sur le chemin réellement envoyé, préfixe compris.

## Ordre de branchement recommandé

1. **Signature** : copier `RequestSigner` depuis [SIGNATURE.md](SIGNATURE.md). Faire passer le test du vecteur de référence **avant** tout appel réseau.
2. **Enregistrement de l'appareil**, au premier lancement :
   - générer un UUID, stocké dans le Keychain (`AfterFirstUnlockThisDeviceOnly`) ;
   - appeler `POST /api/v1/devices/register`, signé avec la **clé application** (en-tête `X-Key-Id`) ;
   - corps : `{ "platform": "ios", "model", "osVersion", "appVersion", "pushToken" }` ;
   - réponse : `{ device, deviceToken, deviceSecret, serverTime, signatureWindow }`. Stocker `deviceToken` et `deviceSecret` dans le Keychain, et calculer `clockOffset = serverTime − maintenant`.
3. **Client HTTP central** :
   - signe chaque requête avec `deviceSecret` (en-tête `X-Device-Token`) ;
   - ajoute `Authorization: Bearer <accessToken>` si connecté ;
   - gère les 401 (voir « Erreurs ») ;
   - renvoie `If-None-Match` avec l'`ETag` reçu : un 304 signifie « garder le cache ».
4. **Inscription et connexion** (brief §6) :
   - `POST /auth/register` avec le ProfileBody (`firstName`, `email`, `password`, `phone`, `gender`, `pronouns`, `city`, `postalCode`, `cityServed`, `notifyCityLaunch`, `marketingOptIn`, `consentVersion`, `ageConfirmed`) → `201 Session` ; `409 conflict` si l'e-mail existe ; `422` si un champ est invalide ;
   - `POST /auth/login { email, password }` → `200 Session` ; `401 unauthenticated` si les identifiants sont faux ;
   - `POST /auth/apple { identityToken, authorizationCode, nonce, fullName }` et `/auth/google { idToken }` → `SocialSession` : tant que le profil n'est pas complet, `isNewUser: true`, `user: null`, avec `email` et `firstName` pour préremplir ; compléter ensuite par `PATCH /me` avec le Bearer reçu ;
   - `POST /auth/password/forgot { email }` → toujours 204 ; l'e-mail contient un lien vers une page web (1 h, usage unique) ;
   - `Session` = `{ accessToken, refreshToken, expiresIn, user: UserProfile }`. Le `refreshToken` (`mc_rt_…`) va dans le Keychain ;
   - pour Apple : envoyer le nonce **en clair**, l'app passe son SHA-256 à Apple. `fullName` = le prénom en chaîne simple.
5. **Écrans en lecture** : `/meta` au démarrage (version minimale, drapeaux), puis carte, événements, emploi, logement, bons plans, communauté…
6. **Écritures** : favoris, avis, publications, signalements.
   - Favoris et avis par **clé de l'app** (brief §9) : `PUT / DELETE /me/favorites/{key}`, `GET /me/favorites` → `{ items: [{ key, createdAt }] }`, `POST /me/favorites/import { keys }` ; `PUT /places/{key}/reviews/me`, `POST /me/reviews/import { items: [{ key, rating, text }] }`. `{key}` est encodé RFC 3986 (espaces, accents) et peut désigner un lieu inconnu du back.
   - Une publication ou un avis retenu par le filtre de modération renvoie quand même un succès : il n'apparaît dans les listes qu'après validation.
7. **Envoi de photos** :
   - `POST /uploads { purpose, mime, sizeBytes }` → `{ id, uploadUrl, expiresAt }` ;
   - `PUT uploadUrl` avec les octets bruts, **sans signature** (le jeton est dans l'URL) ;
   - `POST /uploads/{id}/confirm` → URL finale ;
   - envoyer du **JPEG** : le HEIC est refusé (415).
8. **Notifications** : `PATCH /devices/me { pushToken }` après l'autorisation (ou `POST /devices { pushToken, locale, topics }`, `PATCH /devices/{id}` pour changer de thèmes).
9. **Clermont IA** : `POST /assistant/answer { question, latitude?, longitude?, context: chat|map }`. Sans compte : 1 question, puis `401` avec `reason: account_required`. Avec un compte gratuit : illimité (10 / min). Réponse `{ text, followUps, cards }`.
10. **My Clermont+** : après un achat StoreKit 2, `POST /subscriptions/apple/verify { signedTransaction }`. Lire l'état avec `GET /me/subscription`. Passer `appAccountToken` = l'UUID renvoyé par `/me`.

Les routes de compatibilité du build 16 (`/evenements.json`, `/photos/manifest.json`, `/api/trafic`) restent publiques et non signées. Les nouvelles versions de l'app doivent utiliser les routes `/api/v1`.

## Contrat JSON

- **Clés** en camelCase (`JSONDecoder` par défaut, **pas** de `convertFromSnakeCase`). Le serveur accepte aussi le camelCase en entrée.
- **Pas d'enveloppe** : un objet est renvoyé tel quel.
- **Listes** : `{ "items": [...], "nextCursor": "…" | null }`. Repasser `?cursor=<nextCursor>` pour la page suivante.
- **Dates** : ISO 8601 sans millisecondes, `.iso8601` suffit. Les événements portent un décalage horaire (`+01:00`), le reste est en `Z`.
- **Champs supplémentaires** : certains objets en ont que la spec ne prévoit pas. Les ignorer, ne jamais échouer dessus.
- **Erreurs** : `{ "error": { "code", "message", "details" } }`. `message` est en français, affichable tel quel. Le motif précis est dans `details.reason`, les erreurs par champ dans `details.fields`.
- **Codes d'erreur** : `validation_failed` (400), `unauthenticated` (401), `forbidden` (403), `plus_required` (403), `not_found` (404), `conflict` (409), `rate_limited` (429, avec `Retry-After`), `upstream_unavailable` / `not_configured` (503).
- **204** : aucune réponse à décoder.
- **Journaux** : chaque réponse porte un `X-Request-Id`. Le journaliser côté app pour croiser avec les journaux du serveur. On peut aussi en envoyer un.

## Erreurs 401 : que faire selon `details.reason`

| `reason` | Action |
|---|---|
| `invalid_device`, `device_blocked` | Effacer les identifiants appareil, se réenregistrer, rejouer **une** fois (pas de nouvel essai si `device_blocked`) |
| `timestamp_out_of_window` | `clockOffset = X-Server-Time − maintenant`, rejouer une fois |
| `invalid_signature` | Bug de signature : chemin, query ou corps différents de ce qui est signé |
| `token_expired` | `POST /auth/refresh { refreshToken }`. **Un seul** refresh en vol à la fois : les autres requêtes attendent. Puis rejouer |
| `invalid_refresh_token` (y compris jeton réutilisé) | Déconnecter et revenir à l'écran de connexion |

Un refresh token déjà utilisé révoque toute la session. Deux refresh en parallèle déconnectent donc l'utilisateur : d'où le verrou ci-dessus.

## Pièges

- **Octets signés** : ce sont les octets **exacts** du corps. Sérialiser le JSON une fois, signer, puis envoyer **ce même `Data`**.
- **Query** : encodage RFC 3986, avec `+` envoyé en `%2B`. Utiliser `RequestSigner.makeURL`, jamais `URLComponents.queryItems` seul.
- **Chemin** : pas de `/` final.
- **Nonce** : nouveau à chaque essai. Un rejeu avec le même nonce est refusé.
- **Clé application** : la cacher dans le binaire (pas de chaîne en clair). Elle ne sert **qu'à** `/devices/register`.
- **Montants** :
  - `Housing.price` et `Ad.price` en euros entiers (`0` = don) ;
  - `Deal.original`, `Deal.reduced` et `Deal.discount` sont des libellés prêts à afficher (`"3,50 €"`, `"-49 %"`) ; les montants sont dans `originalAmount` / `reducedAmount`.
- **`Job.contract`** : toujours une des 5 valeurs (CDI, CDD, Alternance, Stage, Freelance). Intérim et Saisonnier arrivent en `CDD`, avec le libellé exact dans `contractLabel`.
- **Icônes** : noms SF Symbols valides partout (`Job.icon`, `Service.icon`, `AgendaItem.icon`, cartes de Clermont IA). Liste blanche : `src/Support/AppSchemas.php` du back.
- **Catégories** : toujours le libellé affiché (`Maison & travaux`, `Restaurants`, `Maison & déco`…), jamais un slug. Les filtres `?category=` acceptent le libellé.
- **Images** : URL HTTPS absolues. Une fiche sans photo reçoit un visuel par défaut hébergé par le back (`/static/defaults/…`).
- **Sondages** : voter avec `POST /community/posts/{id}/vote { "optionIndex": 1 }`. `myVote` vaut l'index du choix ou `null`.
- **Features non disponibles** : Clermont IA et la connexion Google répondent `503 not_configured` tant que leurs clés ne sont pas installées sur le serveur. L'app doit afficher un état « indisponible », sans planter.

## Tests à écrire côté app

1. Le vecteur de signature de [SIGNATURE.md](SIGNATURE.md).
2. Le décodage de chaque fichier de `fixtures/` vers le modèle Swift ou Kotlin correspondant, y compris `error-validation.json` et `error-account-required.json` vers le type d'erreur. Ces fixtures sont construites par les presenters du back et validées contre les mêmes schémas que le décodeur iOS (`php tests/run.php --fixtures`).
3. Le décodage d'une date `2026-11-02T20:00:00+01:00` et d'une date `…Z`.

## Questions et nouvelles fonctionnalités

S'il manque une route ou si un champ ne correspond pas aux modèles de l'app, **ne pas contourner côté app** : déposer une demande sur le canal agents ([CANAL_AGENTS.md](CANAL_AGENTS.md)), avec la route, le JSON reçu et le JSON attendu. Au début de chaque session, lire les réponses du back-end sur ce canal et intégrer ce qui a été livré.
