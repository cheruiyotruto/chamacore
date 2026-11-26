#!/bin/sh
set -eu

cd /var/www/html

# A mounted volume may replace the image's storage/ or bootstrap/cache/ on the
# first boot, so recreate the writable tree on every start.
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
chown -R www-data:www-data bootstrap/cache storage 2>/dev/null || true

if [ ! -f bootstrap/cache/packages.php ]; then
    su-exec www-data php artisan package:discover --ansi || true
fi

# Optional one-shot setup. Enable RUN_MIGRATIONS/RUN_SEED/RUN_OPTIMIZE on the
# web service only, never on workers (they would race each other).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su-exec www-data php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEED:-false}" = "true" ]; then
    su-exec www-data php artisan db:seed --force --no-interaction
fi

if [ "${RUN_OPTIMIZE:-false}" = "true" ]; then
    su-exec www-data php artisan optimize
fi

# "web" (the image default) starts FPM + nginx. Any other command — queue
# workers, schedule:work, Fly release_command, one-off artisan calls — is
# executed directly as the application user.
case "${1:-web}" in
    web)
        export PORT="${PORT:-8080}"
        envsubst '${PORT}' \
            < /etc/nginx/http.d/default.conf.template \
            > /etc/nginx/http.d/default.conf
        php-fpm -D
        exec nginx -g 'daemon off;'
        ;;
    *)
        exec su-exec www-data "$@"
        ;;
esac
