# syntax=docker/dockerfile:1

# --- Frontend: Vite build into public/build ---------------------------------
FROM node:24-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
RUN npm run build

# --- Runtime base: PHP 8.4 + nginx + fpm, runs as www-data ------------------
FROM serversideup/php:8.4-fpm-nginx@sha256:792c458ebd3d8e25515bf5c4b6e7800feee8c84f9788c4e39ae35ad41ebdc706 AS base
USER root
RUN install-php-extensions gd intl exif
# Legacy `*.php` URLs go to Laravel, not straight to PHP-FPM.
COPY docker/nginx/legacy-php.conf /etc/nginx/server-opts.d/legacy-php.conf
# Realtime: /app to the optional reverb service (starts fine without it).
COPY docker/nginx/reverb.conf /etc/nginx/server-opts.d/reverb.conf
# X-Frame-Options is set by the app (SecurityHeaders), which drops it for the FiveM CEF webview.
# The other security headers stay. Fails the build if upstream changes the line.
RUN f=/etc/nginx/server-opts.d/security.conf \
    && grep -q '^add_header X-Frame-Options' "$f" \
    && sed -i '/^add_header X-Frame-Options/d' "$f" \
    && ! grep -q 'X-Frame-Options' "$f"
USER www-data

# --- Composer: production dependencies only -----------------------------------
FROM base AS vendor
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# --- Final image --------------------------------------------------------------
FROM base
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/ignis-entrypoint
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
# The installed version (App\Update\InstalledVersion reads VERSION first). `dev` writes none: no update by click.
ARG IGNIS_VERSION=dev
RUN if [ -n "$IGNIS_VERSION" ] && [ "$IGNIS_VERSION" != "dev" ]; then printf '%s\n' "$IGNIS_VERSION" > VERSION; fi

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_STACK=daily,stderr \
    LOG_LEVEL=warning \
    LOG_DAILY_DAYS=14 \
    PHP_OPCACHE_ENABLE=1

ENTRYPOINT ["ignis-entrypoint"]
CMD ["/init"]
