#!/bin/sh
# Dev utility — builds and runs the preauth container locally.
# Not for production use.
# APP_SECRET should be set in your environment or .env file.

docker container rm preauth 2>/dev/null
docker build . -t digitaladapt/preauth:dev
docker run --name preauth \
    -e APP_ENV=dev \
    -e APP_DEBUG=true \
    -e APP_SECRET="${APP_SECRET:-$(openssl rand -hex 16)}" \
    -e APP_SHARE_DIR=var/share \
    -e DEFAULT_URI=http://localhost \
    -v ./var/share:/app/var/share \
    -p 8000:80 \
    digitaladapt/preauth:dev
