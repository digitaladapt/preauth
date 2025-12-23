# use build image, to simplify final image
FROM php:8.4-trixie AS build

# install APCu and composer
RUN pecl install apcu && \
    docker-php-ext-enable apcu
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apt-get update && \
    apt-get install -y unzip git

# symfony required environment variables
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV DEFAULT_URI='http://'

# load application into build image
RUN mkdir -p /app/bin
WORKDIR /app
COPY ./bin/console   /app/bin/console
COPY ./config        /app/config
COPY ./public        /app/public
COPY ./src           /app/src
COPY ./templates     /app/templates
COPY ./composer.json /app/composer.json
COPY ./composer.lock /app/composer.lock
COPY ./symfony.lock  /app/symfony.lock

# install application dependencies
RUN composer install --no-dev --optimize-autoloader
RUN composer dump-env prod --empty

# start creating final image
FROM dunglas/frankenphp:php8.4-trixie

# install APCu
RUN pecl install apcu && \
    docker-php-ext-enable apcu

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
RUN echo 'expose_php = off' > $PHP_INI_DIR/conf.d/restrict.ini

# app uses var folder for cache storage
VOLUME ["/app/var"]

# runs http on standard port
EXPOSE 80

# healthcheck
HEALTHCHECK --interval=5m \
    --retries=3 \
    --start-interval=1s \
    --start-period=10s \
    --timeout=2s \
    CMD curl http://localhost || exit 1
