#!/bin/sh
# Dev utility — builds and runs the preauth container locally.
# Not for production use.

docker container rm preauth
docker build . -t digitaladapt/preauth:dev
docker run --name preauth \
    -e APP_ENV=dev \
    -e APP_DEBUG=true \
    -e APP_SECRET=f88a1074691c40415be4439345b79f69 \
    -e APP_SHARE_DIR=var/share \
    -e DEFAULT_URI=http://localhost \
    -v ./var/share:/app/var/share \
    -p 8000:80 \
    digitaladapt/preauth:dev
