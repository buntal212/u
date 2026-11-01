FROM php:8.3-cli-bookworm

ARG UIH_UID=1000
ARG UIH_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        xml \
        zip \
    && groupadd --gid "${UIH_GID}" uih \
    && useradd --uid "${UIH_UID}" --gid uih --create-home --shell /bin/bash uih \
    && mkdir -p /tmp/composer \
    && chown -R uih:uih /tmp/composer \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
USER uih

ENV COMPOSER_HOME=/tmp/composer
