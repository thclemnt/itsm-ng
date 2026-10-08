ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    default-mysql-client \
    git \
    libbz2-dev \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libldap2-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libxslt1-dev \
    libzip-dev \
    nodejs \
    npm \
    unzip \
    zip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
 && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    bz2 \
    exif \
    gd \
    intl \
    ldap \
    mysqli \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    soap \
    xsl \
    zip \
 && if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then \
      docker-php-ext-install -j"$(nproc)" opcache; \
    fi \
 && curl --fail --silent --show-error --location \
      --retry 3 --retry-delay 2 --retry-max-time 120 --connect-timeout 10 --max-time 60 \
      --output /tmp/apcu-5.1.28.tgz https://pecl.php.net/get/apcu-5.1.28.tgz \
 && curl --fail --silent --show-error --location \
      --retry 3 --retry-delay 2 --retry-max-time 120 --connect-timeout 10 --max-time 60 \
      --output /tmp/redis-6.3.0.tgz https://pecl.php.net/get/redis-6.3.0.tgz \
 && printf '%s\n' \
      'ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf  /tmp/apcu-5.1.28.tgz' \
      '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5  /tmp/redis-6.3.0.tgz' \
    | sha256sum --check --strict \
 && pecl install /tmp/apcu-5.1.28.tgz /tmp/redis-6.3.0.tgz \
 && rm /tmp/apcu-5.1.28.tgz /tmp/redis-6.3.0.tgz \
 && docker-php-ext-enable apcu redis \
 && rm -rf /var/lib/apt/lists/*

RUN git config --system --add safe.directory /var/itsm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN useradd --create-home --home-dir /home/itsm --uid 1000 --shell /bin/bash itsm

WORKDIR /var/itsm
