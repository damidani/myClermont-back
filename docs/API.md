# Référence de l'API — v1

URL de base : `https://api.myclermont.fr/v1`
Spécification machine : [openapi.yaml](openapi.yaml)

## Conventions

- Corps des requêtes et réponses en **JSON UTF-8** (`Content-Type: application/json`).
- Dates au format ISO 8601 UTC (`2026-10-27T21:56:00Z`), timestamps en secondes Unix.
- Les identifiants exposés sont des UUID (jamais les ID internes).
- Toutes les réponses contiennent l'en-tête `X-Server-Time` (heure du serveur) pour corriger le décalage d'horloge de l'appareil.

### Enveloppe de réponse

Succès :

```json
{ "success": true, "data": { … } }
```

Erreur :

```json
{
  "success": false,
  "error": {
    "code": "validation_failed",
    "message": "Les données envoyées sont invalides.",
    "details": { "email": "Adresse e-mail invalide." }
  }
}
```

L'app doit se baser sur `error.code` (stable), jamais sur `error.message` (texte destiné à l'affichage, susceptible de changer).

## Authentification

Quatre niveaux d'accès, détaillés dans [SECURITE.md](SECURITE.md) :

| Pictogramme | Signification | En-têtes requis |
|---|---|---|
| 🌐 | Public | aucun |
| 🔑 | Signé avec la **clé application** | `X-Key-Id`, `X-Device-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature` |
| 📱 | Signé par l'**appareil** | `X-Device-Id`, `X-Device-Token`, `X-Timestamp`, `X-Nonce`, `X-Signature` |
| 👤 | Appareil **+ utilisateur connecté** | en-têtes 📱 + `Authorization: Bearer <access_token>` |

Exemple de requête 👤 :

```http
GET /v1/users/me HTTP/1.1
Host: api.myclermont.fr
X-Device-Id: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
X-Device-Token: q3Jx2r…
X-Timestamp: 1793139600
X-Nonce: 8f14e45fceea167a5a36dedd4bea2543
X-Signature: 5d41402abc4b2a76b9719d911017c592…
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…
```

## Limitation de débit

| Profil | Par défaut | Portée | Routes |
|---|---|---|---|
| `device` | 120 req / 60 s | appareil | toutes les routes 📱 / 👤 |
| `register` | 10 req / heure | IP | `POST /devices/register` |
| `login` | 10 req / 5 min | IP | `POST /auth/login`, `POST /auth/register` |
| `contact` | 10 req / heure | appareil | `POST /housing/{id}/contact`, `POST /jobs/{id}/apply`, `POST /services/{id}/quote`, `POST /classifieds/{id}/contact` |
| `upload` | 20 req / heure | appareil | `POST /documents`, `POST /media` |
| `post` | 10 req / heure | appareil | `POST /community/posts` |
| `comment` | 30 req / heure | appareil | `POST /community/posts/{id}/comments` |
| `mobility` | 60 req / heure | appareil | `GET /mobility/journeys`, `GET /mobility/places` |
| `listing` | 10 req / jour | appareil | `POST /classifieds` |
| `message` | 120 req / heure | appareil | `POST /conversations/{id}/messages` |

En-têtes renvoyés : `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Au-delà : **429** `too_many_requests` avec `Retry-After` (secondes).

---

## Endpoints

### 🌐 `GET /health`

Disponibilité du service et heure serveur. N'accède pas à la base.

**200**
```json
{ "success": true, "data": { "status": "ok", "server_time": 1793139600 } }
```

---

### 🔑 `POST /devices/register`

Enregistre l'appareil et lui délivre ses identifiants. À appeler :
- au premier lancement de l'app ;
- quand une requête renvoie `invalid_device` (réinstallation, données effacées…).

Si l'appareil existe déjà, ses identifiants sont **renouvelés** et ses sessions utilisateur fermées.

**Corps**

| Champ | Type | Requis | Description |
|---|---|---|---|
| `platform` | `"ios"` \| `"android"` | ✔ | Doit correspondre à la plateforme de la clé `X-Key-Id` |
| `model` | string ≤ 100 | | ex. `iPhone15,2`, `Pixel 8` |
| `os_version` | string ≤ 50 | | ex. `18.1` |
| `app_version` | string ≤ 30 | | ex. `1.4.0` |
| `push_token` | string ≤ 255 | | Jeton APNs / FCM |

```json
{ "platform": "ios", "model": "iPhone15,2", "os_version": "18.1", "app_version": "1.4.0" }
```

**201** (nouvel appareil) / **200** (réenregistrement)
```json
{
  "success": true,
  "data": {
    "device": {
      "id": "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
      "platform": "ios",
      "model": "iPhone15,2",
      "os_version": "18.1",
      "app_version": "1.4.0",
      "push_enabled": false,
      "status": "active",
      "created_at": "2026-10-27T21:56:00Z"
    },
    "device_token": "q3Jx2r…",
    "device_secret": "Zk9pL0…",
    "server_time": 1793139600,
    "signature_window": 300
  }
}
```

> ⚠️ `device_secret` n'est transmis **qu'ici**. Le stocker immédiatement dans le Keychain (iOS) ou chiffré via Android Keystore.

**Erreurs** : `invalid_app_key` 401, `invalid_signature` 401, `platform_mismatch` 403, `device_blocked` 403, `validation_failed` 422, `too_many_requests` 429

---

### 📱 `GET /devices/me`

Informations de l'appareil courant.

**200** → `data` = objet `device` (voir ci-dessus).

---

### 📱 `PATCH /devices/me`

Met à jour les informations de l'appareil (à appeler quand le push token change ou après une mise à jour de l'app). Seuls les champs envoyés sont modifiés ; `null` efface la valeur.

| Champ | Type |
|---|---|
| `model` | string ≤ 100 \| null |
| `os_version` | string ≤ 50 \| null |
| `app_version` | string ≤ 30 \| null |
| `push_token` | string ≤ 255 \| null |

```json
{ "push_token": "fcm:APA91b…", "app_version": "1.5.0" }
```

**200** → `data` = objet `device`.

---

## Compte

Module Account — voir aussi [MODULES.md](MODULES.md).

### Objet `user`

```json
{
  "id": "a1b2c3d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d",
  "username": "lea_63",
  "bio": "Fan de randonnée sur la chaîne des Puys",
  "neighborhood": { "id": 2, "slug": "montferrand", "name": "Montferrand" },
  "points": 125,
  "is_premium": false,
  "badges": [
    { "code": "welcome", "name": "Bienvenue à Clermont", "description": "Créer son compte myClermont.", "earned_at": "2026-10-27T21:56:00Z" }
  ],
  "created_at": "2026-10-27T21:56:00Z",

  "email": "lea@example.fr",
  "referral_code": "K7QX4M2P",
  "premium_until": null
}
```

Les trois derniers champs (`email`, `referral_code`, `premium_until`) ne sont présents que sur le profil **de l'utilisateur lui-même** (`/users/me`, connexion, inscription), jamais sur un profil public.

---

### 📱 `POST /auth/register`

Crée un compte et ouvre une session sur l'appareil. Rapporte automatiquement +50 points, le badge « Bienvenue à Clermont » et +5 points de visite du jour (voir `meta.rewards`).

| Champ | Type | Requis | Description |
|---|---|---|---|
| `email` | e-mail ≤ 191 | ✔ | |
| `password` | string 8–128 | ✔ | |
| `username` | pseudo | ✔ | 3–30 caractères `A-Z a-z 0-9 _ .`, sans point au début ni à la fin. Unique, insensible à la casse |
| `neighborhood_id` | entier \| null | | Id de `GET /neighborhoods` |
| `referral_code` | string ≤ 8 | | Code de parrainage d'un autre membre (le parrain gagne 100 points) |

```json
{ "email": "lea@example.fr", "password": "••••••••", "username": "lea_63", "neighborhood_id": 2, "referral_code": "K7QX4M2P" }
```

**201**
```json
{
  "success": true,
  "data": {
    "user": { "…": "objet user" },
    "tokens": {
      "access_token": "eyJhbGciOi…",
      "token_type": "Bearer",
      "expires_in": 900,
      "refresh_token": "Jm2k…",
      "refresh_expires_in": 2592000
    }
  },
  "meta": {
    "rewards": [
      { "action": "account.registered", "points": 50, "badges": [ { "code": "welcome", "name": "Bienvenue à Clermont", "…": "…" } ] },
      { "action": "account.daily_login", "points": 5, "badges": [] }
    ]
  }
}
```

**Erreurs** : `email_taken` 409, `account_conflict` 409 (inscription simultanée), `validation_failed` 422 (`username` déjà pris, `neighborhood_id` inconnu, `referral_code` invalide…), `too_many_requests` 429

---

### 📱 `POST /auth/login`

| Champ | Type | Requis |
|---|---|---|
| `email` | e-mail | ✔ |
| `password` | string | ✔ |

**200** → même format que `/auth/register` (`meta.rewards` contient la visite du jour si c'est la première de la journée).

**Erreurs** : `invalid_credentials` 401, `account_disabled` 403, `validation_failed` 422, `too_many_requests` 429

---

### 📱 `POST /auth/refresh`

Échange le refresh token contre une **nouvelle paire** de jetons. L'ancien refresh token devient invalide : remplacer les deux jetons stockés. Compte comme visite du jour.

```json
{ "refresh_token": "Jm2k…" }
```

**200** → `data` = objet `tokens`.

**Erreurs** : `invalid_refresh_token` 401, `refresh_token_expired` 401 → renvoyer l'utilisateur à l'écran de connexion.

> Ne jamais lancer deux rafraîchissements en parallèle : un refresh token réutilisé est considéré comme volé et **toute la session est révoquée**. Sérialiser les appels (mutex / acteur côté app).

---

### 👤 `POST /auth/logout`

Ferme la session de l'utilisateur sur cet appareil (révoque les refresh tokens).

**204** sans corps.

---

### 📱 `GET /neighborhoods`

Liste des quartiers de Clermont-Ferrand (pour le sélecteur de l'inscription et du profil).

**200**
```json
{ "success": true, "data": [ { "id": 1, "slug": "centre-jaude", "name": "Centre-ville / Jaude" }, { "id": 2, "slug": "montferrand", "name": "Montferrand" } ] }
```

---

### 📱 `GET /users/username-available?username=lea_63`

Vérification en direct pendant la saisie du pseudo.

**200** → `{ "username": "lea_63", "available": false }` · **422** si le format est invalide.

---

### 📱 `GET /users/{username}`

Profil public d'un membre (sans e-mail ni code de parrainage).

**200** → `data` = objet `user` public · **404** `user_not_found`

---

### 👤 `GET /users/me`

**200** → `data` = objet `user` complet.

---

### 👤 `PATCH /users/me`

Seuls les champs envoyés sont modifiés ; `null` efface `bio` ou `neighborhood_id`. Renseigner **bio + quartier** rapporte une fois +20 points.

| Champ | Type |
|---|---|
| `username` | pseudo (mêmes règles qu'à l'inscription) |
| `bio` | string ≤ 500 \| null |
| `neighborhood_id` | entier \| null |

**200** → `data` = objet `user` mis à jour · **422** `validation_failed`

---

### 👤 `PUT /users/me/password`

| Champ | Type | Requis |
|---|---|---|
| `current_password` | string | ✔ |
| `new_password` | string 8–128 | ✔ |

Ferme **toutes** les sessions de l'utilisateur sur tous ses appareils, puis renvoie de nouveaux jetons pour l'appareil courant.

**200** → `data` = objet `tokens` (les remplacer) · **422** si `current_password` est faux

---

## Gamification

Points et badges sont attribués **automatiquement** par le serveur ; l'app ne fait que les afficher. Règles détaillées : [MODULES.md › Gamification](MODULES.md#gamification).

Toute réponse peut contenir `meta.rewards` (liste) quand l'action a rapporté des points ou un badge :

```json
"meta": { "rewards": [ { "action": "favorite.added", "points": 2, "badges": [] } ] }
```

### 👤 `GET /badges`

Catalogue complet avec la progression de l'utilisateur.

**200**
```json
{
  "success": true,
  "data": [
    { "code": "welcome", "name": "Bienvenue à Clermont", "description": "Créer son compte myClermont.", "earned_at": "2026-10-27T21:56:00Z", "earned": true, "progress": { "current": 1, "target": 1 }, "points": 0 },
    { "code": "curious", "name": "Curieux", "description": "Ajouter 10 éléments en favoris.", "earned_at": null, "earned": false, "progress": { "current": 4, "target": 10 }, "points": 20 }
  ]
}
```

Codes : `welcome`, `curious`, `explorer`, `ambassador`, `volcano`, `gourmand`. Le `code` est stable (à utiliser pour choisir l'icône dans l'app) ; `name` et `description` peuvent changer.

### 👤 `GET /users/me/points?limit=30&before=<id>`

Solde et historique, du plus récent au plus ancien. Page suivante : `before = meta.next_before` (null = fin).

**200**
```json
{
  "success": true,
  "data": {
    "balance": 125,
    "history": [
      { "id": 812, "amount": 20, "reason": "badge.curious", "created_at": "2026-10-27T22:10:00Z" },
      { "id": 811, "amount": 2, "reason": "favorite.added", "created_at": "2026-10-27T22:10:00Z" }
    ]
  },
  "meta": { "next_before": 811 }
}
```

`reason` : `account.registered`, `account.profile_completed`, `account.daily_login`, `referral.completed`, `favorite.added`, `event.participated`, `badge.<code>`.

---

## Favoris

Favoris génériques : `type` = `place` (lieux), `listing` (annonces), `event`… Chaque type est activé par son module ; tant qu'il n'existe pas, l'API répond 422 `Type inconnu`.

### 👤 `PUT /users/me/favorites/{type}/{id}`

Ajoute aux favoris. **Idempotent** (appeler deux fois ne fait rien de plus). La première mise en favori d'un élément rapporte +2 points et fait progresser les badges (Curieux, Explorateur, Passion volcan, Gourmand).

**200** → `{ "type": "place", "id": "puy-de-dome", "favorited": true }`
**Erreurs** : `favorite_target_not_found` 404, `validation_failed` 422 (type inconnu)

### 👤 `DELETE /users/me/favorites/{type}/{id}`

Retire des favoris. Idempotent. Les points et badges déjà gagnés sont conservés.

**200** → `{ "type": "place", "id": "puy-de-dome", "favorited": false }`

### 👤 `GET /users/me/favorites?type=place&limit=30&before=<curseur>`

Liste, du plus récent au plus ancien. `type` optionnel. Page suivante : `before = meta.next_before`.

**200**
```json
{
  "success": true,
  "data": [
    { "type": "place", "id": "puy-de-dome", "item": { "name": "Puy de Dôme", "cover_url": "https://…" }, "created_at": "2026-10-27T22:10:00Z" }
  ],
  "meta": { "counts": { "place": 12, "listing": 3 }, "next_before": "MjAyNi0xMC0yNyAyMjoxMDowMA==" }
}
```

`item` vaut `null` si l'élément a été supprimé depuis.

### 👤 `GET /users/me/favorites?type=place&ids=a,b,c`

Indique lesquels de ces éléments (200 max) sont en favori — pour afficher les cœurs sur un écran de liste en un seul appel.

**200** → `{ "type": "place", "favorited": ["a", "c"] }`

---

## Premium

### 👤 `GET /users/me/premium`

**200**
```json
{
  "success": true,
  "data": { "is_premium": true, "until": "2026-11-26T22:00:00Z", "source": "promo", "perks": { "points_multiplier": 1.5 } }
}
```

Les routes réservées aux membres premium répondent **402** `premium_required` aux autres.

L'achat in-app (App Store / Google Play) n'est pas encore branché ; le premium s'attribue en CLI (`php bin/console premium:grant`).

---

## Agenda

Module Events. Consultation **sans compte** (signature appareil suffisante) ; si l'app envoie `Authorization: Bearer`, les champs `is_favorite` / `is_participating` sont renseignés (sinon `null`).

### Objet `event` (carte de liste)

```json
{
  "id": "0e8b3c4a-1111-4222-8333-444455556666",
  "slug": "match-de-rugby-soiree-supporters",
  "title": "Match de rugby — soirée supporters",
  "category": { "slug": "sport", "name": "Sport", "icon": "sport", "color": "#D63A5E" },
  "image": { "url": "https://…/rugby.jpg", "caption": "Visuel d'illustration · démo" },
  "starts_at": "2026-10-28T19:00:00+01:00",
  "ends_at": "2026-10-28T21:00:00+01:00",
  "duration_minutes": 120,
  "state": "upcoming",
  "venue": { "name": "Stade Marcel-Michelin", "city": "Clermont-Ferrand", "address": "107 Avenue de la République", "latitude": 45.7899, "longitude": 3.1162 },
  "price": { "label": "Billetterie à confirmer", "min_cents": null, "is_free": false },
  "is_featured": true,
  "participants_count": 42,
  "is_favorite": false,
  "is_participating": false
}
```

| Champ | Utilisation à l'écran |
|---|---|
| `starts_at` | Heure de Paris avec décalage. Pastille « OCT. 28 », « Mercredi 28 oct. », « 19:00 », étiquettes **DEMAIN / DANS 2 JOURS** et compte à rebours « Commence dans 19 h 15 min » : calculés par l'app (corriger l'horloge avec `X-Server-Time`) |
| `duration_minutes` | « 19:00 · **2 h** » |
| `state` | `upcoming`, `ongoing` (en cours), `past`, `cancelled` |
| `image.caption` | Bandeau « VISUEL D'ILLUSTRATION · DÉMO » (null = pas de bandeau) |
| `category.icon` / `color` | Clé d'icône à associer dans l'app + couleur de la pastille |
| `price.label` | Texte affiché tel quel (« Billetterie à confirmer », « À partir de 15 € ») |
| `venue.latitude/longitude` | Carte « Accès » et bouton « Itinéraire » (Plans / Google Maps) |
| `source`, `external_url` | Provenance (null = myClermont) et page d'origine si l'événement vient d'un partenaire / d'un import ([IMPORTS.md](IMPORTS.md)) |

### 📱 `GET /events`

Écran « Agenda » : événements publiés non terminés, du plus proche au plus lointain.

| Paramètre | Description |
|---|---|
| `category` | Slug de catégorie (`sport`, `musique`…) — absent = « Tous » |
| `from`, `to` | Jours `AAAA-MM-JJ` (heure de Paris), bornes incluses |
| `neighborhood_id` | Quartier |
| `free=1` | Gratuits uniquement |
| `featured=1` | Mis en avant uniquement |
| `limit` | 1–50, défaut 20 |
| `cursor` | `meta.next_cursor` de la page précédente |

**200**
```json
{
  "success": true,
  "data": [ { "…": "objet event" } ],
  "meta": {
    "categories": [
      { "slug": "sport", "name": "Sport", "icon": "sport", "color": "#D63A5E", "count": 2 },
      { "slug": "musique", "name": "Musique", "icon": "music", "color": "#8E3B8E", "count": 1 }
    ],
    "total": 7,
    "next_cursor": null
  }
}
```

- `meta.categories` → les filtres « Sport 2 », « Musique 1 » ; « Tous 7 » = `meta.total` sans filtre. Les compteurs tiennent compte de tous les événements à venir (pas des filtres de date).
- `meta.total` → « 7 événement(s) » pour la catégorie sélectionnée.

**Erreurs** : `validation_failed` 422 (catégorie inconnue, date ou curseur invalide)

### 📱 `GET /events/categories`

Les mêmes catégories avec compteurs, seules.

### 📱 `GET /events/{id}`

Fiche détaillée. `{id}` = UUID **ou** slug (liens de partage). Ajoute à l'objet `event` :

```json
{
  "description": "Une soirée autour du rugby clermontois.\nÉvénement fictif utilisé pour tester l'agenda.",
  "programme": [
    { "time": "18:00", "label": "Accueil du public" },
    { "time": "19:00", "label": "Animation sportive" },
    { "time": "21:00", "label": "Fin prévisionnelle" }
  ],
  "notice": "Événement fictif : aucune réservation ni billetterie réelle.",
  "ticket_url": null,
  "capacity": null,
  "is_full": false,
  "share_url": "https://myclermont.fr/agenda/match-de-rugby-soiree-supporters",
  "calendar_url": "/v1/events/0e8b3c4a-…/calendar.ics"
}
```

| Section de l'écran | Champ |
|---|---|
| À propos | `description` (sauts de ligne `\n`) |
| Programme | `programme` (vide = masquer la section) |
| Accès | `venue` |
| Calendrier | Créer l'événement via EventKit / CalendarContract avec `title`, `starts_at`, `ends_at`, `venue`, ou télécharger `calendar_url` |
| Partager | `share_url` |
| Mention ⓘ | `notice` (null = masquer) |
| Billetterie | `ticket_url` (null = pas de bouton) |

**Erreurs** : `event_not_found` 404

### 📱 `GET /events/{id}/calendar.ics`

Fichier iCalendar (`text/calendar`) de l'événement. Requête signée comme les autres.

### 👤 `PUT /events/{id}/participation`

Bouton « Je participe ». Idempotent. La première participation à un événement rapporte +5 points (`meta.rewards`).

**200**
```json
{ "success": true, "data": { "id": "0e8b3c4a-…", "is_participating": true, "participants_count": 43 } }
```

**Erreurs** : `event_not_found` 404, `event_cancelled` 409, `event_ended` 409, `event_full` 409

### 👤 `DELETE /events/{id}/participation`

Annule la participation. Idempotent. Même réponse avec `is_participating: false`.

### 👤 `GET /users/me/events?past=1`

« Mes événements » : à venir (défaut, du plus proche au plus lointain) ou passés (`past=1`, du plus récent au plus ancien). 100 maximum.

### Favoris

Le cœur utilise le module Favoris avec le type **`event`** :

- `PUT /users/me/favorites/event/{id}` / `DELETE …` (id = UUID de l'événement)
- `GET /users/me/favorites?type=event` → `item` = objet `event`

Tags transmis à la gamification : la catégorie + les tags de l'événement (un événement tagué `volcan` fait progresser « Passion volcan »).

---

## Logement

Module Housing. Consultation **sans compte** ; connecté → `is_favorite` renseigné. Les annonces peuvent venir de myClermont, d'un partenaire ou d'un import : voir [IMPORTS.md](IMPORTS.md).

### Objet `listing` (carte de liste)

```json
{
  "id": "7d1e2f30-aaaa-4bbb-8ccc-ddddeeeeffff",
  "transaction": { "code": "rent", "label": "Location" },
  "property_type": { "code": "apartment", "label": "Appartement" },
  "title": "T2 lumineux proche Jaude",
  "price": { "cents": 69000, "period": "month", "label": "690 € / mois" },
  "location": {
    "district": "Centre-ville", "city": "Clermont-Ferrand", "label": "Centre-ville · Clermont-Ferrand",
    "neighborhood": { "id": 1, "name": "Centre-ville / Jaude" }
  },
  "surface_m2": 44, "rooms": 2, "bedrooms": 1, "furnished": false,
  "features": [
    { "code": "balcony", "label": "Balcon", "icon": "sun" },
    { "code": "equipped_kitchen", "label": "Cuisine équipée", "icon": "kitchen" },
    { "code": "near_tram", "label": "Proche tram", "icon": "tram" }
  ],
  "cover_url": "https://…/1.jpg",
  "image_count": 3,
  "source": null,
  "is_favorite": false,
  "published_at": "2026-10-27T22:00:00Z"
}
```

| Élément de l'écran | Champ |
|---|---|
| Badge LOCATION / ACHAT / COLOCATION | `transaction.label` |
| Badge MEUBLÉ | `furnished` |
| « 690 € / mois » | `price.label` (déjà formaté, espaces insécables) |
| « Centre-ville · Clermont-Ferrand » | `location.label` |
| « 44 m² · 2 p. · 1 ch. » | `surface_m2`, `rooms`, `bedrooms` |
| Pastilles d'atouts | `features` (la carte en affiche 3, la fiche toutes) |
| Mention de source | `source.attribution` (null = rien à afficher) |

### 📱 `GET /housing`

| Paramètre | Description |
|---|---|
| `transaction` | `rent`, `sale`, `colocation` — absent = « Tous » |
| `furnished=1` | Bouton « Meublé » / « Meublé uniquement » |
| `max_price` | « Budget maximum » en **euros** |
| `min_rooms` | « Nombre minimum de pièces » |
| `q` | Barre « Quartier, ville, type de bien… » (titre, quartier, ville, code postal, type : « appartement », « maison »…) |
| `neighborhood_id`, `property_type` | Filtres complémentaires |
| `bbox` | `minLat,minLng,maxLat,maxLng` : annonces dans la zone visible |
| `sort` | `recent` (défaut), `price_asc`, `price_desc` |
| `limit`, `cursor` | Pagination (`meta.next_cursor`) |

**200**
```json
{
  "success": true,
  "data": [ { "…": "objet listing" } ],
  "meta": {
    "transactions": [
      { "code": "rent", "label": "Location", "count": 2 },
      { "code": "sale", "label": "Achat", "count": 2 },
      { "code": "colocation", "label": "Colocation", "count": 1 }
    ],
    "all_count": 5,
    "total": 5,
    "next_cursor": null
  }
}
```

- Puces : « Tous » = `meta.all_count`, puis `meta.transactions`.
- « 5 bien(s) » = `meta.total` (tient compte de **tous** les filtres ; renvoyé sur la première page uniquement, `null` ensuite).

### 📱 `GET /housing/map`

Mode carte : mêmes paramètres de filtre (passer `bbox` = zone visible). 500 épingles max.

```json
{ "success": true, "data": [ { "id": "7d1e…", "transaction": "rent", "latitude": 45.7775, "longitude": 3.0815, "price_label": "690 € / mois" } ] }
```

Toucher une épingle → `GET /housing/{id}`.

### 📱 `GET /housing/filters`

Libellés et codes de tous les filtres (transactions, types de bien, équipements avec icônes, tris), pour ne rien coder en dur dans l'app.

### 📱 `GET /housing/{id}`

Fiche. Ajoute à l'objet `listing` :

```json
{
  "images": ["https://…/1.jpg", "https://…/2.jpg", "https://…/3.jpg"],
  "image_caption": "Photos d'illustration · démo",
  "description": "Appartement lumineux dans un immeuble de caractère…",
  "charges": null,
  "floor": null,
  "energy_class": null,
  "available_from": null,
  "coordinates": { "latitude": 45.7775, "longitude": 3.0815, "approximate": true },
  "income_hint": {
    "ratio": 3,
    "monthly_cents": 207000,
    "label": "≈ 2 070 € / mois",
    "text": "Règle courante des bailleurs : des revenus d'environ 3 fois le loyer. Une garantie (Visale, garant) peut compléter le dossier."
  },
  "notice": "Annonce fictive : aucune prise de contact réelle.",
  "is_active": true,
  "contact": { "mode": "form", "url": null, "label": "Contacter" },
  "share_url": "https://myclermont.fr/logement/7d1e…"
}
```

| Élément | Champ |
|---|---|
| Carrousel + bandeau « PHOTOS D'ILLUSTRATION · DÉMO » | `images`, `image_caption` |
| Encart « Revenus souvent demandés » | `income_hint` (`null` pour un achat → masquer) |
| Mention ⓘ | `notice` |
| Bouton « Contacter » | `contact` : `form` → formulaire ; `external` → ouvrir `contact.url` ; `none` → désactivé (annonce expirée) |

Les coordonnées de l'annonceur ne sont **jamais** renvoyées par l'API.

**Erreurs** : `listing_not_found` 404

### 📱 `POST /housing/{id}/contact`

Formulaire « Contacter l'annonceur ». Fonctionne sans compte (avec compte, la demande est rattachée à l'utilisateur). La demande est enregistrée immédiatement puis transmise par e-mail à l'annonceur (`Reply-To` = e-mail du demandeur : l'annonceur répond directement).

| Champ | Type | Requis |
|---|---|---|
| `name` | string 2–120 | ✔ « Nom et prénom » |
| `email` | e-mail | ✔ |
| `phone` | string ≤ 30 \| null | « Téléphone (facultatif) » |
| `message` | string 10–2000 | ✔ « Ton message » |

« Prévisualiser ma demande » est un écran de l'app : aucun appel serveur.

**201**
```json
{ "success": true, "data": { "id": "c0ffee00-…", "status": "pending", "message": "Votre demande a été transmise à l'annonceur." } }
```

**Erreurs** :
- `contact_external` 409 → l'annonce se contacte sur le site d'origine (`error.details.url`) — normalement évité en lisant `contact.mode` sur la fiche
- `listing_inactive` 409 → annonce archivée
- `contact_already_sent` 429 → déjà contacté cet annonceur depuis cet appareil dans les dernières 24 h
- `too_many_requests` 429 → plus de 10 demandes par heure depuis l'appareil
- `validation_failed` 422

### Favoris

Type **`housing`** : `PUT /users/me/favorites/housing/{id}`, `GET /users/me/favorites?type=housing`.

---

## Emploi & stages

Module Jobs. Consultation **sans compte** ; connecté → `is_favorite` renseigné et candidatures visibles dans « Mes candidatures ».

### Objet `job` (carte de liste)

```json
{
  "id": "5a5a5a5a-1111-4222-8333-444455556666",
  "title": "Développeur·se iOS SwiftUI",
  "company": { "name": "Studio Volcan · Démo", "logo_url": null },
  "category": { "slug": "tech", "name": "Informatique & numérique", "icon": "phone" },
  "contract": { "code": "cdi", "label": "CDI", "color": "#2E9E6E" },
  "work_mode": { "code": "hybrid", "label": "Hybride", "icon": "laptop" },
  "summary": "Conception d'interfaces iOS, SwiftUI, tests et collaboration produit.",
  "city": "Clermont-Ferrand",
  "salary": { "min_cents": 3800000, "max_cents": 4500000, "period": "year", "label": "38 000–45 000 € / an" },
  "badge": "DÉMO",
  "is_new": true,
  "source": null,
  "is_favorite": false,
  "published_at": "2026-10-27T22:00:00Z"
}
```

| Élément de l'écran | Champ |
|---|---|
| Icône carrée de la carte | `company.logo_url`, sinon `category.icon` |
| Pastille NOUVEAU | `is_new` (publiée depuis moins de 7 jours) |
| Pastille CDI / CDD / Alternance | `contract.label` + `contract.color` |
| « Clermont-Ferrand · Hybride » | `city`, `work_mode` |
| « 38 000–45 000 € / an » | `salary.label` (déjà formaté ; `salary` null = non communiqué) |
| Pastille DÉMO | `badge` (texte libre, null = aucune) |

### 📱 `GET /jobs`

| Paramètre | Description |
|---|---|
| `contract` | `cdi`, `cdd`, `alternance`, `stage`, `freelance`, `interim`, `saisonnier` — absent = « Tous » |
| `q` | Recherche (métier, entreprise, ville, résumé, catégorie) |
| `category`, `work_mode`, `city` | Filtres complémentaires |
| `min_salary` | Salaire annuel minimum en **euros** (offres avec salaire annuel uniquement) |
| `limit`, `cursor` | Pagination (`meta.next_cursor`), du plus récent au plus ancien |

**200** → `data` = liste de `job`, et :

```json
"meta": {
  "contracts": [
    { "code": "cdi", "label": "CDI", "color": "#2E9E6E", "count": 2 },
    { "code": "cdd", "label": "CDD", "color": "#E0882E", "count": 1 },
    { "code": "alternance", "label": "Alternance", "color": "#7B4FD6", "count": 1 }
  ],
  "all_count": 4,
  "total": 4,
  "next_cursor": null
}
```

« Tous 4 » = `all_count` ; « 4 offre(s) » = `total` (avec les filtres, première page uniquement). Masquer les puces dont `count` vaut 0 si souhaité.

### 📱 `GET /jobs/filters`

Contrats (libellés, couleurs), rythmes (libellés, icônes), catégories (icônes).

### 📱 `GET /jobs/{id}`

Ajoute à l'objet `job` :

```json
{
  "images": ["https://…/1.jpg", "https://…/2.jpg", "https://…/3.jpg"],
  "image_caption": "Découvre l'entreprise",
  "description": "Conception d'interfaces iOS, SwiftUI, tests et collaboration produit…",
  "missions": ["Participer aux projets de l'équipe", "Collaborer avec les différents interlocuteurs", "Contribuer à l'amélioration continue"],
  "profile": "Autonomie, esprit d'équipe, curiosité et motivation…",
  "start_date": null,
  "duration": null,
  "coordinates": null,
  "notice": "Annonce fictive : aucune entreprise ne recevra de candidature.",
  "is_active": true,
  "apply": { "mode": "form", "url": null, "label": "Postuler", "cv": "required", "cover_letter": "optional" },
  "my_application": null,
  "share_url": "https://myclermont.fr/emploi/5a5a…"
}
```

| Section | Champ |
|---|---|
| « Le poste » | `description` |
| « Tes missions » | `missions` (vide = masquer) |
| « Profil recherché » | `profile` |
| Bouton « Postuler » | `apply.mode` : `form` → formulaire ; `external` → ouvrir `apply.url` (site carrière ou offre d'origine) ; `none` → offre expirée |
| « CV obligatoire · Lettre facultative » | `apply.cv`, `apply.cover_letter` (`required` / `optional` / `none` = masquer le champ lettre) |
| Déjà postulé | `my_application` (`{id, status, applied_at}` ou null) → afficher « Candidature envoyée » à la place du bouton |

**Erreurs** : `job_not_found` 404

### Postuler : envoi des PDF puis de la candidature

Les fichiers sont envoyés **un par un, avant** la candidature, en corps brut (pas de multipart) : la signature HMAC porte alors exactement sur les octets du PDF, comme pour n'importe quelle requête.

#### 📱 `POST /documents?kind=cv&filename=Mon%20CV.pdf`

```http
POST /v1/documents?kind=cv&filename=Mon%20CV.pdf
Content-Type: application/pdf
X-Signature: …   (hash du corps = SHA-256 des octets du PDF)

%PDF-1.7 …
```

| Paramètre | Valeurs |
|---|---|
| `kind` | `cv` ou `cover_letter` |
| `filename` | Nom d'origine (nettoyé côté serveur) |

**201**
```json
{ "success": true, "data": { "id": "d0c0…", "kind": "cv", "filename": "Mon CV.pdf", "size_bytes": 184320 } }
```

- 5 Mo maximum (`413 payload_too_large`), PDF uniquement, vérifié sur le contenu (`415 unsupported_document`).
- 20 envois par heure et par appareil.
- Le document n'est utilisable que **par l'appareil qui l'a envoyé**, et est supprimé automatiquement s'il n'est pas joint à une candidature sous 24 h.
- L'app peut envoyer le PDF dès qu'il est choisi (la ligne « Ajouter mon CV » passe à « Mon CV.pdf ✓ ») ; en cas de remplacement : `DELETE /documents/{id}` puis nouvel envoi.

#### 📱 `POST /jobs/{id}/apply`

| Champ | Type | Requis |
|---|---|---|
| `name` | string 2–120 | ✔ « Nom et prénom » |
| `email` | e-mail | ✔ |
| `phone` | string \| null | « Téléphone (facultatif) » |
| `message` | string ≤ 3000 \| null | « Message au recruteur » |
| `cv_document_id` | id renvoyé par `/documents` | ✔ |
| `cover_letter_document_id` | id \| null | selon `apply.cover_letter` |

« Dossier complété à X % » est calculé par l'app à partir des champs remplis.

**201**
```json
{ "success": true, "data": { "id": "a991…", "status": "pending", "message": "Ta candidature a été envoyée au recruteur.", "created_at": "2026-10-28T00:06:00Z" } }
```

La candidature est transmise par e-mail au recruteur dans la minute, CV et lettre en pièces jointes, avec l'e-mail du candidat en adresse de réponse. Les documents sont ensuite conservés 90 jours puis supprimés (RGPD).

**Erreurs** :
- `already_applied` 409 → déjà postulé à cette offre (30 jours, par appareil ou par compte)
- `apply_external` 409 → la candidature se fait sur le site du recruteur (`error.details.url`)
- `job_inactive` 409 → offre expirée
- `validation_failed` 422 → champ manquant, ou document introuvable / expiré / déjà utilisé (« renvoyez le fichier »)
- `too_many_requests` 429

### 👤 `GET /users/me/applications`

« Mes candidatures » (100 dernières) :

```json
{ "success": true, "data": [ { "id": "a991…", "status": "sent", "applied_at": "2026-10-28T00:06:00Z", "offer": { "…": "objet job" } } ] }
```

`status` : `processing` (en cours d'envoi) ou `sent` (transmise au recruteur).

### Favoris

Type **`job`** : `PUT /users/me/favorites/job/{id}`, `GET /users/me/favorites?type=job`.

---

## Communauté

Module Community. Lecture **sans compte** ; publier, aimer, voter, commenter, signaler : compte obligatoire.

### Objet `post`

```json
{
  "id": "9c1f…",
  "type": { "code": "poll", "label": "Sondage", "icon": "chart" },
  "author": { "id": "…", "username": "Thomas", "initials": "T", "color_index": 3, "neighborhood": "Montferrand", "is_me": false },
  "body": "Quel type de sortie préférez-vous ce week-end ?",
  "badge": "DÉMO",
  "media": [],
  "media_caption": null,
  "poll": {
    "options": [
      { "id": 11, "label": "Concert", "votes": null, "percent": null },
      { "id": 12, "label": "Randonnée", "votes": null, "percent": null },
      { "id": 13, "label": "Restaurant", "votes": null, "percent": null }
    ],
    "total_votes": null,
    "voted_option_id": null,
    "results_visible": false
  },
  "likes_count": 0,
  "comments_count": 0,
  "is_liked": false,
  "share_url": "https://myclermont.fr/communaute/9c1f…",
  "created_at": "2026-10-28T00:11:00Z"
}
```

| Élément de l'écran | Champ |
|---|---|
| Avatar « AC », « J » | `author.initials` + couleur n° `author.color_index` (palette de 8 couleurs définie dans l'app) |
| « Jaude · il y a 4 secondes » | `author.neighborhood` + `created_at` (relatif calculé par l'app) |
| Pastille Discussion / Sondage / Photo | `type.label`, `type.icon` |
| Pastille DÉMO | `badge` |
| Photos / vidéo | `media[]` : `{kind: image\|video, url, width, height}` ; bandeau « PHOTO D'ILLUSTRATION » = `media_caption` |
| « Vote pour découvrir les résultats » | `poll.results_visible = false` : votes et pourcentages valent `null` tant que l'utilisateur n'a pas voté |
| ❤ 0 · 💬 0 | `likes_count`, `is_liked` (null si non connecté), `comments_count` |

Formats (`type.code`) : `text` (Discussion), `poll` (Sondage), `photos` (Photos + texte, 1 à 10 photos), `photo` (Photo + description), `video` (Vidéo).

### 📱 `GET /community/posts`

| Paramètre | Description |
|---|---|
| `filter` | `discussions`, `polls`, `photos` (photos + photo), `videos` — absent = « Tout » |
| `neighborhood_id` | Publications d'un quartier |
| `author` | Publications d'un utilisateur (son `id`) |
| `limit`, `cursor` | Du plus récent au plus ancien ; page suivante : `cursor = meta.next_cursor` |

`meta` : `filters` (`[{code, label, count}]` → « Discussions 1 », « Sondages 1 »), `all_count` (« Tout 3 », « 3 publication(s) »), `next_cursor`.

### 📱 `GET /community/posts/{id}`

### 👤 `POST /media` — envoyer une photo ou une vidéo

Corps brut, comme les CV : `Content-Type: image/jpeg | image/png | video/mp4 | video/quicktime`, signé sur les octets du fichier.

- Photos : JPEG ou PNG, 10 Mo max. **Les métadonnées (position GPS, appareil…) sont supprimées** par le serveur. L'orientation EXIF étant retirée, envoyer l'image déjà orientée (`UIImage.jpegData`, `Bitmap.compress` le font). HEIC non accepté : convertir en JPEG.
- Vidéos : MP4 ou MOV, 50 Mo max. Le serveur ne peut pas nettoyer les métadonnées vidéo : exporter **sans la localisation** (iOS : `AVAssetExportSession` avec `metadata = []`).
- 20 envois par heure. Un média non publié sous 24 h est supprimé.

**201** → `{ "id": "…", "kind": "image", "mime": "image/jpeg", "url": "https://api.myclermont.fr/media/2026/10/….jpg", "width": 1200, "height": 900, "size_bytes": 184320 }`

**Erreurs** : `unsupported_media` 415, `payload_too_large` 413

### 👤 `POST /community/posts` — « Créer un post »

| Champ | `text` | `poll` | `photos` | `photo` | `video` |
|---|---|---|---|---|---|
| `type` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `body` (≤ 2000) | ✔ texte | ✔ question (≤ 300) | légende facultative | description facultative | légende facultative |
| `poll_options` | — | ✔ 2 à 6 réponses (≤ 80 car.) | — | — | — |
| `media_ids` | — | — | ✔ 1 à 10 photos | ✔ 1 photo | ✔ 1 vidéo |

```json
{ "type": "poll", "body": "Quel type de sortie préférez-vous ce week-end ?", "poll_options": ["Concert", "Randonnée", "Restaurant"] }
```

Le quartier affiché est celui du profil de l'auteur. 10 publications par heure. La première publication du jour rapporte +5 points.

**201** → objet `post`. **Erreurs** : `validation_failed` 422 (dont « Média introuvable ou expiré »), `too_many_requests` 429

### 👤 `DELETE /community/posts/{id}` — par son auteur (`not_author` 403 sinon)

### 👤 `PUT` / `DELETE /community/posts/{id}/like`

Idempotent → `{ "id": "…", "is_liked": true, "likes_count": 4 }`

### 👤 `PUT /community/posts/{id}/vote`

```json
{ "option_id": 12 }
```

Un vote définitif par personne (+1 point). **200** → objet `poll` avec les résultats. **Erreurs** : `already_voted` 409, `not_a_poll` 409

### 📱 `GET /community/posts/{id}/comments?cursor=`

Du plus ancien au plus récent : `[{id, author, body, created_at}]`, `meta.next_cursor`.

### 👤 `POST /community/posts/{id}/comments` `{ "body": "…" }` (≤ 1000, 30 par heure) · 👤 `DELETE /community/comments/{id}`

### 👤 `POST /community/posts/{id}/report`

```json
{ "reason": "spam", "details": null }
```

`reason` : `spam`, `harassment`, `hate`, `inappropriate`, `false_info`, `other`. Au 5ᵉ signalement, la publication est masquée automatiquement en attente de modération (`php bin/console community:moderation`).

---

## Services

Module Services : annuaire de professionnels. Lecture sans compte.

### Objet `provider` (carte)

```json
{
  "id": "…",
  "name": "Atelier Plomberie des Puys",
  "category": { "slug": "maison-travaux", "name": "Maison & travaux", "icon": "hammer" },
  "city": "Clermont-Ferrand",
  "neighborhood": null,
  "summary": "Dépannage et entretien des installations sanitaires…",
  "logo_url": null,
  "price_label": "Sur devis",
  "rating": { "value": 4.8, "count": 0, "is_verified": false, "label": "note de démonstration" },
  "badge": "DÉMO",
  "source": null,
  "is_favorite": false
}
```

`rating` : moyenne des **avis vérifiés myClermont** (`is_verified: true`, `label: "3 avis"`), sinon note indicative clairement signalée (`is_verified: false`, ex. note de démo ou d'une source importée), sinon `null` (« Pas encore d'avis »).

### 📱 `GET /services`

| Paramètre | Description |
|---|---|
| `category` | Slug (`maison-travaux`, `services-domicile`, `informatique`, `sport-bien-etre`, `beaute-sante`, `cours-formation`, `animaux`, `evenementiel`, `auto-mobilite`) |
| `q` | « Service, métier, quartier… » (nom, mots-clés métier, prestations, catégorie, ville, quartier) |
| `sort` | `relevance` (« Pertinence », défaut) ou `rating` (« Mieux notés ») |
| `favorites=1` | « Favoris » (compte requis) |
| `page`, `limit` | Pagination par page |

`meta` : `categories` (« Explorer les catégories » avec `count`), `all_count` (« Tous 10 »), `total` (« 10 professionnel(s) »), `page`, `next_page`.

### 📱 `GET /services/categories`

### 📱 `GET /services/{id}`

Ajoute à la carte :

```json
{
  "cover_url": null,
  "hours_label": "Lun–Ven · 8h–18h (démo)",
  "description": "Dépannage et entretien des installations sanitaires…",
  "services": ["Plomberie", "Dépannage", "Installation"],
  "service_area": { "latitude": 45.7772, "longitude": 3.087, "radius_km": 8 },
  "phone": null,
  "website_url": null,
  "reviews": [],
  "notice": "Fiche fictive : aucune demande n'est transmise à un professionnel.",
  "quote": { "mode": "form", "url": null, "label": "Demander un devis" },
  "my_quote_request": null,
  "can_review": false,
  "share_url": "https://myclermont.fr/services/…"
}
```

| Section | Champ |
|---|---|
| Horaires / Tarif / Secteur / Catégorie | `hours_label`, `price_label`, `city`, `category.name` |
| Prestations proposées | `services` |
| Zone d'intervention (cercle sur la carte) | `service_area` (`radius_km` null = pas de cercle) |
| Avis MyClermont | `reviews` (10 derniers) ; vide → « Aucun avis vérifié » |
| Bouton « Demander un devis » | `quote.mode` : `form` → formulaire ; `external` → ouvrir `quote.url` ; `none` → fiche archivée |

### 📱 `POST /services/{id}/quote` — « Demander un devis »

| Champ | Type | Requis |
|---|---|---|
| `name` | string 2–120 | ✔ |
| `email` | e-mail | ✔ |
| `phone` | string \| null | |
| `postal_code` | 5 chiffres \| null | |
| `preferred_date` | `AAAA-MM-JJ` \| null | |
| `message` | string 10–3000 | ✔ |

**201** → `{ "id": "…", "status": "pending", "message": "Ta demande de devis a été transmise." }` (envoyée par e-mail au professionnel dans la minute).

**Erreurs** : `quote_external` 409 (`details.url`), `provider_inactive` 409, `quote_already_sent` 429 (24 h par appareil), `validation_failed` 422

### 👤 `PUT /services/{id}/review` — laisser un avis vérifié

```json
{ "rating": 5, "comment": "Intervention rapide et soignée." }
```

Possible uniquement si l'utilisateur, connecté, a demandé un devis à ce professionnel et que la demande a été transmise (`can_review` sur la fiche). Un avis par personne (une nouvelle note remplace l'ancienne). **200** → nouvel objet `rating`. **Erreurs** : `review_not_allowed` 403

### Favoris

Type **`service`** : `PUT /users/me/favorites/service/{id}`.

---

## Mobilité

Module Mobility.

### Qui calcule quoi

| Mode | Calculé par | Pourquoi |
|---|---|---|
| **Voiture, à pied, vélo** | **l'app**, avec le service de cartes du téléphone (MapKit `MKDirections` sur iOS, Google Maps / Directions sur Android) | Gratuit, trafic en temps réel, plusieurs itinéraires, étapes « Tournez à gauche… », tracé : c'est exactement ce que montrent tes écrans |
| **Transports en commun (T2C)** | **le serveur**, via l'API Navitia | Horaires du réseau ; la clé d'API reste secrète côté serveur, réponses mises en cache |
| **Info trafic T2C** | **le serveur**, via Navitia | Idem |

Pour l'onglet « Tous », l'app lance en parallèle ses calculs voiture / à pied et `GET /mobility/journeys`, puis fusionne les résultats.

### 📱 `GET /mobility/destinations` — « Destinations rapides »

```json
{ "success": true, "data": [ { "id": "gare-sncf", "name": "Gare SNCF", "address": "…", "icon": "train", "latitude": 45.7789, "longitude": 3.1001 } ] }
```

Liste modifiable en base (`mobility_destinations`). ⚠️ Coordonnées initiales approximatives, à vérifier.

### 📱 `GET /mobility/journeys` — transports en commun

| Paramètre | Description |
|---|---|
| `from`, `to` | `latitude,longitude` (métropole clermontoise uniquement) ; « Partir de ma position » = position GPS du téléphone |
| `datetime` | ISO 8601 (défaut : maintenant) |
| `represents` | `departure` (« Partir à ») ou `arrival` (« Arriver avant ») |

60 recherches par heure et par appareil.

**200**
```json
{
  "success": true,
  "data": [
    {
      "mode": "transit",
      "duration_seconds": 1080,
      "departure_at": "2026-10-28T08:31:00+01:00",
      "arrival_at": "2026-10-28T08:49:00+01:00",
      "transfers": 0,
      "walking_seconds": 420,
      "co2_grams": 85,
      "is_fastest": true,
      "sections": [
        { "type": "walk", "duration_seconds": 240, "from": "Place de Jaude", "to": "Jaude", "line": null,
          "steps": [ { "instruction": "Tournez à gauche sur Place de Jaude", "distance_m": 123 } ], "geometry": { "type": "LineString", "coordinates": [[3.0816, 45.7773]] } },
        { "type": "transit", "duration_seconds": 660, "from": "Jaude", "to": "Gare SNCF",
          "line": { "code": "A", "label": "A", "mode": "Tramway", "direction": "…", "network": "T2C", "color": "#E2001A", "text_color": "#FFFFFF" },
          "stops_count": 6, "steps": [], "geometry": { "…": "…" } }
      ]
    }
  ],
  "meta": { "count": 3, "message": null }
}
```

- `sections[].type` : `walk`, `transit`, `transfer`, `wait`, `bike`, `car`.
- `geometry` : GeoJSON (coordonnées `[longitude, latitude]`) pour tracer le trajet.
- Aucun itinéraire → `data: []` et `meta.message`.

**Erreurs** : `mobility_unavailable` 503 (clé Navitia non configurée), `mobility_provider_error` 502, `date_out_of_bounds` 422, `validation_failed` 422

### 📱 `GET /mobility/places?q=jaude`

Autocomplétion des champs Départ / Arrivée (arrêts T2C, adresses, lieux) : `[{id, name, type: stop|address|poi, latitude, longitude}]`. Sur iOS, `MKLocalSearchCompleter` peut suffire pour les adresses.

### 📱 `GET /mobility/traffic` — « Info trafic T2C »

```json
{
  "success": true,
  "data": [
    { "id": "…", "status": "active", "severity": { "name": "perturbée", "effect": "SIGNIFICANT_DELAYS", "color": "#FF8800" },
      "title": "Travaux", "message": "Ligne B : arrêt Jaude non desservi…", "starts_at": "…", "ends_at": "…",
      "lines": [ { "code": "B", "name": "…", "color": "#…" } ] }
  ],
  "meta": { "available": true, "active_count": 1 }
}
```

Sans clé Navitia : `data: []` et `meta.available = false` → afficher « pas encore disponible » (comme sur ta maquette).

---

## Petites annonces

Module Classifieds. Lecture **sans compte** ; déposer, contacter, signaler : compte obligatoire.

### Objet `classified` (carte)

```json
{
  "id": "1b2c…",
  "title": "Don de plantes",
  "category": { "slug": "dons", "name": "Dons & gratuit", "icon": "gift", "color": "#4A9A9A" },
  "price": { "cents": 0, "is_free": true, "label": "Gratuit" },
  "condition": { "code": "good", "label": "Bon état" },
  "location": "Montferrand",
  "cover_url": null,
  "status": "active",
  "badge": "DÉMO",
  "source": null,
  "is_mine": false,
  "is_favorite": false,
  "published_at": "2026-10-28T00:20:00Z"
}
```

| Élément de l'écran | Champ |
|---|---|
| Visuel | `cover_url` ; sans photo → icône `category.icon` sur la couleur `category.color` (comme sur les maquettes) |
| « 90 € » / « Gratuit » | `price.label` |
| « Bon état » | `condition.label` |
| Pastille « TON ANNONCE » | `is_mine` |
| Statut | `active`, `reserved` (afficher « Réservé »), `sold`, `expired` |

États (`condition.code`) : `new`, `very_good`, `good`, `fair`, `for_parts`.

### 📱 `GET /classifieds`

| Paramètre | Description |
|---|---|
| `category` | Slug (`maison-deco`, `high-tech`, `mode`, `sport-loisirs`, `bricolage`, `enfants`, `livres-media`, `vehicules`, `dons`, `autre`) |
| `q` | « Que recherches-tu ? » (titre, description, catégorie, lieu) |
| `free=1` | Bouton « Dons » / « Dons gratuits uniquement » |
| `max_price` | « Budget maximum » en **euros** |
| `neighborhood_id` | Filtre « Quartier » |
| `favorites=1` | « Favoris » (compte requis) |
| `sort` | `recent` (défaut), `price_asc`, `price_desc` (bouton ⇅) |
| `limit`, `cursor` | Pagination (`meta.next_cursor`) |

`meta` : `categories` (puces avec `count`), `all_count` (« 12 annonces », « Tout 12 »), `free_count` (« 1 dons »), `total` (« 12 résultat(s) », première page uniquement), `sort` (« récentes »), `next_cursor`.

Connecté, les annonces des personnes bloquées (dans un sens ou dans l'autre) sont masquées.

### 📱 `GET /classifieds/filters`

Catégories, états, tris et limites (titre, description, 6 photos, prix max) pour construire le formulaire de dépôt.

### 📱 `GET /classifieds/{id}`

Ajoute à la carte :

```json
{
  "photos": [],
  "description": "Boutures de plantes vertes à récupérer gratuitement.",
  "seller": { "id": "…", "username": "Habitant_11", "initials": "H1", "color_index": 5, "is_me": false,
              "member_since": "2026-01-01", "active_listings": 1, "sold_listings": 0 },
  "safety_tips": [ { "icon": "people", "text": "Rencontre l'autre personne dans un lieu public et fréquenté." }, "…" ],
  "notice": "Aucune transaction ni messagerie réelle.",
  "contact": { "mode": "message", "url": null, "label": "Contacter" },
  "conversation_id": null,
  "expires_at": "2026-12-27T00:20:00Z",
  "share_url": "https://myclermont.fr/annonces/1b2c…"
}
```

| Élément | Champ |
|---|---|
| « Publiée il y a 26 secondes » | `published_at` (relatif calculé par l'app) |
| Carte vendeur | `seller` (null pour une annonce importée) |
| Conseils de sécurité | `safety_tips` (modifiables dans `config/classifieds.php`, sans mise à jour de l'app) |
| Bouton « Contacter » | `contact.mode` : `message` → messagerie (voir ci-dessous ; si `conversation_id` est renseigné, ouvrir directement cette conversation) ; `external` → ouvrir `contact.url` ; `none` → désactivé avec `contact.label` (« Ton annonce », « Vendu », « Annonce expirée ») |

### 👤 `POST /classifieds` — « Déposer une annonce » (gratuit)

Les photos sont envoyées avant via `POST /media` (voir Communauté), puis référencées par `media_ids`.

| Champ | Type | Requis | Notes |
|---|---|---|---|
| `title` | string 3–100 | ✔ | |
| `category` | slug | ✔ | |
| `description` | string ≤ 4000 | | |
| `is_free` | booléen | | « Je donne gratuitement » → prix 0 |
| `price_cents` | entier > 0 | si non gratuit | en **centimes** (90 € = 9000), 100 000 € max |
| `condition` | code d'état | ✔ | |
| `location_label` | string ≤ 100 | | « Quartier / commune » ; vide → quartier du profil (« Par défaut : Centre-ville ») |
| `neighborhood_id` | entier | | Pour le filtre par quartier |
| `media_ids` | liste (0 à 6) | | Ordre = ordre d'affichage ; la 1ʳᵉ est la couverture |

```json
{ "title": "Bureau en bois", "category": "maison-deco", "description": "Bureau en bois massif.", "is_free": false, "price_cents": 9000, "condition": "good", "media_ids": ["…"] }
```

En ligne pour 60 jours. 10 dépôts par jour, 50 annonces actives maximum par personne. La première annonce du jour rapporte +5 points ; un objet donné (annonce gratuite passée en « vendu ») +10.

**201** → fiche complète. **Erreurs** : `validation_failed` 422, `too_many_listings` 409, `too_many_requests` 429

### 👤 `PATCH /classifieds/{id}`

Mêmes champs, tous facultatifs. `media_ids` = liste **complète** dans le nouvel ordre (photos déjà présentes + nouvelles ; les photos retirées sont supprimées). **Erreurs** : `not_owner` 403, `classified_expired` 409

### 👤 `PATCH /classifieds/{id}/status`

```json
{ "status": "sold" }
```

`active` (remettre en ligne ; une annonce expirée repart pour 60 jours), `reserved`, `sold`. `"renew": true` prolonge de 60 jours.

### 👤 `DELETE /classifieds/{id}` · 👤 `GET /users/me/classifieds` (« Mes annonces », tous statuts, avec `expires_at`)

### 👤 `POST /classifieds/{id}/contact` — bouton « Contacter »

Ouvre la conversation avec le vendeur (ou retrouve celle qui existe) et envoie le premier message.

```json
{ "message": "Bonjour, les boutures sont-elles toujours disponibles ?", "client_id": "8f1c2a" }
```

**201** → `{ "conversation_id": "…", "message": { "…": "objet message" } }` → ouvrir l'écran de conversation.

**Erreurs** : `own_classified` 409, `classified_unavailable` 409, `contact_external` 409 (`details.url`), `user_blocked` 403, `too_many_requests` 429 (10 nouveaux contacts / heure)

### 👤 `POST /classifieds/{id}/report`

`reason` : `scam`, `prohibited`, `duplicate`, `wrong_category`, `offensive`, `other`. Masquée automatiquement au 3ᵉ signalement.

### Favoris

Type **`classified`** : `PUT /users/me/favorites/classified/{id}`.

---

## Messagerie

Module Messaging : onglet « Chat ». Compte obligatoire pour toutes les routes. Les conversations sont créées par les modules (aujourd'hui : `POST /classifieds/{id}/contact`). Une conversation = deux personnes + un élément (une annonce) : contacter deux fois le même vendeur pour la même annonce rouvre la même conversation.

### Temps réel

Pas de connexion permanente (WebSocket) : plus simple à héberger avec PHP et suffisant pour des petites annonces.

| Situation | Ce que fait l'app |
|---|---|
| Conversation ouverte à l'écran | `GET /conversations/{id}/messages?after=<newest_id>` toutes les `poll_interval` secondes (5 s), et à chaque retour au premier plan |
| Liste des conversations ouverte | `GET /conversations` au retour sur l'écran et après un pull-to-refresh |
| Badge de l'onglet Chat | `GET /conversations/unread-count` au lancement / au retour au premier plan (requête très légère) |
| App fermée / en arrière-plan | Notification push (voir plus bas) |

### Objet `conversation` (boîte de réception)

```json
{
  "id": "c0nv…",
  "context": { "type": "classified", "id": "1b2c…", "title": "Bureau en bois", "subtitle": "90 €", "image_url": null, "icon": "sofa", "color": "#4FA77E", "status": "active" },
  "other": { "id": "…", "username": "Habitant_11", "initials": "H1", "color_index": 5 },
  "my_role": "buyer",
  "last_message": { "body": "Oui, toujours disponible !", "is_mine": false, "created_at": "2026-10-28T00:25:12.481Z" },
  "unread_count": 1,
  "updated_at": "2026-10-28T00:25:12.000Z"
}
```

`context.status` vaut `sold` / `expired` / `deleted` quand l'annonce n'est plus disponible : l'afficher dans l'en-tête de la conversation.

### Objet `message`

```json
{ "id": 812, "uid": "…", "client_id": "8f1c2a", "type": "text", "body": "Bonjour…", "is_mine": true, "sender_id": "…", "has_link": false, "created_at": "2026-10-28T00:21:05.123Z" }
```

`has_link: true` → afficher sous la bulle l'avertissement « Méfie-toi des liens de paiement reçus par message ».

### 👤 `GET /conversations?archived=1&cursor=…`

Plus récentes en premier. `meta.unread_total` (badge), `meta.next_cursor`. `archived=1` : conversations masquées.

### 👤 `GET /conversations/unread-count` → `{ "unread_total": 3 }`

### 👤 `GET /conversations/{id}`

En-tête : `context`, `other` (+ `blocked_by_me`), `my_role`, `can_reply` (false si l'un a bloqué l'autre), `poll_interval`.

### 👤 `GET /conversations/{id}/messages`

| Paramètre | Usage |
|---|---|
| (aucun) | Ouverture : les 50 derniers messages |
| `before=<oldest_id>` | Charger les messages précédents (défilement vers le haut) |
| `after=<newest_id>` | Nouveaux messages (interrogation périodique) |

Messages toujours renvoyés du plus ancien au plus récent. `meta` : `oldest_id`, `newest_id`, `has_more`, `other_last_read_id` (afficher « Vu » sous mes messages dont l'id ≤ cette valeur).

### 👤 `POST /conversations/{id}/messages`

```json
{ "body": "Je peux passer ce soir vers 18 h ?", "client_id": "a7d9e2" }
```

`client_id` : identifiant généré par l'app pour chaque message (UUID). Si le réseau coupe et que l'app renvoie le même message, il n'est pas dupliqué. 2000 caractères max, 120 messages / heure.

**201** → objet `message`. **Erreurs** : `user_blocked` 403, `conversation_not_found` 404, `validation_failed` 422

### 👤 `POST /conversations/{id}/read` `{ "message_id": 812 }`

Marque comme lu (jusqu'au dernier message si `message_id` absent). À appeler à l'ouverture et à la réception de nouveaux messages pendant que la conversation est affichée. → `{ "unread_total": 2 }`

### 👤 `DELETE /conversations/{id}`

Masque la conversation pour moi (elle réapparaît si l'autre personne écrit).

### 👤 `POST /conversations/{id}/report`

```json
{ "reason": "scam", "details": "Me demande de payer par un lien", "block": true }
```

`reason` : `scam`, `harassment`, `spam`, `other`. `block: true` bloque aussi la personne.

### 👤 `PUT` / `DELETE /users/{id}/block`

Bloquer / débloquer un utilisateur (`id` public). Une personne bloquée ne peut plus vous écrire ni ouvrir de conversation, et vos annonces respectives disparaissent de vos listes.

### Notifications push

À chaque message, une notification est mise en file (`push_outbox`) pour le destinataire, regroupée par conversation (plusieurs messages rapprochés = une seule notification mise à jour). Titre = pseudo de l'expéditeur, texte = début du message, données : `{ "type": "message", "conversation_id": "…" }` → ouvrir la conversation au tap.

L'app doit envoyer son jeton push : `PATCH /devices/me { "push_token": "…" }` (après l'autorisation de l'utilisateur, et à chaque changement de jeton). Les notifications vont aux appareils sur lesquels l'utilisateur est connecté.

⚠️ L'envoi réel vers Apple / Google reste à brancher (voir docs/MODULES.md › Messagerie) : en l'état, les notifications sont journalisées.

---

## Explorer

Module Explore : écran d'accueil. Lecture **sans compte** ; connecté → salutation personnalisée, stories vues, favoris.

### 📱 `GET /explore?section=restaurants` — tout l'écran en un appel

`section` (facultatif) = puce sélectionnée ; filtre les exclusivités et « À découvrir ».

```json
{
  "success": true,
  "data": {
    "header": {
      "greeting": "Bonsoir, Alex",
      "date_label": "mer. 28 oct.",
      "local_time": "2026-10-28T00:40:00+01:00",
      "weather": { "condition": "cloudy", "label": "Nuageux", "icon": "cloud", "temperature": 13, "unit": "°C" }
    },
    "sections": [ { "slug": "restaurants", "name": "Restaurants", "icon": "fork-knife", "is_chip": true } ],
    "stories": [ { "id": "…", "title": "Les volcans", "subtitle": "My Clermont · Story démo", "cover_url": "https://…", "icon": "mountain", "seen": false } ],
    "flash": {
      "id": "…", "badge": "ÉDITION FLASH · DÉMO", "tag": "HOT", "title": "Clermont by Night", "subtitle": "Une soirée au cœur de la ville",
      "image_url": "https://…", "location": "Centre-ville · Clermont-Ferrand", "link": { "type": "screen", "id": "agenda", "url": null }
    },
    "highlights": [ "…" ],
    "offers": [
      { "id": "…", "partner": "Partenaire démo", "title": "Brunch au centre-ville", "subtitle": "Offre de démonstration", "discount": "-30 %",
        "icon": "fork-knife", "image_url": null, "section": { "slug": "restaurants", "name": "Restaurants" }, "badge": "Exclusivité My Clermont",
        "ends_at": null, "stock_left": null, "is_premium_only": false, "is_favorite": false }
    ],
    "discover": {
      "items": [
        { "id": "…", "section": { "slug": "bons-plans", "name": "Bons plans" }, "title": "Brunch au centre-ville", "subtitle": "Exclusivité · -30 %",
          "icon": "fork-knife", "image_url": null, "link": { "type": "offer", "id": "…", "url": null }, "is_favorite": false }
      ],
      "total": 13,
      "next_page": null
    }
  }
}
```

| Élément de l'écran | Champ |
|---|---|
| « Bonsoir, Alex » | `header.greeting` (heure de Clermont ; « Bonjour » de 5 h à 18 h) |
| ☁ 13° · mer. 28 oct. | `header.weather` (`null` si la météo est indisponible → masquer), `header.date_label` |
| Puces | `sections` où `is_chip = true` (+ « Tout » = pas de `section`) |
| Stories | `stories` ; anneau coloré si `seen = false` (`null` si non connecté → l'app mémorise localement) |
| Événement Flash | `flash` (`null` → masquer la section) ; tap → `flash.link` |
| Exclusivités | `offers` |
| « À découvrir · 13 adresses » | `discover.items`, `discover.total` ; libellé en capitales = `section.name` |

La partie commune est en cache 60 s côté serveur : l'écran se charge en une requête légère.

### Liens profonds (`link`)

Tout élément cliquable porte `link: { type, id, url }`. L'app associe chaque type à un écran :

| `type` | Écran | `id` |
|---|---|---|
| `event`, `housing`, `job`, `classified`, `service`, `offer`, `post`, `story` | Fiche correspondante | uid de l'élément |
| `screen` | Écran d'un module | `agenda`, `housing`, `jobs`, `classifieds`, `services`, `community`, `mobility`, `chat`… |
| `url` | Navigateur intégré | — (`url`, HTTPS) |

### 📱 `GET /explore/discover?section=&page=2`

Suite de « À découvrir » (20 par page) : `meta.total`, `meta.next_page`.

### 📱 `GET /explore/stories/{id}`

```json
{ "id": "…", "title": "Sortir ce soir", "subtitle": "My Clermont · Story démo", "seen": false,
  "slides": [ { "media_type": "image", "media_url": "https://…", "duration_ms": 5000, "title": "La ville s'anime",
                "body": "Explore Clermont-Ferrand et ses environs avec My Clermont.", "caption": "Contenu de démonstration",
                "cta": { "label": "Voir l'agenda", "link": { "type": "screen", "id": "agenda", "url": null } } } ] }
```

Barres de progression = nombre de `slides` ; durée d'une image = `duration_ms` (vidéo : sa propre durée). `cta` null = pas de bouton.

### 👤 `POST /explore/stories/{id}/view`

À appeler à la fin (ou à la fermeture) d'une story. Une story modifiée ensuite redevient « non vue ». **204**.

### 📱 `GET /explore/offers?section=` · 📱 `GET /explore/offers/{id}`

La fiche ajoute `description`, `conditions`, `address`, `coordinates`, `redemption_mode`, `members_only`, `notice`, `is_active`, et `my_claim` si l'utilisateur en a déjà profité. **Le code promo n'apparaît jamais avant d'en profiter.**

### 👤 `POST /explore/offers/{id}/claim` — « Profiter de l'offre »

**201** (première fois) / **200** (déjà utilisée : même contenu, `already_claimed: true`, rien n'est recompté) :

```json
{ "redemption_mode": "code", "code": "BRUNCH30", "external_url": null, "show": null, "conditions": "Une utilisation par personne.", "already_claimed": false }
```

| `redemption_mode` | Affichage |
|---|---|
| `code` | Afficher `code` (copier) |
| `show` | Écran plein à présenter en caisse avec `show.title`, `show.discount`, `show.partner` et l'heure serveur `show.server_time` (une capture d'écran ancienne se repère) |
| `external` | Ouvrir `external_url` (site du partenaire) |

**Erreurs** : `offer_inactive` 409, `offer_sold_out` 409, `premium_required` 402, `offer_not_found` 404

### 📱 `GET /explore/search?q=brunch` — « Un lieu, une sortie, une envie… »

Recherche dans tous les modules actifs, 5 résultats par module, regroupés :

```json
{
  "success": true,
  "data": [
    { "type": "offer", "label": "Bons plans", "items": [ { "id": "…", "title": "Brunch au centre-ville", "subtitle": "Partenaire démo · -30 %", "icon": "tag", "link": { "type": "offer", "id": "…", "url": null } } ] },
    { "type": "event", "label": "Agenda", "items": [ "…" ] }
  ],
  "meta": { "query": "brunch", "count": 3 }
}
```

Groupes possibles : `place`, `event`, `offer`, `service`, `housing`, `job`, `classified`, `discover`. Tap → `link`. 2 à 100 caractères.

### 📱 `GET /explore/weather`

Même objet que `header.weather` (mis à jour toutes les 15 min).

### Favoris

Types **`offer`** (exclusivités) et **`explore_item`** (« À découvrir »).

---

## Carte & lieux

Module Places. Lecture sans compte ; avis : compte obligatoire.

### Stratégie de chargement (rapidité)

1. **Au lancement** : l'app affiche immédiatement les repères **gardés sur le téléphone** depuis la dernière visite.
2. Elle appelle `GET /places/pins?near=<position>` avec `If-None-Match: <ETag mémorisé>` :
   - rien n'a changé → **304, aucune donnée** (cas le plus fréquent) ;
   - sinon → tous les repères en format compact, **triés du plus proche au plus loin**, à mémoriser avec le nouvel ETag.
3. Premier lancement / connexion lente : `GET /places/pins/stream` (Server-Sent Events) envoie d'abord les repères à moins de 1 km, puis 3 km, puis 10 km, puis le reste ; la carte se remplit autour de l'utilisateur pendant que le reste arrive.
4. La fiche d'un lieu (`GET /places/{id}`) n'est chargée qu'au toucher d'un repère.

Le regroupement des repères proches (« clusters ») se fait sur le téléphone (MapKit `clusteringIdentifier`, Google Maps Utils), avec les données déjà reçues : zoomer ou déplacer la carte ne déclenche **aucun** appel réseau.

Côté serveur, les repères sont gardés en mémoire : aucune requête SQL pour servir la carte (5 000 lieux filtrés et triés en ~8 ms, 72 Ko compressés ; 179 lieux ≈ 3 Ko).

### 📱 `GET /places/pins`

| Paramètre | Description |
|---|---|
| `near` | `latitude,longitude` (position de l'utilisateur ou centre de la carte) → tri par distance + champ `dist` |
| `category` | Slug de catégorie (`restaurants`, `cafes`, `culture`, `parcs`, `carburant`…) |
| `filter` | `partners` (« Partenaires ») ou `must_see` (« Incontournables ») |

En-tête `If-None-Match` (facultatif) : ETag de la réponse précédente.

**200** (en-tête `ETag`)
```json
{
  "success": true,
  "data": {
    "version": "3f9a1c0b2d4e5f60",
    "fields": ["id", "lat", "lng", "cat", "flags", "name", "dist"],
    "categories": [
      { "slug": "monuments", "name": "Monuments & places", "icon": "star", "color": "#D63A5E", "count": 12 },
      { "slug": "restaurants", "name": "Restaurants", "icon": "fork-knife", "color": "#E2703A", "count": 48 }
    ],
    "filters": { "all": 179, "partners": 1, "must_see": 12 },
    "total": 179,
    "pins": [
      ["9c1f…", 45.7773, 3.0816, 0, 2, "Place de Jaude", 42],
      ["a7b2…", 45.778, 3.083, 1, 1, "Le Bistrot", 150]
    ]
  }
}
```

| Colonne | Sens |
|---|---|
| `cat` | Index dans `categories` → icône et couleur du repère |
| `flags` | `1` = partenaire, `2` = incontournable (cumulables : `3`) |
| `dist` | Mètres depuis `near` (`null` sans `near`) |

Puces : « Tous 179 » = `filters.all`, « Partenaires 1 » = `filters.partners`, « Incontournables » = `filters.must_see`, puis `categories`. « 179 lieux » = `total`.

**304** : pas de contenu, garder les repères mémorisés.

### 📱 `GET /places/pins/stream`

Mêmes paramètres. Réponse `text/event-stream`, fermée par le serveur à la fin :

```
event: meta
data: {"version":"3f9a…","fields":[…],"categories":[…],"filters":{…},"total":179}

event: pins
data: {"ring":1000,"pins":[["9c1f…",45.7773,3.0816,0,2,"Place de Jaude",42], …]}

event: pins
data: {"ring":3000,"pins":[…]}

event: pins
data: {"ring":10000,"pins":[…]}

event: pins
data: {"ring":null,"pins":[…]}

event: done
data: {"total":179}
```

Sans `near`, le centre est la place de Jaude. iOS : lire `URLSession.bytes(for:)` ligne par ligne ; Android : OkHttp `okhttp-sse` (`EventSources`).

### 📱 `GET /places/nearby?near=…&category=&filter=&limit=20&offset=0`

Liste « Touche un repère » / « 179 lieux » : cartes complètes du plus proche au plus loin, `meta.total`, `meta.next_offset`.

### Objet `place` (carte)

```json
{
  "id": "9c1f…", "name": "Place de Jaude", "tagline": "Le grand salon à ciel ouvert de Clermont",
  "category": { "slug": "monuments", "name": "Monuments & places", "icon": "star", "color": "#D63A5E" },
  "cover_url": null, "latitude": 45.7773, "longitude": 3.0816, "address": "Place de Jaude",
  "distance": { "meters": 42, "label": "40 m" },
  "open_status": { "state": "open", "label": "Ouvert 24 h/24", "hours_label": "Accès libre · 24 h/24" },
  "price_label": "Gratuit",
  "rating": { "value": 4.8, "count": 0, "is_verified": false, "label": "note démo" },
  "is_partner": false, "is_must_see": true, "is_favorite": false
}
```

`open_status.state` : `open`, `closed` (`label` « Fermé · ouvre à 14 h »), `unknown` (pas d'horaires structurés : afficher seulement `hours_label`). Calculé à l'heure de Clermont, plages de nuit comprises.

### 📱 `GET /places/search?q=musée&near=…`

Barre « Restos, carburant, musées, parcs… » : 30 cartes max, triées par distance si `near` est fourni.

### 📱 `GET /places/{id}?near=…` — fiche

Ajoute à la carte :

| Section de l'écran | Champ |
|---|---|
| Bandeau « À DÉCOUVRIR » | `badge` |
| Texte d'introduction | `description` |
| En chiffres | `facts` : `[{icon, value: "35 000 m²", label: "de superficie"}]` |
| À ne pas manquer | `highlights` : `[{icon, title, text}]` |
| Repères | `timeline` : `[{year: "1903", text}]` |
| Infos pratiques | `practical` : `[{icon, label: "Horaires", value}]` (lignes vides déjà retirées) |
| Sur la carte / Y aller | `directions` → ouvrir Mobilité avec cette destination |
| Le conseil My Clermont | `tip` |
| Avis | `rating`, `reviews` (10 derniers), `my_review` ; `external_reviews` : `null` (« Avis Google · Non connectés ») |
| Sources | `sources` |
| Favori / Partager | `is_favorite`, `share_url` |

Sections vides (`[]` / `null`) → masquées.

### 👤 `PUT /places/{id}/review` — « Donner mon avis »

```json
{ "rating": 5, "comment": "Superbe en fin de journée." }
```

Un avis par personne, modifiable. **200** → nouvel objet `rating`.

### Favoris

Type **`place`**. Les lieux en favori font progresser les badges **Explorateur** (25 lieux), **Passion volcan** (tag `volcan`) et **Gourmand** (tag `gourmand`).

---

## Codes d'erreur

| HTTP | `error.code` | Signification | Action conseillée dans l'app |
|---|---|---|---|
| 400 | `invalid_json` | Corps JSON mal formé | Bug client |
| 401 | `invalid_device_id` | `X-Device-Id` absent ou non UUID | Bug client |
| 401 | `invalid_device_token` | `X-Device-Token` absent | Bug client |
| 401 | `invalid_device` | Appareil inconnu ou jeton faux | **Réenregistrer l'appareil** puis rejouer |
| 401 | `invalid_signature` | Signature absente ou incorrecte | Bug client (voir vecteur de test) |
| 401 | `invalid_timestamp` | `X-Timestamp` absent ou invalide | Bug client |
| 401 | `timestamp_out_of_window` | Horloge décalée de plus de 5 min | Corriger avec `X-Server-Time` puis rejouer **une** fois |
| 401 | `invalid_nonce` | `X-Nonce` absent ou mal formé | Bug client |
| 401 | `replayed_request` | Nonce déjà utilisé | Regénérer un nonce (ne jamais réutiliser) |
| 401 | `invalid_app_key` | `X-Key-Id` inconnu ou révoqué | Forcer la mise à jour de l'app |
| 401 | `unauthenticated` | Route 👤 sans jeton, ou compte supprimé | Écran de connexion |
| 401 | `invalid_token` | Access token invalide | `/auth/refresh` |
| 401 | `token_expired` | Access token expiré | `/auth/refresh` puis rejouer |
| 401 | `token_device_mismatch` | Jeton émis pour un autre appareil | Écran de connexion |
| 401 | `invalid_credentials` | E-mail / mot de passe incorrect | Afficher le message |
| 401 | `invalid_refresh_token` / `refresh_token_expired` | Session terminée | Écran de connexion |
| 403 | `device_blocked` | Appareil bloqué par un administrateur | Message bloquant |
| 403 | `account_disabled` | Compte désactivé | Message bloquant |
| 403 | `platform_mismatch` | `platform` ≠ plateforme de la clé application | Bug client |
| 404 | `not_found` | Route ou ressource inexistante | |
| 405 | `method_not_allowed` | Méthode HTTP non supportée sur cette route | |
| 402 | `premium_required` | Fonctionnalité premium | Proposer l'abonnement |
| 404 | `user_not_found` | Profil inexistant | |
| 404 | `favorite_target_not_found` | Élément à mettre en favori inexistant | |
| 404 | `event_not_found` | Événement inexistant ou non publié | |
| 404 | `listing_not_found` | Annonce inexistante | |
| 404 | `job_not_found` | Offre inexistante | |
| 403 | `not_author` | Suppression d'une publication d'autrui | |
| 403 | `review_not_allowed` | Avis sans demande de devis transmise | Masquer le bouton (`can_review`) |
| 404 | `post_not_found` / `comment_not_found` | Publication / commentaire inexistant ou supprimé | Retirer du fil |
| 404 | `provider_not_found` | Professionnel inexistant | |
| 403 | `not_owner` | Modification de l'annonce d'autrui | |
| 403 | `user_blocked` | Blocage entre les deux personnes | Désactiver la saisie |
| 404 | `classified_not_found` / `conversation_not_found` | Annonce / conversation inexistante | |
| 404 | `story_not_found` / `offer_not_found` | Contenu Explorer inexistant ou terminé | Rafraîchir l'accueil |
| 404 | `place_not_found` | Lieu inexistant ou retiré | Retirer le repère |
| 409 | `email_taken` | E-mail déjà utilisé | Afficher le message |
| 409 | `offer_inactive` / `offer_sold_out` | Offre terminée ou stock épuisé | Afficher le message |
| 409 | `own_classified` / `classified_unavailable` / `classified_expired` | Contact ou modification impossible | |
| 409 | `too_many_listings` | 50 annonces actives atteintes | |
| 409 | `cannot_message_self` | | |
| 409 | `already_voted` / `not_a_poll` | Vote impossible | Afficher les résultats |
| 409 | `quote_external` / `provider_inactive` | Devis sur le site d'origine (`details.url`) / fiche archivée | |
| 415 | `unsupported_media` | Photo / vidéo dans un format refusé | Convertir en JPEG / MP4 |
| 422 | `date_out_of_bounds` | Horaires de transport indisponibles pour cette date | |
| 429 | `quote_already_sent` | Professionnel déjà contacté aujourd'hui | |
| 502 | `mobility_provider_error` | Service d'itinéraires indisponible | Réessayer |
| 503 | `mobility_unavailable` | Transports en commun non configurés | Proposer voiture / à pied |
| 409 | `already_applied` | Déjà postulé à cette offre | Afficher « Candidature envoyée » |
| 409 | `apply_external` | Candidature sur le site du recruteur (`details.url`) | Ouvrir l'URL |
| 409 | `job_inactive` | Offre expirée | |
| 415 | `unsupported_document` | Fichier non PDF | Afficher le message |
| 409 | `contact_external` | Annonce à contacter sur le site d'origine (`details.url`) | Ouvrir l'URL |
| 409 | `listing_inactive` | Annonce archivée | |
| 429 | `contact_already_sent` | Annonceur déjà contacté depuis cet appareil (24 h) | Afficher le message |
| 409 | `event_cancelled` / `event_ended` | Participation impossible | Mettre à jour la fiche |
| 409 | `event_full` | Plus de place | Afficher « Complet » |
| 409 | `account_conflict` | E-mail ou pseudo pris pendant l'inscription | Réessayer |
| 413 | `payload_too_large` | Corps > 1 Mo | |
| 422 | `validation_failed` | Champs invalides (`error.details` : champ → message) | Afficher sous chaque champ |
| 429 | `too_many_requests` | Rate-limit atteint | Attendre `Retry-After` secondes |
| 500 | `server_error` | Erreur interne (journalisée) | Réessayer plus tard |

## Versionnement

Le préfixe `/v1` est figé : tout changement incompatible donnera lieu à `/v2`, servi en parallèle le temps que les anciennes versions de l'app disparaissent. Ajouter un champ à une réponse **n'est pas** un changement incompatible : les apps doivent ignorer les champs inconnus.
