# AIRR backend (Laravel) image. Build context = repo root.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_pgsql zip bcmath
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/ ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    && php artisan config:cache || true

EXPOSE 9000
CMD ["php-fpm"]
