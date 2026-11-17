ARG PHP_VERSION=8.2

FROM php:${PHP_VERSION}-fpm

COPY dockerfiles/php/php.ini /usr/local/etc/php/conf.d/99-overrides.ini

RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd zip intl

RUN mkdir -p /var/www/html

ARG SMF_VERSION

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

COPY dockerfiles/php/download-smf.sh /usr/local/bin/download-smf.sh
COPY dockerfiles/php/install-smf-languages.sh /usr/local/bin/install-smf-languages.sh
COPY dockerfiles/downloads/ /tmp/smf-downloads/

# SMF core
RUN set -eux; \
    SMF_VERSION_DASH="${SMF_VERSION//./-}"; \
    sh /usr/local/bin/download-smf.sh "smf_${SMF_VERSION_DASH}_install.zip" /tmp/smf.zip \
    && unzip /tmp/smf.zip -d /var/www/html \
    && rm /tmp/smf.zip

# SMF language packs (installed from whatever smf_<version>_language-*.zip is present)
RUN set -eux; \
    SMF_VERSION_DASH="${SMF_VERSION//./-}"; \
    sh /usr/local/bin/install-smf-languages.sh "$SMF_VERSION_DASH" /var/www/html

# SMF RESTful API mod (https://github.com/dragomano/smf-restful-api):
# the archive is placed into Packages/ so the Package Manager shows it,
# and its files are extracted exactly like the installer's <require-dir> would.
RUN set -eux; \
    cp /tmp/smf-downloads/smf_restful_api.zip /var/www/html/Packages/smf_restful_api.zip \
    && unzip -q -o /var/www/html/Packages/smf_restful_api.zip -d /var/www/html \
    && ls -l /var/www/html/Sources/API /var/www/html/Themes/default/API.template.php

# install.php and the SQL scripts are only needed for the one-time install session
# (see compose.install.yaml); the shipped image boots an already installed forum.
RUN rm -f /var/www/html/install.php \
    /var/www/html/install_2-1_mysql.sql \
    /var/www/html/install_2-1_postgresql.sql \
    /var/www/html/Settings.php \
    /var/www/html/Settings_bak.php

COPY dockerfiles/Settings.php /var/www/html/Settings.php
COPY dockerfiles/Settings.php /var/www/html/Settings_bak.php

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html
