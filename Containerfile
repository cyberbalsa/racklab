# syntax=docker/dockerfile:1.7

ARG NODE_VERSION=24

FROM docker.io/library/node:${NODE_VERSION}-bookworm-slim AS assets
WORKDIR /app

COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm ci --ignore-scripts && npm run build

FROM docker.io/dunglas/frankenphp:php8.3-bookworm AS runtime
ARG BUILD_DATE=unknown
ARG VCS_REF=unknown

LABEL org.opencontainers.image.title="RackLab" \
    org.opencontainers.image.description="RackLab educational lab control plane" \
    org.opencontainers.image.source="https://github.com/cyberbalsa/racklab" \
    org.opencontainers.image.revision="${VCS_REF}" \
    org.opencontainers.image.created="${BUILD_DATE}" \
    org.opencontainers.image.licenses="Apache-2.0"

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1 \
    OCTANE_SERVER=frankenphp \
    PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        postgresql-client \
        podman \
        redis-tools \
        unzip \
        zip; \
    install-php-extensions \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pdo_sqlite \
        posix \
        redis \
        zip; \
    rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY app ./app
COPY artisan ./
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY --from=assets /app/public/build ./public/build

RUN set -eux; \
    mkdir -p \
        bootstrap/cache \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /var/lib/racklab/plugins/config \
        /var/lib/racklab/plugins/packages; \
    composer dump-autoload --no-dev --classmap-authoritative --no-scripts; \
    BROADCAST_CONNECTION=null php artisan package:discover --ansi

EXPOSE 8000 8080
ENTRYPOINT []

FROM runtime AS web
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--https", "--http-redirect", "--caddyfile=/etc/racklab/Caddyfile"]

FROM runtime AS reverb
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]

# Single Horizon image — both racklab-horizon-app.container and
# racklab-horizon-runner.container pull this image; the Quadlet's
# RACKLAB_HORIZON_POOL_GROUP env var (app|runner) tells config/horizon.php
# which supervisor subset to spawn. Replaces the four legacy worker targets
# (provider-worker, script-worker, console-worker, notification-worker).
FROM runtime AS horizon
CMD ["php", "artisan", "horizon"]

FROM runtime AS scheduler-reconciler
CMD ["/bin/sh", "-lc", "while true; do php artisan racklab:reconcile-provider-tasks; php artisan racklab:expire-deployments; php artisan racklab:detect-provider-drift; php artisan racklab:reap-script-containers --max-age=3600; sleep ${RACKLAB_RECONCILER_INTERVAL:-30}; done"]
