#!/bin/sh

# absolute path to our parent folder
project_dir=$(readlink -f "$0" | xargs dirname | xargs dirname)

docker container rm preauth
docker build . -t digtialadapt/preauth:dev
docker run --name preauth \
    -e APP_ENV=dev \
    -e APP_DEBUG=true \
    -e APP_SECRET=f88a1074691c40415be4439345b79f69 \
    -e APP_SHARE_DIR=var/share \
    -e DEFAULT_URI=http://localhost \
    -p 80:80 \
    digtialadapt/preauth:dev

# -v $project_dir/src:/app/src
