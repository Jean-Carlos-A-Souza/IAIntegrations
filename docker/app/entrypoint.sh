#!/bin/sh
set -e

APP_DIR="/var/www/html"

cd "$APP_DIR"

echo "Booting entrypoint... APP_ENV=${APP_ENV:-undefined} ROLE=${SERVICE_ROLE:-web}"

# DEV/LOCAL: cria .env a partir do exemplo se não existir
if [ "${APP_ENV:-local}" != "production" ]; then
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

# Dependências (se você já faz no build, pode até remover)
if [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
  echo "Installing composer dependencies..."
  composer install --no-interaction --prefer-dist --no-progress
fi

# --- APP_KEY: validação forte (principal ponto do seu erro) ---
# Em produção, o Render DEVE injetar APP_KEY via env var.
# Se estiver vazio, tentamos gerar (bom para teste/free), mas o ideal é setar no painel.
if [ "${APP_ENV:-local}" = "production" ]; then
  echo "Production mode. APP_KEY length: ${#APP_KEY}"

  # Se APP_KEY não veio, tenta gerar dentro do container (opcional, mas ajuda a subir)
  # Observação: isso não persiste se o container reiniciar, então o correto é setar no Render.
  if [ -z "${APP_KEY:-}" ]; then
    echo "WARNING: APP_KEY is empty. Trying to generate a temporary key..."
    # Gera e exporta para o processo atual (não imprime a chave)
    GENERATED_KEY="$(php artisan key:generate --show 2>/dev/null || true)"
    if [ -n "$GENERATED_KEY" ]; then
      export APP_KEY="$GENERATED_KEY"
      echo "Temporary APP_KEY generated. Length: ${#APP_KEY}"
    else
      echo "ERROR: Failed to generate APP_KEY. Please set APP_KEY in Render env vars."
    fi
  fi

  # Se mesmo assim não tiver chave, não cacheia config (senão “gruda” errado)
  if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY still missing. Skipping config cache to avoid locking bad cache."
  else
    # Limpa caches antes de cachear (evita cache velho sem chave)
    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan route:clear || true
    php artisan view:clear || true

    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
  fi
else
  # Local/dev: manter mais simples, mas também limpar pode ajudar
  php artisan config:clear || true
  php artisan cache:clear || true
fi

ROLE="${SERVICE_ROLE:-web}"

if [ "$ROLE" = "web" ]; then
  # Só roda migrate no web
  php artisan migrate --force || true

  # Render injeta a porta em $PORT
  exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

elif [ "$ROLE" = "queue" ]; then
  exec php artisan queue:work --tries=3 --timeout=120 --no-interaction

elif [ "$ROLE" = "scheduler" ]; then
  # Loop scheduler (Render free)
  while true; do
    php artisan schedule:run --verbose --no-interaction || true
    sleep 60
  done

else
  exec "$@"
fi
