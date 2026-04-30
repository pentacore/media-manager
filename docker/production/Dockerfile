# syntax=docker/dockerfile:1.7

# ---------- builder ----------
FROM php:8.5-cli-alpine AS builder

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

# Builder needs PHP only to run composer (vendor/ ships in the runtime image).
# It does NOT boot artisan: Wayfinder + Typefinder TypeScript types are produced
# upstream (CI) so the Vite build runs with SKIP_TYPE_GENERATION=1 and never
# shells out to PHP. That's why no .env, key:generate, or DB scaffolding here.
RUN apk add --no-cache \
        git \
        unzip \
        libzip \
        nodejs \
        npm

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

# Copy app source — caller must have run `php artisan wayfinder:generate --with-form`
# and `php artisan typefinder:generate` so the generated .d.ts files are in the context.
COPY . .

ENV SKIP_TYPE_GENERATION=1
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && npm run build \
    && rm -rf node_modules

# ---------- runtime ----------
# FrankenPHP = PHP + Caddy in a single binary; Laravel Octane keeps the framework booted across
# requests in worker mode (~2-4× throughput vs cold-boot per request). Octane generates its own
# Caddyfile at runtime under storage/octane/frankenphp/.
FROM dunglas/frankenphp:1-php8.5-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    RUN_MIGRATIONS=false

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

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD /usr/local/bin/healthcheck

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD []
