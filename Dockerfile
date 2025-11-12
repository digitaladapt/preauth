FROM php:8.4-fpm-alpine

# get compose so we can install our php dependencies
COPY --from=composer /usr/bin/composer /usr/bin/composer

# app is stored here
RUN mkdir /preauth
WORKDIR /preauth
COPY . /preauth/

# sessions are stored here
RUN mkdir -p /tmp/sessions

# fcgi command for the healthcheck
RUN apk add fcgi

# install our php dependencies
RUN composer install

EXPOSE 9000

HEALTHCHECK --interval=60s --retries=3 --start-interval=1s --start-period=10s --timeout=5s \
    CMD SCRIPT_NAME=/health.php SCRIPT_FILENAME=/preauth/health.php REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect localhost:9000 | grep 'online' || exit 1

ENTRYPOINT /preauth/init.sh

