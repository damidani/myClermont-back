#!/bin/sh
# Démarrage du conteneur myClermont : configuration, attente de MySQL, migrations, puis Nginx + PHP-FPM + cron.
set -eu
cd /var/www/myclermont
console() { su -s /bin/sh www-data -c "php bin/console $*"; }

# 1. Configuration persistante : .env vit dans le volume storage/ (survit aux mises à jour de l'image)
if [ ! -f storage/.env ]; then
    cp .env.example storage/.env
    echo "[myclermont] Premier démarrage : storage/.env créé"
fi
ln -sfn storage/.env .env
chown www-data:www-data storage/.env && chmod 640 storage/.env

# Clés manquantes générées une seule fois (APP_KEY, JWT_SECRET, clés application iOS / Android)
console key:generate | grep -v "Pensez à relancer" || true

# Les variables du conteneur (docker-compose.yml) sont recopiées dans .env : PHP-FPM, la CLI et cron voient la même chose
php -r '
$file = "storage/.env";
$env = file_get_contents($file);
preg_match_all("/^([A-Z][A-Z0-9_]*)=/m", file_get_contents(".env.example"), $m);
foreach (array_unique(array_merge($m[1], ["SMTP_HOST"])) as $key) {
    $value = getenv($key);
    if ($value === false || in_array($key, ["APP_KEY", "JWT_SECRET", "APP_SIGNING_KEYS"], true) && $value === "") continue;
    $line = $key . "=" . (preg_match("/[\s#\"]/", $value) ? "\"" . addcslashes($value, "\"\\\\") . "\"" : $value);
    $env = preg_match("/^" . $key . "=.*$/m", $env) ? preg_replace_callback("/^" . $key . "=.*$/m", fn () => $line, $env) : rtrim($env) . "\n" . $line . "\n";
}
file_put_contents($file, $env);
'

# 2. Mode développement : le code monté depuis l'hôte est relu à chaque requête
if [ "${APP_ENV:-production}" != "production" ]; then
    printf 'opcache.validate_timestamps=1\nopcache.revalidate_freq=0\nopcache.preload=\n' > /usr/local/etc/php/conf.d/zz-dev.ini
else
    rm -f /usr/local/etc/php/conf.d/zz-dev.ini
fi

# 3. Envoi d'emails (contacts logement, candidatures, devis) via un serveur SMTP
if [ -n "${SMTP_HOST:-}" ]; then
    SMTP_AUTH=off; [ -n "${SMTP_USER:-}" ] && SMTP_AUTH=on
    cat > /etc/msmtprc <<CONF
defaults
auth ${SMTP_AUTH}
tls on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile /proc/self/fd/2
account default
host ${SMTP_HOST}
port ${SMTP_PORT:-587}
from ${MAIL_FROM:-no-reply@myclermont.fr}
${SMTP_USER:+user ${SMTP_USER}}
${SMTP_PASSWORD:+password ${SMTP_PASSWORD}}
CONF
    chmod 640 /etc/msmtprc && chown root:www-data /etc/msmtprc
    echo 'sendmail_path = "/usr/bin/msmtp -t"' > /usr/local/etc/php/conf.d/zz-mail.ini
else
    echo "[myclermont] SMTP_HOST vide : les emails ne seront pas envoyés"
fi

# 4. Attente de la base de données (60 s max)
i=0
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do
    i=$((i + 1)); [ "$i" -ge 30 ] && { echo "[myclermont] MySQL injoignable (${DB_HOST:-?})"; exit 1; }
    echo "[myclermont] Attente de MySQL…"; sleep 2
done

# 5. Migrations, puis compte administrateur initial (seulement si aucun n'existe)
console config:clear >/dev/null
console migrate
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    if php -r 'require "vendor/autoload.php"; App\Core\Config::load(getcwd()); exit((int) App\Core\Database::fetch("SELECT COUNT(*) n FROM admin_users")["n"] > 0 ? 1 : 0);'; then
        su -s /bin/sh www-data -c "ADMIN_PASSWORD='${ADMIN_PASSWORD}' php bin/console admin:create '${ADMIN_EMAIL}' '${ADMIN_NAME:-Admin}' --role=admin"
    fi
fi
if [ "${DEMO_DATA:-false}" = "true" ] && [ ! -f storage/.demo-seeded ]; then
    for c in events housing jobs community services classifieds explore places; do console "$c:seed-demo"; done
    touch storage/.demo-seeded
fi
[ "${APP_ENV:-production}" = "production" ] && console config:cache

# 6. Tâches planifiées (deploy/crontab), exécutées en www-data
awk '{ printf "%s %s %s %s %s www-data", $1, $2, $3, $4, $5; for (i = 6; i <= NF; i++) printf " %s", $i; print "" }' /etc/cron.d/myclermont-raw > /etc/cron.d/myclermont
chmod 644 /etc/cron.d/myclermont

mkdir -p storage/logs storage/cache storage/private public/media
chown -R www-data:www-data storage public/media
echo "[myclermont] Prêt (port 80 du conteneur ; back-office : /admin)"
exec "$@"
