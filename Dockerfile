FROM php:8.4-fpm-alpine

# get compose so we can install our php dependencies
COPY --from=composer /usr/bin/composer /usr/bin/composer

# app is stored here
RUN mkdir /preauth
WORKDIR /preauth
COPY . /preauth/

# add php config for rate limit monitoring
RUN mkdir -p /usr/local/etc/php/conf.d
COPY preauth-php.ini /usr/local/etc/php/conf.d/preauth-php.ini

# login sessions are stored here
RUN mkdir -p /tmp/sessions

# rate limit monitoring information is stored here
RUN mkdir -p /tmp/monitor

# fcgi command for the healthcheck
RUN apk add fcgi

# install our php dependencies
RUN composer install

EXPOSE 9000

HEALTHCHECK --interval=5m --retries=3 --start-interval=5s --start-period=50s --timeout=5s \
    CMD SCRIPT_NAME=/health.php SCRIPT_FILENAME=/preauth/health.php REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect localhost:9000 | grep 'online' || exit 1

ENTRYPOINT ["/preauth/init.sh"]

