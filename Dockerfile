# syntax=docker/dockerfile:1.7
#
# PreAuth — one app, one image.
#
# Multi-stage FrankenPHP build: dependencies in a builder, the runtime image
# only gets the finished tree. Runtime: FrankenPHP worker mode, non-root,
# state on /data. TLS is terminated upstream of the container; FrankenPHP
# serves :80.
#
# Build context: the whole tree (`COPY . .`), narrowed by .dockerignore. The
# allowlist that used to live in the eight `COPY ./x /app/x` below is in that
# file now — a directory that must ship is a directory it does not exclude.
#
# Secrets are injected at runtime as env vars, never baked in (§8.12).

# ── Stage: build — composer dependencies + prod app ────────────────────────
FROM php:8.5-trixie AS build

# Build-time set: git (composer resolves packages over VCS) and unzip (dist
# extraction). Neither reaches the runtime image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# APCu and Composer, both only needed to compile the application.
RUN pecl install apcu \
    && docker-php-ext-enable apcu
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Manifests first so the dependency layer only rebuilds when they change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# Copy the application. .dockerignore keeps vendor/, var/, tests/ and the
# local env files out of the context; composer install has already run, so
# its vendor/ wins.
COPY . .

# src/ was not in the context when composer install ran, so the authoritative
# classmap has to be rebuilt now that the application code is present.
#
# There is deliberately no `composer dump-env` step: preauth does not depend
# on symfony/dotenv (it is absent from composer.lock), so nothing reads a
# .env file at runtime and the dump would only add a dead file. Runtime
# configuration comes from the environment, with the defaults documented in
# config/services.yaml.
RUN composer dump-autoload --classmap-authoritative --no-dev

# Build-time smoke of the autoloader + config compile. No APP_SECRET is
# needed: %env(APP_SECRET)% is not resolved at compile time, and the cache is
# cleared afterwards anyway — the real warm-up runs at container start with
# the injected secrets (entrypoint; §8.12).
#
# The cache is written to the share dir, not var/cache: the runtime image
# ships without a warmed var/cache, so the first container start does the
# build for its own APP_SECRET (and the pages/ filesystem pool needs a
# writable dir owned by the app user).
RUN APP_ENV=prod APP_SHARE_DIR=/data/preauth bin/console cache:warmup \
    && rm -rf var/cache/* var/log/*

# ── Stage: app — the runtime image ─────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS app

# Runtime set: curl is the HEALTHCHECK's probe; APCu is the state store.
# The base image ships the install-php-extensions script, which builds the
# extension and removes its own build dependencies afterwards — so git,
# autoconf and gcc never reach this stage the way `pecl install` needed them.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && install-php-extensions apcu \
    && rm -rf /var/lib/apt/lists/*

# PHP configuration. The packaged production baseline is copied in first
# (the base image ships the template, not an active php.ini), then the app's
# own overrides are layered on top of it — they restate the security-critical
# switches so the intent survives a base-image default changing underneath us.
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-preauth.ini
RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
COPY --from=build /app /app

# Non-root runtime user (Guiding Light §6.4). uid/gid 1000, same convention
# as task-loom/task-weaver/context-shuttle. /data holds the cache pools the
# app writes at runtime, /config is Caddy's own XDG dir.
RUN groupadd --system --gid 1000 app \
 && useradd  --system --uid 1000 --gid app \
             --home-dir /app --shell /usr/sbin/nologin app \
 && mkdir -p /data/preauth /config \
 && chown -R app:app /app /data

USER app

# FrankenPHP listens on :80; TLS is terminated by the external proxy.
# APP_SHARE_DIR points the filesystem cache pools (sessions, rate limiter)
# at the volume. MAX_REQUESTS is a build arg so images can bake in a
# different worker-recycle default; the Caddyfile placeholder reads it.
ARG MAX_REQUESTS=500
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SHARE_DIR=/data/preauth \
    SERVER_NAME=:80 \
    MAX_REQUESTS=$MAX_REQUESTS

# Persistent state: cache pools (sessions, backup codes, rate limits) and
# Caddy's data. Only /data is needed at runtime; /config is declared because
# the base image points XDG_CONFIG_HOME at it.
VOLUME ["/config", "/data"]

EXPOSE 80

# Liveness: Caddy's own admin endpoint, bound to loopback inside the
# container, exactly as the base image declares it (restated here so the
# probe does not depend on the upstream default staying put). The app's own
# routes cannot serve this: an unauthenticated request gets the login page
# with a 401, so `curl -f` against HTTP would always report unhealthy.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:2019/metrics || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
