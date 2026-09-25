#!/bin/sh
#
# PreAuth container entrypoint.
#
# Responsibilities:
#   1. Warm the prod cache with the injected secrets.
#   2. Hand off to CMD (FrankenPHP server, or a console override:
#      `docker exec -it preauth bin/console app:generate-backup-codes`).
#
# Secrets are env vars injected at runtime, never baked into images (§8.12).
# The container has no shell to hand out otherwise — it runs as an unprivileged
# user with a nologin shell — so the real boot validation is
# `cache:warmup` failing here, which is also what makes it worth doing.

set -e

if [ "$APP_ENV" = "prod" ]; then
    echo "Warming cache..."
    php bin/console cache:warmup
fi

exec "$@"
