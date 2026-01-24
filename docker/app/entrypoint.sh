#!/bin/sh
set -e

# Se existir .env.example e não existir .env, copia (dev/local). Em produção no Render,
# você normalmente define as env vars no painel, então isso não é obrigatório.
if [ ! -f "/var/www/html/.env" ] && [ -f "/var/www/html/.env.example" ]; then
  cp /var/www/html/.env.example /var/www/html/.env
fi

mkdir -p \
  /var/www/html/storage/app \
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

# Produção: opcional (mas recomendado) - cache config/route/view
if [ "$APP_ENV" = "production" ]; then
  php artisan config:cache || true
  php artisan route:cache || true
  php artisan view:cache || true
fi

ROLE="${SERVICE_ROLE:-web}"

if [ "$ROLE" = "web" ]; then
  # Render injeta a porta em $PORT
  php artisan migrate --force || true
  exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
elif [ "$ROLE" = "queue" ]; then
  exec php artisan queue:work --tries=3 --timeout=120
elif [ "$ROLE" = "scheduler" ]; then
  # Loop infinito chamando schedule:run a cada 60s (Render não deixa cron docker fácil)
  while true; do
    php artisan schedule:run --verbose --no-interaction || true
    sleep 60
  done
else
  # fallback: executa o CMD original, se vier
  exec "$@"
fi
