# Back-office web (`/admin`)

Interface web pour que l'équipe myClermont consulte, ajoute, modifie et supprime **tous** les contenus de l'app : événements, lieux, logements, offres d'emploi, professionnels, petites annonces, Explorer (stories, à la une, bons plans), catégories, quartiers, utilisateurs, appareils, signalements, sources d'import…

- Aucune dépendance : PHP rendu côté serveur, une feuille CSS et un petit script (`public/admin-assets/`). Les pages fonctionnent aussi sans JavaScript.
- Responsive : le menu se replie sur téléphone. Thème clair ou sombre selon le système.
- Séparé de l'API mobile : l'API exige des requêtes signées par un appareil, le back-office une session de navigateur.

## Démarrer

```bash
php bin/console migrate                                    # crée admin_users, admin_sessions, admin_audit_log
php bin/console admin:create vous@myclermont.fr "Votre nom" --role=admin
# le mot de passe est demandé (12 caractères minimum)
```

Puis ouvrir `https://api.myclermont.fr/admin` (en local : `composer serve` puis http://127.0.0.1:8080/admin).

`admin:create` sur un email existant **réinitialise** son mot de passe et le réactive. C'est la procédure en cas d'oubli ou de blocage.

## Ce qu'on peut faire

| Écran | Contenu |
|---|---|
| **Tableau de bord** | Utilisateurs, inscrits sur 7 jours, premium actifs. Bloc « À traiter » : signalements, contenus importés en brouillon à valider, emails de contact / candidatures / devis en échec. Derniers imports, dernières actions. Bouton « Vider les caches de l'app » |
| **Listes** | Recherche (texte ou ID), filtres (statut, catégorie, source…), tri par colonne, pagination, export CSV (UTF-8, ouvrable dans Excel) |
| **Fiches** | Formulaire généré à partir de la base. Listes déroulantes pour les statuts et les catégories, cases à cocher, prix en euros, dates en heure de Paris, éditeur JSON (horaires, « En chiffres »…). Bouton « Envoyer une image » sur les champs image. Historique des modifications de la fiche |
| **Mon compte** | Mot de passe, double authentification, sessions ouvertes |

Les champs sont **déduits du schéma MySQL** :

| Colonne | Champ |
|---|---|
| `ENUM` | liste déroulante |
| `TINYINT(1)` | case à cocher ; liste Oui / Non / — si la colonne accepte NULL |
| clé étrangère | liste déroulante des libellés ; saisie de l'ID au-delà de 500 lignes (utilisateurs) |
| `*_cents` | montant en euros (« 12,50 ») |
| `DATETIME` | date et heure de Paris, converties en UTC en base |
| `JSON` | zone de texte, JSON vérifié et compacté à l'enregistrement |
| `*_url` | URL `http(s)://` ; bouton d'envoi d'image pour les images |

Une colonne ajoutée par une migration apparaît donc toute seule dans le formulaire. `config/admin.php` ne décrit que ce que le schéma ne dit pas : libellés, colonnes de liste, champs de recherche, droits.

Gérés automatiquement :
- `id`, `uid` (UUID généré), `created_at` et `updated_at` ;
- `published_at`, rempli au passage en `published` ;
- mot de passe des utilisateurs et des admins : champ « Nouveau mot de passe », haché en Argon2id.

**Effet immédiat dans l'app** : après chaque modification, les caches concernés sont vidés (repères de la carte, compteurs, écran Explorer…). Un appareil modifié sort du cache. Un utilisateur désactivé ou supprimé perd ses sessions (refresh tokens révoqués) ; son jeton d'accès expire sous 15 min.

## Rôles

| Rôle | Accès |
|---|---|
| `admin` | Tout, y compris les administrateurs, le journal d'audit et le vidage des caches |
| `editor` | Contenus : agenda, lieux, Explorer, logement, emploi, services, mobilité, catégories, quartiers, sources d'import, envoi d'images |
| `moderator` | Utilisateurs, appareils, badges, points, communauté, petites annonces, avis, signalements, messages des conversations signalées |

Les droits se règlent ressource par ressource dans `config/admin.php` (clé `roles`). Certaines ressources sont en consultation seule : historique des points, imports, notifications push, journal d'audit.

**Vie privée** : seuls les messages des conversations **signalées** sont visibles (`where` dans `config/admin.php`). Les conversations privées non signalées ne sont jamais affichées.

## Sécurité

| Mesure | Détail |
|---|---|
| Mots de passe | Argon2id, 12 caractères minimum (10 pour ceux définis par un autre admin dans un formulaire). Temps de réponse identique que l'email existe ou non |
| Anti-force brute | 5 échecs / 15 min par email, 20 par IP → blocage 15 min + entrée `security` dans les logs. Nginx limite aussi le débit |
| Double authentification | TOTP (Google Authenticator, 1Password, Authy…), secret chiffré avec `APP_KEY`, un code ne sert qu'une fois. `ADMIN_REQUIRE_2FA=true` l'impose à tous |
| Session | Cookie `HttpOnly`, `Secure`, `SameSite=Strict`, limité à `/admin`. Il ne contient qu'un jeton aléatoire, stocké haché en base. Expiration absolue (8 h) et après inactivité (1 h). Changer son mot de passe ferme les autres sessions |
| CSRF | Jeton par session sur chaque formulaire. Double envoi cookie + champ sur la page de connexion. Requête `POST` refusée si l'en-tête `Origin` est celui d'un autre site |
| Injection HTML | Tout est échappé. CSP stricte : aucun script ni style en ligne, aucune ressource externe, pas d'iframe (`frame-ancestors 'none'`) |
| Injection SQL | Noms de tables / colonnes pris uniquement dans le schéma lu par `INFORMATION_SCHEMA`. Valeurs toujours passées en paramètres |
| Images | Type lu dans les octets (JPEG, PNG, WebP), pas dans le nom. Métadonnées EXIF / GPS retirées. Nom aléatoire. 8 Mo max. Aucun PHP exécutable dans `public/media` |
| Export CSV | Cellules commençant par `= + - @` neutralisées (injection de formules dans Excel) |
| Garde-fous | Un admin ne peut ni se supprimer, ni changer son propre rôle ou statut |
| Journal d'audit | Chaque connexion, échec, création, modification (valeurs avant / après), suppression (contenu supprimé), export et envoi d'image, avec l'IP. Les secrets n'y sont jamais écrits. Consultable dans Administration › Journal d'audit et en bas de chaque fiche |
| Restriction réseau | `ADMIN_ALLOWED_IPS` dans `.env`, ou `allow` / `deny` dans le bloc `location /admin` de Nginx (recommandé : IP du bureau ou VPN) |

Réglages (`.env`) :

```ini
ADMIN_ENABLED=true          # false = /admin répond 404
ADMIN_ALLOWED_IPS=          # "1.2.3.4,5.6.7.8"
ADMIN_REQUIRE_2FA=true
ADMIN_SESSION_TTL=28800     # 8 h
ADMIN_IDLE_TIMEOUT=3600     # 1 h
```

## Ajouter un écran

Une nouvelle table apparaît dans le back-office dès qu'elle est déclarée dans un groupe de `config/admin.php` :

```php
'Agenda' => [
    'events' => ['label' => 'Événements', 'roles' => ['editor'],
                 'list' => ['id', 'title', 'starts_at', 'status'],   // colonnes du tableau
                 'search' => ['title', 'venue_name']],               // recherche texte
],
```

Autres options :
- `readonly` : consultation seule ;
- `can_create => false` : pas de bouton « Ajouter » ;
- `readonly_columns` : colonnes visibles mais non modifiables ;
- `password => 'password_hash'` : affiche le champ « Nouveau mot de passe » ;
- `where` : filtre SQL fixe des lignes visibles.

Les colonnes sensibles (`password_hash`, `token_hash`, `secret_encrypted`, `totp_secret`…) sont cachées partout (`hidden_columns`).

Si l'app garde la nouvelle table en cache, ajouter la clé de cache dans `src/Admin/CacheInvalidator.php`.

## Fichiers

```
src/Admin/
  AdminKernel.php       routage /admin, en-têtes de sécurité, IP autorisées, contrôle CSRF et Origin
  AdminController.php   pages : connexion, tableau de bord, compte, listes, fiches, export, envoi d'image
  AdminAuth.php         mots de passe, anti-force brute, sessions
  Totp.php              double authentification (RFC 6238)
  Schema.php            lecture du schéma MySQL (mis en cache 5 min ; vidé par migrate)
  Resource.php          ressource = table + config/admin.php ; type de chaque champ
  Crud.php              recherche, filtres, pagination, création, modification, suppression, validation
  Html.php              échappement, champs de formulaire, affichage des valeurs
  Audit.php             journal d'audit
  CacheInvalidator.php  caches de l'app à vider après une modification
  views/                gabarits HTML
public/admin-assets/    admin.css, admin.js
config/admin.php        ressources, rôles, réglages
database/migrations/011_admin.sql
```
