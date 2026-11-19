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

# Hurl runs the demo scenarios (`demo/run.sh`). The version is the one CI installs
# (.github/workflows/demo.yml), and a test keeps the two equal. The image is pinned
# by its digest (the index, so both architectures): Docker verifies what it pulls.
ARG HURL_VERSION=8.1.0
ARG HURL_IMAGE_DIGEST=sha256:f8aed33658ba793ed53c5a5b2e57cadd9ec9322c8b56c550652c1f4cbaa241c5
FROM ghcr.io/orange-opensource/hurl:${HURL_VERSION}@${HURL_IMAGE_DIGEST} AS hurl

FROM php:${PHP_VERSION}-cli-alpine

# An ARG set before the first FROM is not visible inside a stage.
ARG HURL_VERSION

# git   — Composer needs it to install packages from source
# unzip — Composer needs it to extract dist archives
# bash is there because demo/run.sh is a bash script and the base image has none.
# libcurl, libxml2 and libgcc are the libraries Hurl's binary links against.
RUN apk add --no-cache git unzip bash libcurl libxml2 libgcc

# pcov powers `composer test:coverage`. It is lighter than Xdebug and sufficient
# for line coverage. The build toolchain is removed in the same layer so it does
# not remain in the final image.
RUN apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=hurl /usr/bin/hurl /usr/local/bin/hurl
# Docker ignores the tag when a digest is given, so this line is what proves the
# copied binary is the version named above. It also fails if the binary cannot run.
RUN hurl --version | grep -q "^hurl ${HURL_VERSION} "

# The base image ships memory_limit=128M, which PHPStan exceeds while analysing
# a Laravel application graph — it crashes its worker with a bare
# "child process error (exit code 255)" that names no cause. Development tooling
# needs headroom; this is a CLI container, never a production runtime.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/zz-development.ini

# Composer runs as the host user (see compose.yaml), which has no home directory
# inside the container. Point its cache somewhere writable instead.
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_MEMORY_LIMIT=-1

# The directory has to exist, and be writable by any user, or Docker creates the
# `composer-cache` volume's root owned by root and Composer runs without a cache.
RUN mkdir -p /tmp/composer && chmod 1777 /tmp/composer

# The checkout is mounted from the host, and git refuses a repository owned by
# another user. HOME is not writable for the host user, so this goes in the system
# config, written here as root.
RUN git config --system --add safe.directory /app

WORKDIR /app
