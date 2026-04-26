# syntax=docker/dockerfile:1.7

# ---------- builder ----------
FROM php:8.5-cli-alpine AS builder

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apk add --no-cache \
        git \
        unzip \
        libzip \
        icu-libs \
        oniguruma \
        libpng \
        libjpeg-turbo \
        freetype \
        postgresql-libs \
        nodejs \
        npm \
    && wget -q -O - https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
        > /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        sodium \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cache composer layer first
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

# Cache npm layer
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --prefer-offline

# Copy app source (.dockerignore prunes vendor/, node_modules/, etc.)
COPY . .

# Build-time env: artisan must be bootable so wayfinder + typefinder vite plugins can shell out to it.
# Order matters: framework dirs → dump-autoload → .env + key → discover → generate types → vite build.
# .dockerignore prunes storage/framework/{cache,sessions,views} contents, so we recreate the dirs
# before booting Laravel — Blade's Compiler refuses to start without a valid view-cache path.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/app/public storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && cp .env.example .env \
    && php artisan key:generate --force --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form \
    && php artisan typefinder:generate \
    && npm run build \
    && rm -rf node_modules .env

# ---------- runtime ----------
# FrankenPHP = PHP + Caddy in a single binary; Laravel Octane keeps the framework booted across
# requests in worker mode (~2-4× throughput vs cold-boot per request). Octane generates its own
# Caddyfile at runtime under storage/octane/frankenphp/.
FROM dunglas/frankenphp:1-php8.5-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    CONTAINER_ROLE=web \
    RUN_MIGRATIONS=false \
    OCTANE_SERVER=frankenphp

RUN apk add --no-cache \
        bash \
        curl \
        ca-certificates \
        tzdata \
        icu-libs \
        oniguruma \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        postgresql-libs \
    && install-php-extensions \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        sodium \
        zip \
    && addgroup -g 1000 -S app \
    && adduser -u 1000 -S -G app -h /app app \
    && mkdir -p /app /data/caddy /config/caddy \
    && chown -R app:app /app /data/caddy /config/caddy \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

WORKDIR /app

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/production/healthcheck.sh /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/healthcheck

COPY --from=builder --chown=app:app /app /app

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views \
        storage/app/public storage/logs storage/octane/frankenphp bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

# 8080: web role (FrankenPHP/Caddy) OR reverb role — different roles run in different containers
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD /usr/local/bin/healthcheck

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD []
