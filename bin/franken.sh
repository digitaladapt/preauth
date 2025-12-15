#!/bin/sh

# TODO make a Dockerfile which starts with this image as our base,
# add APCu, and our code...

# absolute path to our parent folder
project_dir=$(readlink -f "$0" | xargs dirname | xargs dirname)

echo '--- clearing the cache ------------------------------------------'
"$project_dir/bin/console" cache:clear
echo '--- starting up docker ------------------------------------------'
docker run \
    -e FRANKENPHP_CONFIG="worker ./public/index.php" \
    -e APP_RUNTIME=Runtime\\FrankenPhpSymfony\\Runtime \
    -v /home/andrew/code/new-preauth/preauth:/app \
    -p 80:80 -p 443:443 -p 443:443/udp \
    dunglas/frankenphp
