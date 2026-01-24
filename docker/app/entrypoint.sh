#!/usr/bin/env sh
set -e

if [ ! -f "/var/www/html/.env" ] && [ -f "/var/www/html/.env.example" ]; then
  cp /var/www/html/.env.example /var/www/html/.env
fi

mkdir -p /var/www/html/storage/app \
  /var/www/html/storage/app/public \
  /var/www/html/storage/framework/cache \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache

chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
  composer install --no-interaction --prefer-dist
fi

exec "$@"
