# FF Arena — G6 Production Deployment Pipeline
# Multi-stage, production-hardened, PHP-FPM + nginx + supervisor
# G4: Includes phpredis extension for Redis cache + queue + distributed locks
# G5: Includes Reverb dependencies for WebSockets
# G6: Production hardening, non-root, health checks, optimized layers

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-fpm-alpine AS app
WORKDIR /var/www/html

# Install system deps + PHP extensions
# postgresql-client for Phase 16 backup engine, $PHPIZE_DEPS for phpredis
RUN apk add --no-cache \
    nginx \
    supervisor \
    sqlite \
    postgresql-client \
    curl \
    bash \
    $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite bcmath intl opcache \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

# Copy vendor and frontend build
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

# Copy application
COPY . .

# Production hardening: non-root, permissions
RUN addgroup -S ffarena && adduser -S ffarena -G ffarena \
    && chown -R ffarena:ffarena storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R ffarena:ffarena /var/log \
    && mkdir -p /var/run/nginx /var/run/supervisor \
    && chown -R ffarena:ffarena /var/run/nginx /var/run/supervisor

# Copy configs
COPY deploy/nginx.conf /etc/nginx/nginx.conf
COPY deploy/supervisor-ffarena.conf /etc/supervisor/conf.d/ffarena.conf

ENV APP_ENV=production APP_DEBUG=false
ENV LOG_CHANNEL=stack LOG_LEVEL=warning
ENV CACHE_STORE=redis QUEUE_CONNECTION=redis BROADCAST_CONNECTION=reverb

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://127.0.0.1:80/health/live || exit 1

EXPOSE 80 8080

# G6: Entrypoint with migrations, cache, and rollback support
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/ffarena.conf"]
