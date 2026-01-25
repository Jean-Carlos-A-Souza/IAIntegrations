#!/bin/sh
set -e

APP_DIR="/var/www/html"
cd "$APP_DIR"

# Detecta Render automaticamente
IS_RENDER="false"
if [ -n "${RENDER_SERVICE_ID:-}" ] || [ -n "${RENDER_EXTERNAL_HOSTNAME:-}" ] || [ "${RENDER:-}" = "true" ]; then
  IS_RENDER="true"
fi

# Se estiver no Render e APP_ENV não veio, força production
if [ "$IS_RENDER" = "true" ] && [ -z "${APP_ENV:-}" ]; then
  export APP_ENV="production"
fi

ROLE="${SERVICE_ROLE:-web}"

echo "Booting entrypoint... APP_ENV=${APP_ENV:-undefined} RENDER=$IS_RENDER ROLE=$ROLE"

# ------------------------------------------------------------
# DEV/LOCAL: cria .env a partir do exemplo, se não existir
# Render: NUNCA copia/cria .env (usa apenas env vars do painel)
# ------------------------------------------------------------
if [ "$IS_RENDER" != "true" ] && [ "${APP_ENV:-local}" != "production" ]; then
  if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
    echo "DEV: copying .env.example -> .env"
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  fi
fi

# Pastas necessárias do Laravel
mkdir -p \
  "$APP_DIR/storage/app" \
  "$APP_DIR/storage/app/public" \
  "$APP_DIR/storage/framework/cache" \
  "$APP_DIR/storage/framework/sessions" \
  "$APP_DIR/storage/framework/views" \
  "$APP_DIR/storage/logs" \
  "$APP_DIR/bootstrap/cache"

chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true

# Dependências (se você já instala no build, pode remover)
if [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
  echo "Installing composer dependencies..."
  composer install --no-interaction --prefer-dist --no-progress
fi

# ------------------------------------------------------------
# PRODUÇÃO (Render): exige APP_KEY via env var (secret)
# e evita cache velho / cache sem chave
# ------------------------------------------------------------
if [ "${APP_ENV:-local}" = "production" ]; then
  if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY is missing."
    echo "Set APP_KEY in Render > Settings > Environment as a Secret."
    exit 1
  fi

  # Limpa caches para não "grudar" config antiga
  php artisan optimize:clear || true

  # Cachear é opcional (melhora performance)
  php artisan config:cache || true
  php artisan route:cache || true
  php artisan view:cache || true
else
  # DEV/local: costuma ajudar a evitar dor de cabeça
  php artisan optimize:clear || true
fi

# ------------------------------------------------------------
# Start por role
# ------------------------------------------------------------
if [ "$ROLE" = "web" ]; then
  # Migrar em produção pode ser ok, mas se preferir controlar, remova.
  php artisan migrate --force || true

  # Render injeta a porta em $PORT
  exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

elif [ "$ROLE" = "queue" ]; then
  exec php artisan queue:work --tries=3 --timeout=120 --no-interaction

elif [ "$ROLE" = "scheduler" ]; then
  # Loop do scheduler (Render free)
  while true; do
    php artisan schedule:run --verbose --no-interaction || true
    sleep 60
  done

else
  exec "$@"
fi
