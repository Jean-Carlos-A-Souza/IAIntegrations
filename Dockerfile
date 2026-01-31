# Usa o Dockerfile real que já existe no projeto
FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copia o projeto inteiro para dentro da imagem (importante no Render)
COPY . /var/www/html

# Copia o entrypoint para um local padrão e garante LF + permissão
COPY docker/app/entrypoint_clean.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint

ENTRYPOINT ["sh", "/usr/local/bin/entrypoint"]

EXPOSE 8000

# Render injeta a porta em $PORT
CMD ["sh", "-lc", "php artisan migrate --force && php artisan serve --host 0.0.0.0 --port ${PORT:-8000}"]
