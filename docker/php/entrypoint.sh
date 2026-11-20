#!/bin/sh
# Arranque del contenedor: la caché de Symfony con los secretos del entorno y var/ de www-data.
set -e

cd /var/www/html

if [ "${WARM_CACHE:-0}" = "1" ]; then
    php bin/console cache:clear --no-warmup
    php bin/console cache:warmup
fi

mkdir -p var/jobs var/users var/library
chown -R www-data:www-data var 2>/dev/null || true

exec "$@"
