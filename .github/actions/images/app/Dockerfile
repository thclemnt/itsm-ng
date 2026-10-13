ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-bookworm AS app

ENV DEBIAN_FRONTEND=noninteractive

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    ca-certificates curl default-mysql-client git nodejs npm postgresql-client unzip zip \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions \
    apcu bcmath bz2 exif gd intl ldap mysqli opcache pcntl \
    pdo_mysql pdo_pgsql pgsql soap xsl zip

RUN install-php-extensions redis \
 || (sleep 5 && install-php-extensions redis)

RUN git config --system --add safe.directory /var/itsm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN useradd --create-home --home-dir /home/itsm --uid 1000 --shell /bin/bash itsm

WORKDIR /var/itsm

FROM app AS e2e

COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
RUN npx --yes playwright@1.59.1 install --with-deps chromium
