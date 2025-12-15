# use build image, to avoid needing composer in final image
FROM composer AS build

# symfony required environment variables
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV DEFAULT_URI='http://'

# load application info build image
RUN mkdir -p /app
WORKDIR /app
COPY . /app/

# install dependencies
RUN composer install --no-dev --optimize-autoloader
RUN composer dump-env prod --empty
RUN php bin/console cache:clear

# start creating final image
FROM dunglas/frankenphp:php8.4-trixie

# symfony required environment variables
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV DEFAULT_URI='http://'

# load application into final image
WORKDIR /app
COPY --from=build /app /app

# configure container
COPY ./Caddyfile /etc/frankenphp/Caddyfile
RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

# install APCu
RUN pecl install apcu && \
    docker-php-ext-enable apcu --ini-name 10-docker-php-ext-apcu.ini

VOLUME ["/app/var"]

EXPOSE 80

HEALTHCHECK --interval=5m --retries=3 --start-interval=1s --start-period=10s --timeout=2s \
    CMD curl http://localhost || exit 1

