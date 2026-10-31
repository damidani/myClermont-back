# Déploiement

Cible : un serveur Debian/Ubuntu avec Nginx, PHP 8.3-FPM, MySQL 8. Les fichiers de configuration prêts à l'emploi sont dans `deploy/`.

## 1. Paquets

```bash
apt install nginx mysql-server php8.3-fpm php8.3-mysql php8.3-apcu php8.3-opcache php8.3-mbstring composer
```

## 2. Base de données

```sql
CREATE DATABASE myclermont CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'myclermont'@'localhost' IDENTIFIED BY '<mot de passe fort>';
-- Droits minimaux : pas de DROP, pas de GRANT
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON myclermont.* TO 'myclermont'@'localhost';
```

Copier `deploy/mysql.cnf` dans `/etc/mysql/mysql.conf.d/99-myclermont.cnf`, ajuster `innodb_buffer_pool_size`, redémarrer MySQL.

## 3. Application

```bash
git clone … /var/www/myclermont && cd /var/www/myclermont
composer install --no-dev --classmap-authoritative
php bin/console key:generate          # note les clés application affichées → équipes iOS/Android
nano .env                             # APP_ENV=production, APP_DEBUG=false, accès MySQL
php bin/console migrate
php bin/console admin:create vous@myclermont.app "Votre nom" --role=admin   # back-office /admin
php bin/console config:cache

chown -R www-data:www-data storage
chmod 640 .env && chown root:www-data .env
```

> Back-office : garder `ADMIN_REQUIRE_2FA=true` et, si possible, restreindre `/admin` aux IP du bureau ou du VPN (voir [ADMIN.md](ADMIN.md)).

> Sauvegarder `APP_KEY` dans un coffre (gestionnaire de mots de passe d'équipe). Sans elle, les secrets des appareils sont irrécupérables.

## 4. PHP-FPM

```bash
cp deploy/php-fpm.conf /etc/php/8.3/fpm/pool.d/myclermont.conf
cp deploy/php.ini      /etc/php/8.3/fpm/conf.d/99-myclermont.ini
systemctl restart php8.3-fpm
```

Ajuster `pm.max_children` : `(RAM disponible pour PHP) / 30 Mo` environ.

## 5. Nginx + HTTPS

```bash
cp deploy/nginx.conf /etc/nginx/sites-available/myclermont
ln -s /etc/nginx/sites-available/myclermont /etc/nginx/sites-enabled/
certbot --nginx -d api.myclermont.app
nginx -t && systemctl reload nginx
```

Si un load balancer / Cloudflare est devant le serveur, renseigner ses IP dans `TRUSTED_PROXIES`, sinon le rate-limit par IP s'appliquera à l'IP du proxy.

## 6. Cron

```bash
crontab -u www-data deploy/crontab
```

## 7. Vérifications

```bash
curl -s https://api.myclermont.app/v1/health          # {"success":true,...}
curl -s https://api.myclermont.app/v1/users/me        # 401 invalid_device_id → la protection fonctionne
php -r 'var_dump(function_exists("apcu_enabled"));'  # APCu installé
```

## Mise à jour (déploiement continu)

```bash
cd /var/www/myclermont
git pull
composer install --no-dev --classmap-authoritative
php bin/console migrate
php bin/console config:cache
systemctl reload php8.3-fpm        # obligatoire : OPcache ne relit pas les fichiers (validate_timestamps=0)
```

## Sauvegardes

- `mysqldump --single-transaction --routines myclermont | gzip > backup-$(date +%F).sql.gz` quotidien, copié hors serveur.
- Sauvegarder le `.env` séparément (contient `APP_KEY`), chiffré.

## Surveillance

| Quoi | Où |
|---|---|
| Erreurs applicatives | `storage/logs/app-YYYY-MM-DD.log`, `"level":"error"` |
| Événements de sécurité | même fichier, `"level":"security"` — alerter si pic |
| Requêtes PHP lentes (> 2 s) | `/var/log/php-fpm/myclermont-slow.log` |
| Requêtes SQL lentes (> 200 ms) | `/var/log/mysql/slow.log` |
| Disponibilité | sonde HTTP sur `/v1/health` |

## Mesurer les performances

```bash
# Route publique (plafond du serveur)
ab -n 20000 -c 100 https://api.myclermont.app/v1/health
# ou
wrk -t4 -c100 -d30s https://api.myclermont.app/v1/health
```

Pour les routes signées, générer les requêtes avec `php bin/console sign …` (chaque requête doit avoir un nonce unique : utiliser un script, pas `ab`).
