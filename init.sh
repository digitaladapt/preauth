#!/bin/sh

# ensure we have required folders
`mkdir -p /tmp/data/sessions`
`mkdir -p /tmp/data/monitor`

# ensure we have required settings
if [ -z "$PREAUTH_KEY" ]; then
    export PREAUTH_KEY=$(cd /preauth && php init-key.php)
fi

if [ -z "$PREAUTH_TOKEN" ]; then
    export PREAUTH_TOKEN=$(cd /preauth && php init-token.php)
fi

# start the process, now that setup is done
exec php-fpm

