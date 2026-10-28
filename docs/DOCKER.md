# Docker

Deux conteneurs :

| Conteneur | Contenu |
|---|---|
| `web` | Nginx + PHP-FPM 8.3 (OPcache, APCu, sodium) + cron (imports, emails, maintenance), géré par supervisord |
| `db` | MariaDB 11.4 |

## Démarrer

```bash
cp .env.docker.example .env.docker     # puis CHANGER les mots de passe (DB, root, admin)
docker compose up -d --build
docker compose logs web                # clés iOS / Android générées au premier démarrage
```

- API : http://localhost:8080/v1/health
- Back-office : http://localhost:8080/admin. Le compte vient de `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
- Autre port : `HTTP_PORT=8088 docker compose up -d`.
- Données de démo : `DEMO_DATA=true` dans `.env.docker`, avant le premier démarrage.

## Au démarrage du conteneur `web`

1. Crée `storage/.env` au premier démarrage, et génère `APP_KEY`, `JWT_SECRET` et les clés application iOS / Android. Ces clés ne sont **jamais régénérées** ensuite. Les clés application s'affichent **une seule fois** dans `docker compose logs web` : transmets-les aux équipes mobiles.
2. Recopie les variables de `.env.docker` dans ce `.env`. PHP-FPM, la ligne de commande et cron voient donc la même configuration.
3. Attend MySQL, exécute les migrations, puis crée le compte admin s'il n'en existe aucun.
4. Met la configuration en cache en production. En `APP_ENV=local`, le code est relu à chaque requête.
5. Installe les tâches cron (`deploy/crontab`) et configure l'envoi d'emails si `SMTP_HOST` est renseigné.

## Données persistantes (volumes)

| Volume | Contenu | Sauvegarder |
|---|---|---|
| `storage` | `.env` (**APP_KEY : sans elle, les secrets des appareils sont perdus**), logs, CV / documents privés | ✅ |
| `media` | photos et vidéos publiées | ✅ |
| `db-data` | base MariaDB | ✅ (`mariadb-dump`) |

```bash
docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" myclermont' > backup.sql
docker compose exec web cat storage/.env > env.backup            # à garder dans un coffre
```

## Commandes utiles

```bash
docker compose exec -u www-data web php bin/console admin:create moi@myclermont.fr "Moi" --role=admin
docker compose exec -u www-data web php bin/console import:run <slug>
docker compose exec -u www-data web php bin/console device:block <device-id>
docker compose logs -f web                                         # accès Nginx + erreurs PHP
docker compose exec web tail -f storage/logs/app-$(date +%F).log   # logs applicatifs (dont "security")
```

Mise à jour : `docker compose up -d --build`. Les migrations s'exécutent toutes seules et les volumes sont conservés.

## Production

- **HTTPS** : le conteneur sert du HTTP sur le port 80. Mets-le derrière un reverse proxy qui gère le certificat (Nginx Proxy Manager, Traefik, Caddy…). L'IP réelle des clients est lue dans `X-Forwarded-For` quand la requête vient d'un réseau privé Docker.
- `APP_ENV=production`, `APP_DEBUG=false`, `ADMIN_REQUIRE_2FA=true`, des mots de passe forts.
- `MEDIA_BASE_URL=https://api.myclermont.fr/media`.
- **Emails** : `SMTP_HOST`, `SMTP_USER`, `SMTP_PASSWORD` (Brevo, Mailjet, OVH…).
- `PHP_FPM_MAX_CHILDREN` : compter environ 30 Mo par worker. Le conteneur `web` utilise environ 60 Mo au repos.
- Le port MySQL n'est pas exposé sur l'hôte.

## Imports automatiques

Les imports (offres France Travail, agences en Poliris, sites partenaires) tournent dans le cron du conteneur. Réglages dans `.env.docker` :

```ini
FRANCE_TRAVAIL_CLIENT_ID=…
FRANCE_TRAVAIL_CLIENT_SECRET=…
```

Commandes utiles :

```bash
docker compose exec web php bin/console import:status
docker compose exec web php bin/console import:preview france-travail 3
docker compose exec web php bin/console import:run france-travail
```

Un fichier Poliris déposé par une agence se place dans le volume `storage`, sous `storage/imports/<source>/`. Détails dans [IMPORTS.md](IMPORTS.md).
