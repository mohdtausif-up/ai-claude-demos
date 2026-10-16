#!/bin/sh
set -e
cd /var/www/html

if [ "$ROLE" = "app" ]; then
    mkdir -p public/uploads storage/imports storage/logs
    chown -R www-data:www-data public/uploads storage
    echo "[entrypoint] running migrations"
    php bin/migrate.php
fi

exec "$@"
