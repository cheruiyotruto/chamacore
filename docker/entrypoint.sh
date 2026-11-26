#!/bin/sh
set -eu

cd /var/www/html

# A named volume replaces the image's storage directory on first boot, so make
# sure Laravel's writable directories exist and are owned by the FPM user.
mkdir -p \
    bootstrap/cache \
    storage/app/backups \
    storage/app/documents \
    storage/app/private \
    storage/app/public \
    storage/fonts \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs
chown -R www-data:www-data bootstrap/cache storage

if [ ! -f bootstrap/cache/packages.php ]; then
    su-exec www-data php artisan package:discover --ansi || true
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su-exec www-data php artisan migrate --force --no-interaction
fi

if [ "${RUN_OPTIMIZE:-false}" = "true" ]; then
    su-exec www-data php artisan optimize
fi

exec su-exec www-data "$@"
