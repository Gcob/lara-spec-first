# syntax=docker/dockerfile:1

# Development image for contributors.
#
# This container is NOT part of the distributed package. Consumers install
# lara-spec-first with Composer and never see it; it exists only so that working
# on the package requires no local PHP or Composer installation.
#
# PHP_VERSION mirrors one cell of the CI matrix (8.3, 8.4, 8.5):
#   docker compose build --build-arg PHP_VERSION=8.5
ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-cli-alpine

# git   — Composer needs it to install packages from source
# unzip — Composer needs it to extract dist archives
RUN apk add --no-cache git unzip

# pcov powers `composer test:coverage`. It is lighter than Xdebug and sufficient
# for line coverage. The build toolchain is removed in the same layer so it does
# not remain in the final image.
RUN apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The base image ships memory_limit=128M, which PHPStan exceeds while analysing
# a Laravel application graph — it crashes its worker with a bare
# "child process error (exit code 255)" that names no cause. Development tooling
# needs headroom; this is a CLI container, never a production runtime.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/zz-development.ini

# Composer runs as the host user (see compose.yaml), which has no home directory
# inside the container. Point its cache somewhere writable instead.
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app
