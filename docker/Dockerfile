# Image « tout-en-un » de myClermont : Nginx + PHP-FPM 8.3 (OPcache, APCu, sodium) + cron.
# La base MySQL/MariaDB est un service à part (voir docker-compose.yml).
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx supervisor cron msmtp-mta ca-certificates tzdata libzip-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache zip \
 && pecl install apcu \
 && docker-php-ext-enable apcu \
 && rm -rf /var/lib/apt/lists/* /tmp/pear /etc/nginx/sites-enabled/default \
 && rm -f /usr/local/etc/php-fpm.d/*.conf /usr/local/etc/php-fpm.d/*.default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/myclermont

# Dépendances d'abord (couche mise en cache tant que composer.json ne change pas)
COPY composer.json ./
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress \
 && rm /usr/bin/composer \
 && mkdir -p storage/logs storage/cache storage/private public/media \
 && chown -R www-data:www-data storage public/media

COPY deploy/php.ini              /usr/local/etc/php/conf.d/99-myclermont.ini
COPY docker/php-fpm.conf         /usr/local/etc/php-fpm.d/myclermont.conf
COPY docker/nginx.conf           /etc/nginx/conf.d/myclermont.conf
COPY docker/supervisord.conf     /etc/supervisor/conf.d/myclermont.conf
COPY docker/entrypoint.sh        /usr/local/bin/myclermont-entrypoint
RUN sed 's# php /var/# /usr/local/bin/php /var/#' deploy/crontab \
      | grep -v '^#' > /etc/cron.d/myclermont-raw \
 && chmod +x /usr/local/bin/myclermont-entrypoint

ENV TZ=UTC \
    PHP_FPM_MAX_CHILDREN=16
EXPOSE 80
VOLUME ["/var/www/myclermont/storage", "/var/www/myclermont/public/media"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/v1/health") === false ? 1 : 0);' -d allow_url_fopen=1 || exit 1

ENTRYPOINT ["myclermont-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
