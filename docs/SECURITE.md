# Sécurité

Ce document décrit comment l'API empêche les appels qui ne viennent pas d'une application myClermont légitime, et ce qu'elle ne peut **pas** garantir.

## 1. Vue d'ensemble : trois niveaux de clés

| Niveau | Qui la possède | Sert à | Durée de vie |
|---|---|---|---|
| **Clé application** (`X-Key-Id`) | Embarquée dans l'app iOS / Android | Signer **uniquement** `POST /v1/devices/register` | Changée à chaque version majeure (rotation) |
| **Identifiants appareil** (`device_token` + `device_secret`) | Uniques par installation, stockés dans le Keychain / Keystore | Signer **toutes** les autres requêtes | Jusqu'à réinstallation ou blocage |
| **Jetons utilisateur** (`access_token` + `refresh_token`) | Obtenus à la connexion | Identifier l'utilisateur | 15 min / 30 jours (rotation) |

Pourquoi ne pas utiliser seulement une clé commune embarquée dans l'app ? Parce qu'une clé présente dans un binaire **finit toujours par être extraite** (décompilation de l'APK, jailbreak). Avec une clé commune, une seule fuite compromettrait toute l'API, sans moyen de bloquer l'attaquant. Ici :

- la clé application ne permet **que** de créer un appareil (limité à 10 / heure / IP) ;
- chaque appareil a **son propre secret**, transmis une seule fois puis jamais renvoyé ;
- un appareil abusif est **bloquable individuellement** (`php bin/console device:block <id>`).

## 2. Protocole de signature

### En-têtes

| En-tête | Format | Description |
|---|---|---|
| `X-Device-Id` | UUID v4 | Généré par l'app au premier lancement, conservé dans le Keychain / Keystore |
| `X-Device-Token` | chaîne ≤ 128 car. | Reçu à l'enregistrement. Stocké hashé (SHA-256) côté serveur |
| `X-Timestamp` | entier | Secondes Unix (UTC) au moment de l'envoi |
| `X-Nonce` | `[A-Za-z0-9_-]{16,64}` | Valeur aléatoire, unique par requête |
| `X-Signature` | 64 car. hexadécimaux | HMAC-SHA256 de la chaîne à signer |
| `X-Key-Id` | chaîne | *Enregistrement uniquement* : identifiant de la clé application (remplace `X-Device-Token`) |

### Chaîne à signer

Sept lignes séparées par `\n` (LF), **sans** `\n` final :

```
POST                                       ← méthode HTTP en majuscules
/v1/auth/login                             ← chemin, encodé tel qu'envoyé, sans "/" final ni query string
a=1&a=hello%20world&b=2                    ← query canonique (ligne vide s'il n'y en a pas)
1700000000                                 ← X-Timestamp
abcdef0123456789                           ← X-Nonce
3f2504e0-4f89-41d3-9a0c-0305e82c3301       ← X-Device-Id, tel qu'envoyé dans l'en-tête
be959f26a12a3b8d…                          ← SHA-256 hexadécimal (minuscules) du corps brut
```

**Query canonique** : pour chaque paramètre, nom et valeur encodés en RFC 3986 (seuls `A-Z a-z 0-9 - . _ ~` restent en clair, l'espace devient `%20`), triés par nom puis par valeur (ordre octet par octet), joints par `&`.

**Corps** : le hash porte sur les **octets exacts** envoyés. Ne pas re-sérialiser le JSON après l'avoir signé. Corps vide → `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.

**Clé HMAC** :
- requêtes appareil : les octets UTF-8 de `device_secret` (la chaîne telle que reçue) ;
- enregistrement : la clé application **décodée du base64** (32 octets).

### Vecteur de test

À reproduire à l'identique dans les tests unitaires des apps :

```
secret     = "test-secret"
méthode    = POST
chemin     = /v1/auth/login
query      = b=2&a=hello%20world&a=1
timestamp  = 1700000000
nonce      = abcdef0123456789
device id  = 3f2504e0-4f89-41d3-9a0c-0305e82c3301
corps      = {"email":"a@b.fr","password":"secret123"}

hash corps = be959f26a12a3b8d2f599ffc26cc5cde44cd3a889f947ec0e5cab91285d7ab5e
signature  = 92f9f6b9bfa2c96ac205992da06013833125905d831c28ad58add58200e14c04
```

### Vérifications côté serveur (dans l'ordre)

1. Format des en-têtes — aucun accès disque/base
2. `|maintenant − X-Timestamp| ≤ 300 s` — une requête capturée devient inutilisable après 5 min
3. Appareil existant **et** `SHA-256(X-Device-Token)` identique (comparaison à temps constant) — cache mémoire, sinon 1 `SELECT`
4. Appareil non bloqué
5. Signature HMAC (comparaison à temps constant `hash_equals`)
6. Nonce jamais vu pour cet appareil (conservé 10 min) — rejette le rejeu dans la fenêtre de 5 min

Le nonce n'est enregistré **qu'après** la validation de la signature : un attaquant ne peut pas remplir le stockage avec des requêtes forgées.

### Ce que garantit la signature

| Attaque | Protection |
|---|---|
| Appel direct à l'API (curl, Postman, script) | Impossible sans `device_secret` |
| Modification de la requête en transit (corps, URL, paramètres) | La signature ne correspond plus |
| Rejeu d'une requête capturée | Nonce déjà vu / timestamp expiré |
| Vol d'un `access_token` | Inutilisable : lié à l'appareil (claim `did`) **et** il faut aussi le secret de l'appareil |
| Fuite de la base de données | Secrets appareils chiffrés (libsodium, `APP_KEY` hors base), jetons et mots de passe hashés |
| Brute-force de connexion | Rate-limit par IP + Argon2id + temps de réponse constant |
| Énumération d'e-mails / d'appareils | Messages d'erreur identiques |

## 3. Chiffrement

- **Transport** : HTTPS (TLS 1.2+) obligatoire. C'est TLS qui chiffre le contenu ; la signature HMAC garantit l'**authenticité** et l'**intégrité**, pas la confidentialité.
- **Au repos** : `devices.secret_encrypted` chiffré avec XSalsa20-Poly1305 (libsodium) et `APP_KEY`. Le serveur doit pouvoir relire le secret pour vérifier un HMAC : il ne peut pas être simplement hashé, d'où le chiffrement.
- **Mots de passe** : Argon2id (`password_hash`), migration automatique si l'algorithme évolue.
- **Jetons** : `device_token` et `refresh_token` ne sont stockés que sous forme de SHA-256.

## 4. Jetons utilisateur

- `access_token` : JWT HS256 de 15 min, claims `sub` (utilisateur), `did` (appareil), `typ=access`. L'algorithme est figé côté serveur (pas d'attaque `alg: none`).
- `refresh_token` : opaque, 30 jours, **usage unique**. Chaque `/auth/refresh` le révoque et en émet un nouveau.
- **Détection de vol** : si un refresh token déjà utilisé est présenté à nouveau, toute la session de l'utilisateur sur cet appareil est révoquée.
- La vérification de l'access token ne touche pas la base (rapidité). Contrepartie : un compte désactivé conserve l'accès aux routes qui ne relisent pas l'utilisateur pendant au plus 15 min. `/users/me` relit toujours l'utilisateur.

## 5. Limites — à lire

**Aucun mécanisme côté serveur ne peut prouver à 100 % qu'une requête vient de votre app.** Un attaquant déterminé qui extrait la clé application d'un binaire peut enregistrer de faux appareils, puis signer correctement ses requêtes. Le système actuel rend cela **coûteux, traçable et bloquable**, mais pas impossible.

Pour aller plus loin (recommandé avant une mise en production à fort enjeu) :

1. **Attestation matérielle** — la vraie solution au problème « est-ce bien mon app, non modifiée, sur un vrai appareil ? » :
   - iOS : **App Attest** (`DCAppAttestService`)
   - Android : **Play Integrity API**

   À vérifier dans `DeviceController::register` avant d'émettre les identifiants. Le point d'extension est prévu : ajouter un champ `attestation` au corps de l'enregistrement.
2. **Protéger la clé application dans le binaire** : obfuscation (R8/ProGuard + chaîne reconstituée à l'exécution, ou code natif NDK sur Android ; éviter les chaînes en clair dans Swift), et rotation à chaque version (`php bin/console app-key:add`), en retirant les clés des versions qui ne sont plus supportées.
3. **Certificate pinning** dans les apps (empêche l'inspection du trafic avec un proxy type Charles/mitmproxy, donc la rétro-ingénierie du protocole).
4. **Surveillance** : les événements `"level":"security"` dans `storage/logs/app-*.log` (signatures invalides, rejeux, clés inconnues) doivent alimenter une alerte.

## 6. Back-office web

`/admin` n'utilise pas la signature d'appareil. Il est protégé par :
- une session de navigateur (cookie `HttpOnly` / `Secure` / `SameSite=Strict`) ;
- Argon2id et un anti-force brute ;
- une double authentification TOTP (imposable) ;
- un jeton CSRF et un contrôle de l'en-tête `Origin` ;
- une CSP stricte, des rôles et un journal d'audit complet.

Détails : [ADMIN.md](ADMIN.md#sécurité). En production : `ADMIN_REQUIRE_2FA=true` et accès limité par IP / VPN si possible.

## 7. Exploitation

| Action | Commande |
|---|---|
| Bloquer un appareil (et fermer ses sessions) | `php bin/console device:block <device-id>` (ou back-office › Appareils) |
| Réinitialiser l'accès d'un admin | `php bin/console admin:create <email> "<Nom>" --role=…` |
| Débloquer | `php bin/console device:unblock <device-id>` |
| Nouvelle clé application (rotation) | `php bin/console app-key:add ios ios-2027-1` |
| Révoquer une clé application | Retirer l'entrée de `APP_SIGNING_KEYS` dans `.env`, puis `config:cache` + reload PHP-FPM |
| Changer `APP_KEY` | ⚠️ Rend tous les secrets appareils illisibles : tous les appareils devront se réenregistrer |
| Changer `JWT_SECRET` | Déconnecte tous les utilisateurs au plus tard à l'expiration de leur access token (ils utiliseront leur refresh token) |

Avec APCu, un appareil bloqué depuis la CLI reste en cache jusqu'à 30 s (`device_cache_ttl`). Pour un effet immédiat : `systemctl reload php8.3-fpm`.
