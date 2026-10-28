# syntax=docker/dockerfile:1
# The ignis updater container (Weg B): this repository's PHP code (App\Update\*, its Laravel framework, the release
# public key) plus the Docker CLI with the compose plugin. Build context is the repository root:
#   docker compose build updater   (or: docker build -f updater/Dockerfile .)

# --- Composer: production dependencies, no platform checks (the updater needs none of intl/gd/zip) ----------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer config platform-check false \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -rf node_modules public/build resources/js resources/css

# --- Runtime: PHP CLI + docker-cli + compose -------------------------------------------------------------------------
FROM php:8.4-cli-alpine
RUN apk add --no-cache docker-cli docker-cli-compose
WORKDIR /opt/ignis
COPY --from=vendor /app /opt/ignis
RUN mkdir -p storage/framework/cache/data storage/framework/views storage/logs bootstrap/cache \
    && install -d -m 0700 /var/lib/ignis-updater \
    && php artisan package:discover --no-ansi >/dev/null

# No database here: the dump runs in the db container through `docker compose exec`. Logs go to stderr.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    PHP_CLI_SERVER_WORKERS=4 \
    IGNIS_UPDATER_PRIVATE_DIR=/var/lib/ignis-updater

# Root: the Docker socket needs it (the socket is root's anyway). Port 8099 is reachable on the internal network only.
EXPOSE 8099
HEALTHCHECK --interval=30s --timeout=5s CMD php -r 'exit(@fsockopen("127.0.0.1", 8099) ? 0 : 1);'
CMD ["sh", "-c", "php updater/updater.php recover; exec php -S 0.0.0.0:8099 updater/updater.php"]
