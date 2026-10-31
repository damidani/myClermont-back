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

## Côté app (requêtes signées)

- `POST /places/{placeId}/messages { subject, message, source, sourceId }` (compte requis) → `201`. La réponse a la même forme qu'une conversation de `GET /me/threads`.
- `GET /me/threads` → `{ items: UserThread[] }`.
  - Un `UserThread` contient `id, subject, source, sourceId, merchantName, placeId, unread, messages[{ id, from, text, createdAt }], updatedAt`.
  - `unread` signifie que le commerçant a répondu et que le client n'a pas encore écrit depuis.
- `POST /me/threads/{id}/messages { text }` → le fil complet.
  - Le commerçant qui répond déclenche un push : thème `messages`, lien `myclermont://pro-threads/<id>`.
- `POST /places/{placeId}/events { type: view|directions|call|website }` (sans compte) → `204`. Un seul comptage par appareil, lieu, type et jour.
- `GET /places/{id}/reviews` : chaque avis porte `reply: { text, createdAt } | null`.

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
- **E-mails** (mot de passe oublié, invitations, réponses aux clients, alertes) : ils partent dès que le SMTP est configuré. Sans SMTP, l'appel réussit quand même.
- **Modération** : dans le back-office (`/admin` → « À modérer »), avec un motif de refus envoyé au commerçant :
  - fiches à valider ;
  - événements ;
  - avis signalés ;
  - SIRET à vérifier.
