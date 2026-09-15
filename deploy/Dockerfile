# FF Arena — optional container image (multi-stage, PHP-FPM).
# The reference deployment is nginx + PHP-FPM + supervisor; this Dockerfile
# is provided for teams that deploy containers instead.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.4-fpm-alpine AS app
WORKDIR /var/www/html

# postgresql-client provides pg_dump/pg_restore for the Phase 16 backup engine.
RUN apk add --no-cache nginx supervisor sqlite postgresql-client \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite bcmath intl

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production APP_DEBUG=false

EXPOSE 80
CMD ["php-fpm"]
