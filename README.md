# myClermont — API backend

Backend PHP / MySQL des applications mobiles **iOS** et **Android** de myClermont.

- **Rapide** : aucun framework, aucune dépendance, routeur O(1), OPcache + préchargement, APCu, connexions MySQL persistantes. Une requête signée typique coûte 0 ou 1 requête SQL.
- **Sûr** : chaque requête est **signée HMAC-SHA256** avec un secret propre à l'appareil, horodatée et protégée contre le rejeu. Un appel direct à l'API (curl, Postman, script) sans les identifiants d'un appareil enregistré est rejeté.

## Démarrage rapide

Avec Docker (tout compris : Nginx, PHP-FPM, cron, MariaDB) :

```bash
cp .env.docker.example .env.docker && docker compose up -d --build   # → http://localhost:8080/admin
```

Détails : [docs/DOCKER.md](docs/DOCKER.md). Sans Docker :

```bash
composer install                     # génère uniquement l'autoloader (aucun paquet tiers)
php bin/console key:generate         # crée .env + APP_KEY, JWT_SECRET, clés application iOS/Android
# éditer .env : identifiants MySQL
php bin/console migrate              # crée les tables
php bin/console admin:create vous@myclermont.fr "Votre nom"   # compte du back-office
composer serve                       # serveur de dev sur http://127.0.0.1:8080 (back-office : /admin)
curl http://127.0.0.1:8080/v1/health
```

Tests (sans MySQL) : `composer test`

Tester une route signée à la main :

```bash
# 1. Enregistrer un appareil avec la clé application iOS
eval "$(php bin/console sign POST /v1/devices/register '{"platform":"ios"}' \
        --key-id=ios-2026-1 --device-id=3f2504e0-4f89-41d3-9a0c-0305e82c3301)"
# => récupérer device_token et device_secret dans la réponse

# 2. Appeler une route protégée
eval "$(php bin/console sign GET /v1/devices/me \
        --device-id=3f2504e0-4f89-41d3-9a0c-0305e82c3301 --token=<device_token> --secret=<device_secret>)"
```

## Structure du projet

```
myClermont/
├── public/
│   ├── index.php                 Point d'entrée unique (API /v1 et back-office /admin)
│   ├── admin-assets/             CSS / JS du back-office
│   └── media/                    Photos / vidéos publiées (servies par Nginx, à sauvegarder)
├── routes/
│   └── api.php                   Déclaration des routes + middlewares
├── config/
│   ├── app.php                   Application, alias de middlewares
│   ├── modules.php               Modules actifs
│   ├── gamification.php          Règles de points et catalogue des badges
│   ├── events.php                Agenda : fuseau, URL de partage, pagination
│   ├── housing.php               Logement : transactions, équipements, ratio de revenus, contact
│   ├── jobs.php                  Emploi : contrats, rythmes, conservation des CV
│   ├── imports.php               Sources importées automatiquement
│   ├── explore.php               Accueil : cache, météo, recherche globale
│   ├── places.php                Carte : cache des repères, anneaux du flux, centre par défaut
│   ├── admin.php                 Back-office : écrans, droits par rôle, sécurité
│   ├── database.php              Connexion MySQL
│   └── security.php              Clés application, fenêtre de signature, JWT, rate-limit
├── src/
│   ├── Core/                     Noyau : App, Router, Request, Response, Database, Config, Events, Module…
│   ├── Admin/                    Back-office web /admin (voir docs/ADMIN.md)
│   ├── Modules/                  Fonctionnalités (voir docs/MODULES.md)
│   │   ├── Account/              Inscription, connexion, profil, pseudo, bio, quartier, parrainage
│   │   ├── Gamification/         Points et badges automatiques
│   │   ├── Favorites/            Favoris génériques (lieux, annonces…)
│   │   ├── Premium/              Statut premium, middleware "premium"
│   │   ├── Events/               Agenda : catégories, fiches, programme, calendrier, participation
│   │   ├── Housing/              Logement : annonces, filtres, carte, formulaire de contact
│   │   ├── Jobs/                 Emploi & stages : offres, candidatures avec CV / lettre PDF
│   │   ├── Community/            Communauté : publications, sondages, photos, vidéos, commentaires
│   │   ├── Services/             Annuaire de professionnels, devis, avis vérifiés
│   │   ├── Mobility/             Destinations, transports en commun et info trafic (Navitia)
│   │   ├── Classifieds/          Petites annonces : vendre, acheter, donner
│   │   ├── Messaging/            Messagerie entre utilisateurs, notifications push
│   │   ├── Explore/              Accueil : stories, flash, exclusivités, à découvrir, recherche globale, météo
│   │   └── Places/               Carte (repères compacts, ETag, flux SSE) et fiches lieux
│   ├── Content/                  Provenance des contenus, imports, documents (CV), médias (photos/vidéos)
│   ├── Http/
│   │   ├── Middleware/           AppSignature, DeviceSignature, Auth, RateLimit
│   │   └── Controllers/          Health, Device
│   ├── Security/                 Signature (HMAC), NonceGuard (anti-rejeu), Crypto (libsodium), Jwt
│   ├── Services/                 TokenService (jetons d'accès / refresh + rotation)
│   ├── Repositories/             Accès aux tables (devices, users, refresh_tokens)
│   ├── Cache/                    CacheStore : APCu (mémoire) ou MySQL (repli)
│   ├── Support/                  Validator
│   └── Exceptions/               HttpException, ValidationException
├── database/migrations/          Scripts SQL versionnés
├── bin/console                   CLI : clés, migrations, cache, blocage d'appareil, curl signé
├── deploy/                       Nginx, PHP-FPM, php.ini (OPcache/APCu), MySQL, cron, preload
├── storage/                      logs/, cache/, private/ (CV — à sauvegarder, jamais public)
├── tests/                        Tests sans dépendance (php tests/run.php)
└── docs/                         Documentation
```

## Documentation

| Document | Contenu |
|---|---|
| [docs/API.md](docs/API.md) | Référence de l'API : en-têtes, endpoints, erreurs, exemples |
| [docs/openapi.yaml](docs/openapi.yaml) | Spécification OpenAPI 3.1 (Swagger UI, Postman, génération de clients) |
| [docs/MODULES.md](docs/MODULES.md) | Système de modules, événements, règles de gamification, brancher un type de favori |
| [docs/ADMIN.md](docs/ADMIN.md) | Back-office web : gestion de tous les contenus, rôles, sécurité, ajouter un écran |
| [docs/IMPORTS.md](docs/IMPORTS.md) | Contenus partenaires / importés, format des flux, bouton Contacter externe |
| [docs/SECURITE.md](docs/SECURITE.md) | Protocole de signature, modèle de menace, limites et recommandations |
| [docs/INTEGRATION_MOBILE.md](docs/INTEGRATION_MOBILE.md) | Code de signature prêt à l'emploi en Swift et Kotlin |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Cycle de vie d'une requête, choix de performance, ajouter un endpoint |
| [docs/DOCKER.md](docs/DOCKER.md) | Conteneurs Docker : démarrage, volumes, sauvegardes, production |
| [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) | Mise en production, réglages serveur, exploitation |

## Prérequis

- PHP **8.2+** avec `pdo_mysql`, `sodium`, `json` (+ `apcu` fortement recommandé, + `opcache`)
- MySQL **8.0+** ou MariaDB **10.6+**
- Nginx (ou Apache avec `mod_rewrite`), HTTPS obligatoire en production
