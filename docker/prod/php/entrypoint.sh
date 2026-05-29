#!/bin/sh
set -e

# Ensure required runtime dirs exist (idempotent)
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log

# No recursive chown here (we already baked correct perms in the image)

exec "$@"