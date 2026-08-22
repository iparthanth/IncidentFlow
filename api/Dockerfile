# syntax=docker/dockerfile:1.7
#
# IncidentFlow API image.
#
# Multi-stage so the runtime layer never contains Composer, a compiler, or the
# dev dependencies — three things that are useful for ten seconds at build time
# and are attack surface for the life of the container.

# ---------------------------------------------------------------------------
# Stage 1 — PHP extensions, built once and copied into the runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS extensions

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        bcmath \
        opcache \
    && apk del .build-deps

# ---------------------------------------------------------------------------
# Stage 2 — Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2.8 AS vendor

WORKDIR /app

# Only the manifests, so this layer is cached until a dependency actually
# changes — editing application code must not re-resolve the whole tree.
COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---------------------------------------------------------------------------
# Stage 3 — Runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# `tini` gives PHP-FPM a real init so signals reach it and shutdown is clean —
# without it a `docker stop` becomes a ten-second wait and a SIGKILL.
RUN apk add --no-cache \
        postgresql-libs \
        tini \
    && rm -rf /var/cache/apk/*

COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

WORKDIR /var/www/html

# Runs as a non-root user. The `www-data` user already exists in the base
# image; the point is that nothing in the container runs as uid 0.
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

COPY --chown=www-data:www-data docker/php.ini /usr/local/etc/php/conf.d/99-incidentflow.ini
COPY --chown=www-data:www-data docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-incidentflow.conf
COPY --chown=www-data:www-data docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/keys bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Build metadata, surfaced on /api/v1/health/live so an operator can tell which
# build is serving a request without shelling in.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

USER www-data

EXPOSE 9000

# The probe talks to PHP-FPM's own ping endpoint rather than to the app: a
# liveness check that depends on the database turns a database outage into an
# orchestrator-driven crash loop.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
