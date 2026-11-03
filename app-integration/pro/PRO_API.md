# API de l'espace commerçant MyClermont Pro

Front : `https://commercant.myclermont.app`. API : `https://api.myclermont.app/api/v1/pro/…`.
Conventions habituelles :
- listes au format `{ items, nextCursor }` ;
- erreurs au format `{ error: { code, message, details } }`, avec `details.reason`, et `details.fields` pour les erreurs de validation.

Une fixture par réponse se trouve dans `pro/fixtures/` (réponses réelles, enregistrées sur une instance de test).

## Session web

| Étape | Détail |
|---|---|
| Connexion / inscription | `POST /pro/auth/login` ou `/pro/auth/signup` → `ProSession` + cookie `mc_pro_rt`. Le cookie est `HttpOnly; Secure; SameSite=Strict; Path=/api/v1/pro/auth` et dure 30 jours si `remember`, sinon c'est un cookie de session. |
| Appels | `Authorization: Bearer <accessToken>` (15 min). Un `401` porte `details.reason` : `token_expired` (rafraîchir puis rejouer) ou `invalid_session` (retour à la connexion). |
| Rafraîchir | `POST /pro/auth/refresh`, sans corps : le cookie suffit. Le front doit envoyer `credentials: "include"`. Le cookie change à chaque fois, et un ancien cookie rejoué ferme toute la session. L'en-tête `Origin` est vérifié. |
| CORS | Origine `https://commercant.myclermont.app`, plus `http://localhost:3000` hors production, avec cookies. |
| Limites | Connexion, inscription, mot de passe oublié/réinitialisation et invitation : 10 appels par 5 min et par IP, puis `429` + `Retry-After`. |

## Routes

Toutes les routes des demandes 1 à 8 sont en place, avec les formats du front. Les seuls écarts sont listés plus bas.

- **Compte**
  - `auth/signup`, `login`, `refresh`, `logout`, `password/forgot`, `password/reset`, `password`
  - `auth/sessions`, `DELETE auth/sessions/{id}`
  - `me` (GET, PATCH, DELETE), `me/email`, `me/notifications`, `me/export`
  - `notifications`, `notifications/read`
- **Établissement**
  - `business`, `business/modules`
  - `team`, `team/invitations` (+ `/{id}/resend`, `GET /{token}`, `POST /{token}/accept`), `team/{id}` (PATCH, DELETE)
  - `partnership`, `partnership/subscribe`, `partnership/cancel`, `partnership/contact-request`
  - `invoices`, `invoices/{id}/pdf`
- **Fiche**
  - `GET` et `PUT /pro/place`, `POST /pro/place/submit`
  - photos : `POST /pro/uploads { purpose: place|deal|event|housing, mime, sizeBytes }` → `PUT uploadUrl` → `POST /pro/uploads/{id}/confirm` → `url`. C'est le même flux que l'app, avec la session pro.
- **Avis et messages**
  - `reviews`, `reviews/{id}/reply` (POST, PUT, DELETE), `reviews/{id}/report` (POST, DELETE)
  - `threads` (+ `/{id}` GET, PATCH, DELETE ; `/{id}/read` ; `/{id}/messages`)
- **Bons plans**
  - `deals` (+ `/{id}` PATCH, DELETE)
  - `deal-codes`, `deal-codes/{code}`, `deal-codes/{code}/redeem`. `{code}` accepte le code seul ou le `qrPayload` de l'app encodé en URL.
- **Événements**
  - `events` (+ `/{id}` PATCH, DELETE ; `/{id}/submit` ; `/{id}/withdraw`). L'`id` est le slug, comme dans `GET /events`.
- **Recrutement, devis, logements**
  - `jobs`, `applications` (+ `?status=refusee` en DELETE pour la purge), `applications/{id}/cv` → `{ url, expiresAt }`. L'URL est valable 10 min et s'ouvre sans session.
  - `services`, `quotes`
  - `housings`, `housing-contacts`
- **Statistiques**
  - `stats?from=&to=` (30 derniers jours par défaut), `stats/export?format=csv`
- **Stories** (b5b4c116)
  - `GET /pro/stories` → `{ items: ProStory[] }` (les 200 dernières, la plus récente d'abord) ; `POST /pro/stories` → `201 ProStory`
  - `PATCH /pro/stories/{id}` : `caption`, `captionStyle`, `cta`, `media`, `startsAt` (seulement avant diffusion), ou `{ "status": "retiree" }`
  - `DELETE /pro/stories/{id}` → `204`. Une story pas encore diffusée est effacée ; une story déjà diffusée est seulement retirée et reste comptée dans le quota du jour.
  - Fixtures : `post-stories.json`, `get-stories.json`, `error-409-story_daily_limit.json`

## Côté app (requêtes signées)

- `POST /places/{placeId}/messages { subject, message, source, sourceId }` (compte requis) → `201`. La réponse a la même forme qu'une conversation de `GET /me/threads`.
- `GET /me/threads` → `{ items: UserThread[] }`.
  - Un `UserThread` contient `id, subject, source, sourceId, merchantName, placeId, unread, messages[{ id, from, text, createdAt }], updatedAt`.
  - `unread` signifie que le commerçant a répondu et que le client n'a pas encore écrit depuis.
- `POST /me/threads/{id}/messages { text }` → le fil complet.
  - Le commerçant qui répond déclenche un push : thème `messages`, lien `myclermont://pro-threads/<id>`.
- `POST /places/{placeId}/events { type: view|directions|call|website }` (sans compte) → `204`. Un seul comptage par appareil, lieu, type et jour.
- `GET /places/{id}/reviews` : chaque avis porte `reply: { text, createdAt } | null`.
- `GET /stories` (signé, compte facultatif) → `{ items: [{ author: { placeId, name, avatar, isPartner, spot: MapSpot }, stories: [{ id, media, caption, captionStyle, cta, startsAt, expiresAt }] }], nextCursor: null }`.
  - Seules les stories en ligne sont renvoyées, de la plus ancienne à la plus récente dans chaque groupe.
  - Groupes : partenaires d'abord, puis la story la plus récente d'abord. `avatar` = photo de couverture de la fiche (https) ou `null`.
  - Fixture : `fixtures/stories.json`.
- `POST /stories/{id}/view` et `POST /stories/{id}/cta`, corps vide → `204`. Comptés une fois par appareil, même si l'app renvoie.

## Points à connaître

- **Identifiant de la fiche** :
  - il est créé à l'inscription au format de l'app (`le comptoir des puys@45.7765,3.0821`) et ne change plus ;
  - un avis envoyé avec cet identifiant (`PUT /places/{id}/reviews/me`) est rattaché à la fiche, donc visible dans l'espace pro.
- **`PUT /pro/place`** :
  - la mise à jour est partielle : un champ absent garde sa valeur ;
  - une adresse modifiée sans coordonnées est géocodée par la Base Adresse Nationale.
- **Fiche publiée** :
  - un changement de nom, d'adresse ou de catégorie passe le statut à `en_revue`, et l'ancienne version reste sur la carte ;
  - le reste est publié tout de suite.
- **Événement publié modifié** :
  - la modification attend la validation : le `GET` montre la nouvelle version, l'app garde l'ancienne ;
  - en cas de refus, l'ancienne version reste en ligne et `refusalReason` est renseigné.
- **Statut `inactif` d'un code** : le bon plan n'est plus `actif` (pause, brouillon, terminé).
- **`perPerson`** : la valeur est enregistrée, mais l'app ne délivre encore qu'un code par compte et par bon plan, et redonne le même code.
- **Contrat `Extra`** : il est publié dans l'app comme un saisonnier (`contract: "CDD"`), et l'espace pro garde « Extra ».
- **Logement `price`** : c'est le montant charges comprises que saisit le commerçant ; le serveur stocke le loyer hors charges plus les charges. Les `features` qui correspondent aux libellés de l'app (« Balcon », « Proche tram »…) deviennent ses codes.
- **SIRET** :
  - il est vérifié à l'inscription auprès de l'annuaire Recherche d'entreprises : `verifie` s'il est trouvé et actif, sinon `en_cours` et l'équipe vérifie ;
  - sans SIRET, le statut est `a_verifier` ;
  - un SIRET vérifié ne peut plus être modifié (`403 siret_locked`).
- **Passage Partenaire** : `subscribe` répond `403 not_available` tant que `PRO_SUBSCRIBE_ENABLED` n'est pas activé (pas de paiement). L'équipe peut passer `plan` à `partenaire` depuis le back-office : l'étoile sur la carte suit.
- **Pas encore disponible** :
  - double authentification : `POST` et `DELETE /pro/auth/2fa` répondent `501 not_configured` ;
  - factures : la liste est vide tant que le paiement n'est pas branché.
- **Stories** :
  - média : envoyer d'abord le fichier avec `POST /pro/uploads { purpose: "story" }`. Images JPEG, PNG, WebP ou HEIC (10 Mo) ; vidéos MP4 ou MOV (30 Mo). `media.url` doit être l'URL rendue par `confirm`, sinon `400` ;
  - durée : 6 s pour une image ; pour une vidéo, `media.durationSec` envoyé par le dashboard, 15 s au plus. Le serveur ne recadre ni ne transcode : envoyer du 9:16 ;
  - `startsAt` absent ou passé = tout de suite ; 7 jours d'avance au plus ; `expiresAt` = `startsAt` + 24 h ;
  - quota par jour calendaire de Paris (stories diffusées, programmées ou retirées ce jour-là) : 2 en Gratuit, 10 en Partenaire. Au-delà : `409`, `error.code = "story_daily_limit"`, `details: { limit, plan }` ;
  - `cta.targetId` : uid du bon plan (`kind: "deal"`, un de ses bons plans) ou slug de l'événement (`kind: "event"`). Pour `kind: "place"`, le serveur met l'id de la fiche dans l'app. `url` en https seulement ;
  - modération a posteriori : chaque nouvelle story apparaît dans « À modérer ». Retirée par l'équipe, elle passe en `retiree` et le commerçant reçoit une notification avec le motif ;
  - `stats.views` et `stats.ctaTaps` : une fois par appareil.
- **Pseudos** (baaff3eb §5) : `author` de `GET /pro/reviews`, `holder` de `GET /pro/deal-codes` et le corps de la notification « nouvel avis » (« camille.r : « … » ») portent le pseudo du client, jamais son nom ni son e-mail. La recherche `q` des avis et des codes porte aussi sur le pseudo. Messages, candidatures, devis et visites gardent ce que le client a saisi.
- **E-mails** (mot de passe oublié, invitations, réponses aux clients, alertes) : ils partent dès que le SMTP est configuré. Sans SMTP, l'appel réussit quand même.
- **Modération** : dans le back-office (`/admin` → « À modérer »), avec un motif de refus envoyé au commerçant :
  - fiches à valider ;
  - événements ;
  - avis signalés ;
  - SIRET à vérifier.


## Photos des visiteurs (ca817cb4)

Lecture pour toute l'équipe ; décisions réservées au propriétaire et au gérant (`403 forbidden` pour un équipier). Statuts : `en_attente`, `validee`, `refusee`. Motifs de refus : `floue`, `hors_sujet`, `personnes`, `inapproprie`, `doublon`, `autre`.

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | `/pro/visitor-photos?status=` | | `{ items: VisitorPhoto[], nextCursor, counts: { en_attente, validee, refusee } }` (en attente : plus anciennes d'abord) |
| GET | `/pro/visitor-photos/{id}` | | `VisitorPhoto` |
| POST | `/pro/visitor-photos/validate` | `{ ids (1 à 50), addToGallery, setAsCover? }` | `{ validated, addedToGallery, overLimit, cover, items, place: { photos, coverId } }` ; `409 already_decided` (`details.ids`) |
| POST | `/pro/visitor-photos/{id}/refuse` | `{ reason, note? (200) }` | `VisitorPhoto` ; l'auteur est notifié avec le motif |
| PATCH | `/pro/visitor-photos/{id}` | `{ inGallery }` | `{ photo, place }` ; `409 not_validated`, `403 plan_limit { max, current }` |
| POST | `/pro/visitor-photos/{id}/reopen` | | `VisitorPhoto` (repasse en attente, hors galerie, sans notification) |

Une photo ajoutée rejoint la galerie sous la forme `{ id: "ph_"+id, src, caption, credit: "Photo de @pseudo", visitorPhotoId }` (6 photos en gratuit, 30 en partenaire ; au-delà : `overLimit`, pas une erreur). Couverture : `PUT /pro/place { coverId: "ph_{id}" }`.
Les photos des pins sans commerçant vérifié sont validées par l'équipe dans le back-office (file « Photos des visiteurs » de /admin/moderation).
