# syntax=docker/dockerfile:1.7

# MoonShine V (https://github.com/moonshine-software/v) application image,
# adapted from the project's own docker/Dockerfile, built straight from the
# GitHub source of the MSV_VERSION tag.
ARG MSV_VERSION=v1.0.0

FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    fcgi \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    sqlite-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
    zip \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------- Source stage ----------
FROM base AS source

ARG MSV_VERSION
ADD https://github.com/moonshine-software/v/archive/refs/tags/${MSV_VERSION}.tar.gz /tmp/msv.tar.gz
RUN tar -xzf /tmp/msv.tar.gz --strip-components=1 -C /var/www/html && rm /tmp/msv.tar.gz

# ---------- Dependencies stage ----------
FROM source AS deps

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM node:24-alpine AS frontend
WORKDIR /build
COPY --from=source /var/www/html/package.json /var/www/html/package-lock.json ./
RUN npm ci
COPY --from=source /var/www/html/resources ./resources
COPY --from=deps /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY --from=source /var/www/html/vite.config.js ./
RUN npm run build

# ---------- Application stage ----------
FROM source AS app

# Copy vendor from deps stage
COPY --from=deps /var/www/html/vendor ./vendor

# Copy frontend build
COPY --from=frontend /build/public/build ./public/build

# Unattended administrator seeding (runs in the moonshine-migrate service)
COPY dockerfiles/moonshine-v/seed-admin.php ./docker/seed-admin.php

# Local-stack runtime init: same layout as the project's docker/init-runtime.sh
# but without the production environment validation gate.
COPY dockerfiles/moonshine-v/init-runtime.sh ./docker/init-runtime.sh
RUN chmod +x docker/init-runtime.sh

# Ordered patch set for MoonShine upstream sources. Individual patch handlers
# remain small; this entry point provides one failure policy and invocation.
COPY dockerfiles/moonshine-v/patch-*.php ./docker/
COPY dockerfiles/moonshine-v/apply-patches.php ./docker/apply-patches.php
COPY dockerfiles/moonshine-v/fix-panel-titles.php ./docker/fix-panel-titles.php
RUN php docker/apply-patches.php

# Russian translations for the MoonShine core UI (the upstream ships only
# English) and the SMF resource labels, plus the runtime utility that
# generates/localizes the SMF resources (see README).
COPY dockerfiles/moonshine-v/lang/ ./lang/
COPY dockerfiles/moonshine-v/gen-smf-resources-library.php ./docker/gen-smf-resources-library.php
COPY dockerfiles/moonshine-v/gen-smf-resources.php ./docker/gen-smf-resources.php
COPY dockerfiles/moonshine-v/stubs/api-admin/ ./stubs/api-admin/

# Drag-and-drop ordering for generated SMF index pages: the local page stub
# delegates to this helper for categories and boards.
# app/ApiAdmin/Pages is a plain directory, so the file ships with the image.
COPY dockerfiles/moonshine-v/app/ApiAdmin/Pages/RestReorder.php ./app/ApiAdmin/Pages/RestReorder.php
COPY dockerfiles/moonshine-v/app/ApiAdmin/Resources/SmfPageFields.php ./app/ApiAdmin/Resources/SmfPageFields.php

# Panel chrome: 17-colour palette switcher (persisted in the session), dark
# mode as the first-run default and a footer without the upstream
# copyright/links. The trait is mixed into HasPanelSwitcher — which every
# panel layout already uses — so no generated layout needs to change.
COPY dockerfiles/moonshine-v/app/ApiAdmin/Layouts/HasPanelChrome.php ./app/ApiAdmin/Layouts/HasPanelChrome.php
COPY dockerfiles/moonshine-v/views/palette-switcher.blade.php ./resources/views/api-admin/components/palette-switcher.blade.php
COPY dockerfiles/moonshine-v/views/hub-link.blade.php ./resources/views/api-admin/components/hub-link.blade.php

# The Color field's preview (tables and detail pages) prints the swatch plus
# the hex value; show the swatch alone, with the value as a tooltip.
COPY dockerfiles/moonshine-v/views/color.blade.php ./resources/views/vendor/moonshine/components/color.blade.php

# Panel titles keep short abbreviations upper-case: "smf_forum" becomes
# "SMF Forum Admin". Runtime initialization updates old default titles.
COPY dockerfiles/moonshine-v/app/ApiAdmin/Support/PanelTitle.php ./app/ApiAdmin/Support/PanelTitle.php

RUN mkdir -p \
    storage/api-admin \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Generate optimized autoload
RUN composer dump-autoload --optimize

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Make reload script executable
RUN chmod +x docker/reload.sh

COPY --from=source /var/www/html/docker/php/health.conf /usr/local/etc/php-fpm.d/zz-health.conf

EXPOSE 9000

CMD ["php-fpm"]

# Runtime state lives in a single backed-up volume. Keep the source tree immutable.
FROM php:8.5-fpm-alpine AS production
RUN apk add --no-cache fcgi libzip icu-libs oniguruma libpq sqlite-libs
COPY --from=app /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=app /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=app /var/www/html/docker/php/health.conf /usr/local/etc/php-fpm.d/zz-health.conf
WORKDIR /var/www/html
COPY --from=app /var/www/html /var/www/html
RUN php docker/prepare-runtime.php
USER www-data
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s CMD ["sh", "docker/healthcheck.sh"]

FROM docker.angie.software/angie:1.12.1-minimal AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/docker/angie/production.conf /etc/angie/angie.conf
USER 82:82
CMD ["angie", "-g", "daemon off;"]

# The panel is served over HTTPS with a build-time self-signed certificate
# for localhost (the demo must match the forum, which is HTTPS-only too).
# The upstream sed strips the HTTPS forwarding flag first; the steps below
# re-enable it, switch the listener to TLS and install the certificate.
FROM web AS web-local
USER root
RUN sed -i 's/fastcgi_param HTTPS on;/fastcgi_param HTTPS off;/' /etc/angie/angie.conf \
    && apk add --no-cache openssl \
    && mkdir -p /etc/angie/ssl \
    && openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
       -keyout /etc/angie/ssl/panel.key -out /etc/angie/ssl/panel.crt \
       -subj '/CN=localhost' \
       -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
    && sed -i 's|listen 8080;|listen 8080 ssl;\n    ssl_certificate /etc/angie/ssl/panel.crt;\n    ssl_certificate_key /etc/angie/ssl/panel.key;|' /etc/angie/angie.conf \
    && sed -i 's/fastcgi_param HTTPS off;/fastcgi_param HTTPS on;/' /etc/angie/angie.conf \
    && chmod 644 /etc/angie/ssl/panel.key /etc/angie/ssl/panel.crt
USER 82:82

# Keep an untargeted build an application image.
FROM production AS release
