# R9 Full File Content Part 9 - Files 121-135

Total files in this part: 15

## File: ./database/migrations/2026_09_04_000000_create_all_tables.php

```
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {$table->id(); $table->string('name'); $table->string('email')->unique(); $table->timestamp('email_verified_at')->nullable(); $table->string('password'); $table->boolean('is_admin')->default(false); $table->boolean('is_staff')->default(false); $table->boolean('is_active')->default(true); $table->string('phone')->nullable(); $table->rememberToken(); $table->timestamps();});
        Schema::create('password_reset_tokens', function (Blueprint $table) {$table->string('email')->primary(); $table->string('token'); $table->timestamp('created_at')->nullable();});
        Schema::create('sessions', function (Blueprint $table) {$table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index(); $table->string('ip_address',45)->nullable(); $table->text('user_agent')->nullable(); $table->longText('payload'); $table->integer('last_activity')->index();});
        Schema::create('personal_access_tokens', function (Blueprint $table) {$table->id(); $table->morphs('tokenable'); $table->string('name'); $table->string('token',64)->unique(); $table->text('abilities')->nullable(); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();});
        Schema::create('tournaments', function (Blueprint $table) {$table->id(); $table->string('name'); $table->string('slug')->unique(); $table->string('status')->default('draft')->index(); $table->bigInteger('entry_fee_minor')->default(0); $table->bigInteger('prize_pool_minor')->default(0); $table->integer('max_teams')->default(16); $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();});
        Schema::create('wallets', function (Blueprint $table) {$table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('currency',3)->default('BDT'); $table->bigInteger('balance_minor')->default(0); $table->boolean('is_locked')->default(false); $table->timestamps(); $table->unique(['user_id','currency']); $table->index('user_id');});
        Schema::create('ledger_entries', function (Blueprint $table) {$table->id(); $table->foreignId('wallet_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('direction')->index(); $table->bigInteger('amount_minor'); $table->bigInteger('balance_after_minor'); $table->string('reference_type')->nullable()->index(); $table->string('reference_id')->nullable()->index(); $table->string('idempotency_key')->nullable()->unique(); $table->json('metadata')->nullable(); $table->timestamps(); $table->index(['wallet_id','created_at']);});
        Schema::create('payments', function (Blueprint $table) {$table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete(); $table->string('provider')->index(); $table->string('external_id')->unique(); $table->string('provider_reference')->nullable()->unique(); $table->bigInteger('amount_minor'); $table->string('currency',3)->default('BDT'); $table->string('status')->default('created')->index(); $table->string('idempotency_key')->unique(); $table->string('idempotency_fingerprint')->nullable(); $table->json('metadata')->nullable(); $table->timestamp('authorized_at')->nullable(); $table->timestamp('succeeded_at')->nullable(); $table->timestamp('failed_at')->nullable(); $table->timestamps(); $table->index(['user_id','created_at']);});
        Schema::create('payouts', function (Blueprint $table) {$table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete(); $table->bigInteger('amount_minor'); $table->string('currency',3)->default('BDT'); $table->string('status')->default('pending')->index(); $table->string('external_id')->unique(); $table->string('provider')->default('manual'); $table->string('idempotency_key')->unique(); $table->json('metadata')->nullable(); $table->timestamps();});
        Schema::create('webhook_events', function (Blueprint $table) {$table->id(); $table->string('provider')->index(); $table->string('event_type')->nullable()->index(); $table->string('event_id')->unique(); $table->json('payload')->nullable(); $table->string('signature')->nullable(); $table->string('state')->default('received')->index(); $table->integer('attempts')->default(0); $table->text('last_error')->nullable(); $table->timestamp('processed_at')->nullable(); $table->timestamps(); $table->index(['provider','state']);});
        Schema::create('idempotency_records', function (Blueprint $table) {$table->string('key')->primary(); $table->string('fingerprint'); $table->string('operation')->index(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->json('request_body')->nullable(); $table->json('response_body')->nullable(); $table->integer('status_code')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();});
        Schema::create('financial_settlements', function (Blueprint $table) {$table->id(); $table->foreignId('tournament_id')->constrained()->cascadeOnDelete(); $table->bigInteger('total_amount_minor'); $table->string('currency',3)->default('BDT'); $table->string('status')->default('pending')->index(); $table->string('idempotency_key')->unique(); $table->json('metadata')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();});
        Schema::create('cache', function (Blueprint $table) {$table->string('key')->primary(); $table->mediumText('value'); $table->integer('expiration');});
        Schema::create('cache_locks', function (Blueprint $table) {$table->string('key')->primary(); $table->string('owner'); $table->integer('expiration');});
        Schema::create('jobs', function (Blueprint $table) {$table->id(); $table->string('queue')->index(); $table->longText('payload'); $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable(); $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');});
        Schema::create('job_batches', function (Blueprint $table) {$table->string('id')->primary(); $table->string('name'); $table->integer('total_jobs'); $table->integer('pending_jobs'); $table->integer('failed_jobs'); $table->longText('failed_job_ids'); $table->mediumText('options')->nullable(); $table->integer('created_at'); $table->integer('cancelled_at')->nullable(); $table->integer('finished_at')->nullable();});
        Schema::create('failed_jobs', function (Blueprint $table) {$table->id(); $table->string('uuid')->unique(); $table->text('connection'); $table->text('queue'); $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at')->useCurrent();});
    }
    public function down(): void
    {
        Schema::dropIfExists('financial_settlements'); Schema::dropIfExists('idempotency_records'); Schema::dropIfExists('webhook_events'); Schema::dropIfExists('payouts'); Schema::dropIfExists('payments'); Schema::dropIfExists('ledger_entries'); Schema::dropIfExists('wallets'); Schema::dropIfExists('tournaments'); Schema::dropIfExists('personal_access_tokens'); Schema::dropIfExists('sessions'); Schema::dropIfExists('password_reset_tokens'); Schema::dropIfExists('users'); Schema::dropIfExists('cache'); Schema::dropIfExists('cache_locks'); Schema::dropIfExists('jobs'); Schema::dropIfExists('job_batches'); Schema::dropIfExists('failed_jobs');
    }
};
```

## File: ./database/migrations/2026_09_17_000000_create_r9_tables.php

```
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if(!Schema::hasTable('idempotency_records_go')){
            Schema::create('idempotency_records_go', function (Blueprint $table) {
                $table->string('key')->primary(); $table->string('fingerprint'); $table->string('operation')->index(); $table->bigInteger('user_id')->nullable(); $table->json('request_body')->nullable(); $table->json('response_body')->nullable(); $table->integer('status_code')->nullable(); $table->timestamp('expires_at')->nullable()->index(); $table->timestamps();
            });
        }
    }
    public function down(): void{Schema::dropIfExists('idempotency_records_go');}
};
```

## File: ./deploy/Dockerfile

```
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
```

## File: ./deploy/deploy.sh

```
#!/bin/bash
# FF Arena — G6 Deployment Pipeline
# Image build, staging→prod promotion, rollback, health checks
set -euo pipefail

# Config
APP_NAME="ffarena"
REGISTRY="${REGISTRY:-ghcr.io}"
ENVIRONMENT="${1:-staging}"
VERSION="${2:-$(git rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)}"
ROLLBACK_VERSION="${3:-}"

echo "=== FF Arena G6 Deployment Pipeline ==="
echo "Environment: $ENVIRONMENT"
echo "Version: $VERSION"
echo "Timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Validate env
if [[ "$ENVIRONMENT" != "staging" && "$ENVIRONMENT" != "production" ]]; then
  echo "Error: Environment must be staging or production"
  exit 1
fi

# Check required env vars
if [[ -z "${DB_PASSWORD:-}" ]]; then echo "Error: DB_PASSWORD must be set"; exit 1; fi
if [[ -z "${REDIS_PASSWORD:-}" ]]; then echo "Error: REDIS_PASSWORD must be set"; exit 1; fi

# Functions
build_image() {
  echo "Building image $APP_NAME:$VERSION..."
  docker build -f deploy/Dockerfile -t $APP_NAME:$VERSION -t $APP_NAME:latest -t $REGISTRY/$APP_NAME:$VERSION -t $REGISTRY/$APP_NAME:latest .
  echo "Image built: $APP_NAME:$VERSION"
}

push_image() {
  echo "Pushing image to $REGISTRY..."
  docker push $REGISTRY/$APP_NAME:$VERSION
  docker push $REGISTRY/$APP_NAME:latest
  echo "Image pushed"
}

health_check() {
  local url=$1
  local max_attempts=30
  local attempt=0
  echo "Health checking $url..."
  until curl -f -s $url > /dev/null; do
    attempt=$((attempt+1))
    if [[ $attempt -ge $max_attempts ]]; then
      echo "Health check failed after $max_attempts attempts"
      return 1
    fi
    echo "Health check attempt $attempt/$max_attempts failed, waiting 2s..."
    sleep 2
  done
  echo "Health check passed: $url"
  return 0
}

deploy() {
  local env=$1
  local version=$2
  echo "Deploying $version to $env..."
  
  # Set version in .env
  echo "APP_VERSION=$version" >> .env
  
  # Deploy via compose
  if [[ "$env" == "staging" ]]; then
    docker compose -f docker-compose.yml -f deploy/docker-compose.staging.yml up -d --no-deps --build app queue reverb
  else
    docker compose -f deploy/docker-compose.production.yml up -d --no-deps --build app queue queue-critical reverb nginx
  fi
  
  # Wait for health
  sleep 10
  if ! health_check "http://localhost:80/health/live"; then
    echo "Deployment health check failed, rolling back..."
    rollback $env
    exit 1
  fi
  
  if ! health_check "http://localhost:80/health/ready"; then
    echo "Ready check failed (may be degraded), but live is ok — continuing with warning"
  fi
  
  echo "Deployment successful: $env $version"
}

rollback() {
  local env=$1
  local rollback_version=${ROLLBACK_VERSION:-$(cat .last_successful_version 2>/dev/null || echo "latest")}
  echo "Rolling back $env to $rollback_version..."
  
  if [[ "$env" == "staging" ]]; then
    APP_VERSION=$rollback_version docker compose -f docker-compose.yml -f deploy/docker-compose.staging.yml up -d --no-deps app queue reverb
  else
    APP_VERSION=$rollback_version docker compose -f deploy/docker-compose.production.yml up -d --no-deps app queue queue-critical reverb nginx
  fi
  
  sleep 10
  if health_check "http://localhost:80/health/live"; then
    echo "Rollback successful to $rollback_version"
  else
    echo "Rollback health check failed!"
    exit 1
  fi
}

# Main
case "${4:-deploy}" in
  build)
    build_image
    ;;
  push)
    build_image
    push_image
    ;;
  deploy)
    build_image
    deploy $ENVIRONMENT $VERSION
    echo $VERSION > .last_successful_version
    ;;
  rollback)
    rollback $ENVIRONMENT
    ;;
  *)
    build_image
    deploy $ENVIRONMENT $VERSION
    echo $VERSION > .last_successful_version
    ;;
esac

echo "=== Deployment pipeline complete ==="
echo "Version: $VERSION"
echo "Environment: $ENVIRONMENT"
echo "Health: http://localhost:80/health/live"
echo "Ready: http://localhost:80/health/ready"
echo "Metrics: http://localhost:80/metrics"
```

## File: ./deploy/docker-compose.production.yml

```
# FF Arena — optional container deployment (reference only).
# The actual production layout is nginx + PHP-FPM + supervisor/systemd.
# Use this compose file as a starting point for containerised deploys;
# keep .env out of the image and out of version control.

services:
  app:
    build: .
    restart: unless-stopped
    env_file: .env
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      - queue

  queue:
    build: .
    restart: unless-stopped
    command: php artisan queue:work --tries=3 --timeout=90 --backoff=30
    env_file: .env
    volumes:
      - app-storage:/var/www/html/storage

  scheduler:
    build: .
    restart: unless-stopped
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    env_file: .env
    volumes:
      - app-storage:/var/www/html/storage

  web:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "80:80"
    volumes:
      - ./deploy/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - app-storage:/var/www/html/storage:ro
    depends_on:
      - app

volumes:
  app-storage:
```

## File: ./deploy/docker-compose.tls.yml

```
# FF Arena — Final Hardening — TLS Production Compose
# Extends production compose with TLS certificates, secure headers, log aggregation

services:
  postgres:
    image: postgres:17-alpine
    container_name: ffarena-postgres-prod-tls
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_DATABASE:-ffarena}
      POSTGRES_USER: ${DB_USERNAME:-ffarena}
      POSTGRES_PASSWORD: ${DB_PASSWORD:?DB_PASSWORD must be set in .env}
    volumes:
      - postgres-data:/var/lib/postgresql/data
      - ./deploy/postgresql.conf:/etc/postgresql/postgresql.conf:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER:-ffarena} -d $${POSTGRES_DB:-ffarena}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    networks:
      - internal
    # No ports — never exposed publicly

  redis:
    image: redis:7-alpine
    container_name: ffarena-redis-prod-tls
    restart: unless-stopped
    command: >
      redis-server
      --appendonly yes
      --maxmemory 256mb
      --maxmemory-policy allkeys-lru
      --requirepass ${REDIS_PASSWORD:?REDIS_PASSWORD must be set}
      --tls-port 6380
      --port 0
      --tls-cert-file /tls/redis.crt
      --tls-key-file /tls/redis.key
      --tls-ca-cert-file /tls/ca.crt
      --save 900 1
      --save 300 10
      --save 60 10000
    environment:
      REDIS_PASSWORD: ${REDIS_PASSWORD:?REDIS_PASSWORD must be set}
    volumes:
      - redis-data:/data
      - ./deploy/redis.tls.conf:/usr/local/etc/redis/redis.conf:ro
      - ./certs:/tls:ro
    healthcheck:
      test: ["CMD", "redis-cli", "--tls", "--cacert", "/tls/ca.crt", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5
      start_period: 5s
    networks:
      - internal
    # No ports — private network only, TLS

  app:
    build:
      context: .
      dockerfile: deploy/Dockerfile
    image: ffarena:${APP_VERSION:-latest}
    container_name: ffarena-app-prod-tls
    restart: unless-stopped
    env_file: .env
    environment:
      APP_ENV: production
      APP_DEBUG: false
      APP_URL: ${APP_URL:?APP_URL must be HTTPS in production}
      TRUSTED_PROXIES: ${TRUSTED_PROXIES:-*}
      SESSION_SECURE_COOKIE: true
      SESSION_HTTP_ONLY: true
      SESSION_SAME_SITE: lax
      LOG_CHANNEL: daily
      LOG_LEVEL: warning
      LOG_DAILY_DAYS: 14
      LOG_CENTRAL_ENABLED: ${LOG_CENTRAL_ENABLED:-false}
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_SSLMODE: require
      REDIS_HOST: redis
      REDIS_PORT: 6380
      REDIS_TLS: true
      CACHE_STORE: redis
      QUEUE_CONNECTION: redis
      BROADCAST_CONNECTION: reverb
      REVERB_HOST: reverb
      REVERB_PORT: 8080
      REVERB_SCHEME: https
      REVERB_SCALING_ENABLED: true
      REVERB_SCALING_SERVER_HOST: redis
    volumes:
      - app-storage:/var/www/html/storage
      - app-cache:/var/www/html/bootstrap/cache
      - ./certs:/certs:ro
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - internal
      - external
    healthcheck:
      test: ["CMD", "curl", "-f", "-k", "https://127.0.0.1:443/health/live"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 30s
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 1G
        reservations:
          cpus: '1'
          memory: 512M

  reverb:
    image: ffarena:${APP_VERSION:-latest}
    container_name: ffarena-reverb-prod-tls
    restart: unless-stopped
    env_file: .env
    environment:
      APP_ENV: production
      BROADCAST_CONNECTION: reverb
      REVERB_SERVER_HOST: 0.0.0.0
      REVERB_SERVER_PORT: 8080
      REVERB_HOST: reverb
      REVERB_SCHEME: https
      REVERB_SCALING_ENABLED: true
      REVERB_SCALING_SERVER_HOST: redis
    command: php artisan reverb:start --host=0.0.0.0 --port=8080 --debug=false
    depends_on:
      redis:
        condition: service_healthy
    networks:
      - internal
      - external
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:8080/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  nginx:
    image: nginx:alpine
    container_name: ffarena-nginx-prod-tls
    restart: unless-stopped
    volumes:
      - ./deploy/nginx.tls.conf:/etc/nginx/nginx.conf:ro
      - ./certs:/etc/nginx/certs:ro
      - app-storage:/var/www/html/storage:ro
    depends_on:
      app:
        condition: service_healthy
    networks:
      - internal
      - external
    ports:
      - "80:80"
      - "443:443"
    healthcheck:
      test: ["CMD", "curl", "-f", "-k", "https://127.0.0.1:443/health/live"]
      interval: 30s
      timeout: 5s
      retries: 3

networks:
  internal:
    driver: bridge
    internal: true
  external:
    driver: bridge

volumes:
  postgres-data:
  redis-data:
  app-storage:
  app-cache:
```

## File: ./deploy/entrypoint.sh

```
#!/bin/bash
set -e

echo "=== FF Arena G6 Deployment Pipeline Entrypoint ==="
echo "Environment: $APP_ENV"
echo "Timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Wait for PostgreSQL
echo "Waiting for PostgreSQL..."
until pg_isready -h ${DB_HOST:-postgres} -p ${DB_PORT:-5432} -U ${DB_USERNAME:-ffarena} -d ${DB_DATABASE:-ffarena}; do
  echo "PostgreSQL not ready, waiting 2s..."
  sleep 2
done
echo "PostgreSQL ready"

# Wait for Redis
echo "Waiting for Redis..."
until redis-cli -h ${REDIS_HOST:-redis} -p ${REDIS_PORT:-6379} -a ${REDIS_PASSWORD:-} ping | grep -q PONG; do
  echo "Redis not ready, waiting 2s..."
  sleep 2
done
echo "Redis ready"

# Run migrations with rollback support
echo "Running migrations..."
php artisan migrate --force --no-interaction

# Cache config, routes, views for production
echo "Caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Clear and warm cache
echo "Warming cache..."
php artisan cache:clear --no-interaction || true

# Check health
echo "Checking health..."
php artisan redis:health --json || echo "Redis health check warning"
php artisan queue:metrics --json || echo "Queue metrics warning"

echo "=== Entrypoint complete, starting supervisord ==="
exec "$@"
```

## File: ./deploy/nginx.conf

```
# FF Arena — G6 Production nginx.conf
# Production-hardened, security headers, rate limiting, health checks, Reverb proxy

worker_processes auto;
error_log /var/log/nginx/error.log warn;
pid /var/run/nginx.pid;

events {
    worker_connections 1024;
    use epoll;
    multi_accept on;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for" '
                    'rt=$request_time uct="$upstream_connect_time" uht="$upstream_header_time" urt="$upstream_response_time"';

    access_log /var/log/nginx/access.log main;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    client_max_body_size 20M;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript;

    # Rate limiting — G4 Redis-backed via Laravel, but nginx as first line
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
    limit_req_status 429;

    # Security headers — Phase 16
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;" always;

    upstream app {
        server app:9000;
    }

    upstream reverb {
        server reverb:8080;
    }

    # Health checks — must NOT fail on Redis down for live
    server {
        listen 80;
        server_name _;
        root /var/www/html/public;
        index index.php;

        # Security: hide nginx version
        server_tokens off;

        # Health endpoints — no rate limit, no auth
        location ~ ^/health/(live|ready)$ {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /health {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /metrics {
            # Protected by Laravel auth, but allow internal
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Reverb WebSockets
        location /app {
            proxy_pass http://reverb;
            proxy_http_version 1.1;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_read_timeout 86400;
        }

        # API with rate limiting
        location /api/ {
            limit_req zone=api burst=20 nodelay;
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Auth endpoints with stricter rate limiting
        location ~ ^/(login|register|password) {
            limit_req zone=login burst=5 nodelay;
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Main app
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            fastcgi_pass app;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 90s;
            fastcgi_buffer_size 128k;
            fastcgi_buffers 4 256k;
            fastcgi_busy_buffers_size 256k;
        }

        location ~ /\.ht {
            deny all;
        }

        # Static assets with long cache
        location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
            try_files $uri =404;
        }
    }
}
```

## File: ./deploy/nginx.tls.conf

```
# FF Arena — Final Hardening — TLS / HTTPS Production nginx.conf
# Production-hardened with TLS, security headers, rate limiting, health checks, Reverb proxy
# This config is for production with TLS termination at nginx — for load balancer TLS, use nginx.conf without TLS

# Upstream for PHP-FPM
upstream app {
    server app:9000;
}

upstream reverb {
    server reverb:8080;
}

# Rate limiting zones
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
limit_req_zone $binary_remote_addr zone=webhook:10m rate=30r/m;
limit_req_status 429;

# HTTP — redirect to HTTPS in production
server {
    listen 80;
    server_name _;

    # Health checks must be reachable via HTTP for load balancer
    location ~ ^/health/(live|ready)$ {
        proxy_pass http://app;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location = /up {
        proxy_pass http://app;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Redirect all other HTTP to HTTPS
    location / {
        return 301 https://$host$request_uri;
    }
}

# HTTPS — production
server {
    listen 443 ssl http2;
    server_name ${APP_DOMAIN:-api.ffarena.com};

    # TLS certificates — paths from env, never hardcoded production domain
    ssl_certificate ${TLS_CERT_PATH:-/etc/nginx/certs/fullchain.pem};
    ssl_certificate_key ${TLS_KEY_PATH:-/etc/nginx/certs/privkey.pem};
    ssl_trusted_certificate ${TLS_CA_PATH:-/etc/nginx/certs/chain.pem};

    # TLS hardening — modern configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    ssl_session_tickets off;

    # OCSP stapling
    ssl_stapling on;
    ssl_stapling_verify on;
    resolver 1.1.1.1 8.8.8.8 valid=300s;
    resolver_timeout 5s;

    # Security headers — Final Hardening
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;
    add_header Cross-Origin-Resource-Policy "same-origin" always;
    add_header X-Request-ID $request_id always;

    # Hide nginx version
    server_tokens off;

    root /var/www/html/public;
    index index.php;

    # Logging — JSON for central aggregation
    access_log /var/log/nginx/access.log json;
    error_log /var/log/nginx/error.log warn;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript;

    client_max_body_size 20M;

    # Health endpoints — no rate limit, no auth, no secrets
    location ~ ^/health/(live|ready)$ {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /health {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /up {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /metrics {
        # Protected by Laravel auth:api, but allow internal
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Reverb WebSockets — WSS
    location /app {
        proxy_pass http://reverb;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Request-ID $request_id;
        proxy_read_timeout 86400;
        # TLS for WebSockets
        proxy_ssl_verify off; # Internal network
    }

    # API with rate limiting
    location /api/ {
        limit_req zone=api burst=20 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Auth endpoints stricter rate limiting
    location ~ ^/(login|register|password) {
        limit_req zone=login burst=5 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Webhooks with rate limiting
    location /webhooks/ {
        limit_req zone=webhook burst=30 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Main app
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 90s;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        # Forwarded proto for secure URL generation
        fastcgi_param HTTPS on;
        fastcgi_param SERVER_PORT 443;
    }

    location ~ /\.ht {
        deny all;
    }

    # Static assets with long cache, immutable
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Deny access to sensitive files
    location ~* \.(env|git|htaccess|htpasswd)$ {
        deny all;
    }
}
```

## File: ./deploy/prometheus.yml

```
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: 'ffarena-app'
    static_configs:
      - targets: ['app:80']
    metrics_path: '/metrics'
    scrape_interval: 15s
    scrape_timeout: 5s

  - job_name: 'ffarena-redis'
    static_configs:
      - targets: ['redis:9121']
    scrape_interval: 15s

  - job_name: 'ffarena-postgres'
    static_configs:
      - targets: ['postgres:9187']
    scrape_interval: 30s

  - job_name: 'ffarena-nginx'
    static_configs:
      - targets: ['nginx:9113']
    scrape_interval: 15s
```

## File: ./deploy/rollback.sh

```
#!/bin/bash
# FF Arena — G6 Rollback Script
set -euo pipefail
ENVIRONMENT="${1:-production}"
ROLLBACK_VERSION="${2:-$(cat .last_successful_version 2>/dev/null || echo 'latest')}"
echo "=== FF Arena Rollback ==="
echo "Environment: $ENVIRONMENT"
echo "Rollback to: $ROLLBACK_VERSION"
echo "Timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Confirm in production
if [[ "$ENVIRONMENT" == "production" ]]; then
  read -p "Are you sure you want to rollback production to $ROLLBACK_VERSION? (yes/no): " confirm
  if [[ "$confirm" != "yes" ]]; then echo "Rollback cancelled"; exit 0; fi
fi

# Execute rollback via deploy.sh
./deploy/deploy.sh $ENVIRONMENT $ROLLBACK_VERSION "" rollback

echo "Rollback complete"
```

## File: ./deploy/supervisor-ffarena.conf

```
; FF Arena — G6 Supervisor config
; Manages PHP-FPM, queue workers, scheduler, Reverb

[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/php-fpm.log
stderr_logfile=/var/log/supervisor/php-fpm.log
user=ffarena

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/nginx.log
stderr_logfile=/var/log/supervisor/nginx.log

[program:queue-default]
command=php /var/www/html/artisan queue:work redis --queue=critical,payments,payouts,notifications,webhooks,analytics,maintenance,default --sleep=3 --tries=3 --timeout=90 --max-time=3600 --memory=512
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/queue-default.log
stderr_logfile=/var/log/supervisor/queue-default.log
user=ffarena
numprocs=2
process_name=%(program_name)s_%(process_num)02d

[program:queue-critical]
command=php /var/www/html/artisan queue:work redis --queue=critical --sleep=1 --tries=3 --timeout=60 --max-jobs=1000 --max-time=3600
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/queue-critical.log
stderr_logfile=/var/log/supervisor/queue-critical.log
user=ffarena

[program:scheduler]
command=php /var/www/html/artisan schedule:work
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/scheduler.log
stderr_logfile=/var/log/supervisor/scheduler.log
user=ffarena

[program:reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/reverb.log
stderr_logfile=/var/log/supervisor/reverb.log
user=ffarena

[unix_http_server]
file=/var/run/supervisor.sock

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface
```

## File: ./deploy/systemd-ffarena-scheduler.service

```
# FF Arena — scheduler (run via the companion .timer once per minute).
# Copy to /etc/systemd/system/ffarena-scheduler.service

[Unit]
Description=FF Arena scheduler (php artisan schedule:run)
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/ffarena
ExecStart=/usr/bin/php artisan schedule:run
```

## File: ./deploy/systemd-ffarena-scheduler.timer

```
# FF Arena — scheduler timer (one host only!).
# Copy to /etc/systemd/system/ffarena-scheduler.timer
#   systemctl enable --now ffarena-scheduler.timer

[Unit]
Description=Run FF Arena scheduler every minute

[Timer]
OnCalendar=*:*:00
Persistent=true

[Install]
WantedBy=timers.target
```

## File: ./docker-compose.yml

```
# FF Arena — R9 Real Production Stack
# Local/staging verification with PostgreSQL 15 + Redis 7 + Go + Rust + Laravel
# Usage: docker compose up -d --build
# Health: curl http://localhost:8000/health, :8081/health, :8082/health

services:
  postgres:
    image: postgres:15-alpine
    container_name: ffarena-postgres-r9
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB:-ffarena}
      POSTGRES_USER: ${POSTGRES_USER:-ffarena}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-ffarena}
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER:-ffarena} -d ${POSTGRES_DB:-ffarena}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    networks:
      - ffarena_net
    # Do NOT expose publicly - only internal network
    # ports: intentionally not exposed for security
    deploy:
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M

  redis:
    image: redis:7-alpine
    container_name: ffarena-redis-r9
    restart: unless-stopped
    command: >
      redis-server
      --appendonly yes
      --maxmemory 256mb
      --maxmemory-policy allkeys-lru
      --requirepass ${REDIS_PASSWORD:-ffarena-redis-secret}
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "--no-auth-warning", "-a", "${REDIS_PASSWORD:-ffarena-redis-secret}", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 5s
    networks:
      - ffarena_net
    # Do NOT expose publicly - only internal network
    deploy:
      resources:
        limits:
          memory: 256M
        reservations:
          memory: 128M

  app:
    build:
      context: .
      dockerfile: deploy/Dockerfile
    container_name: ffarena-app-r9
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    environment:
      APP_ENV: ${APP_ENV:-production}
      APP_DEBUG: ${APP_DEBUG:-false}
      APP_KEY: ${APP_KEY}
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: ${POSTGRES_DB:-ffarena}
      DB_USERNAME: ${POSTGRES_USER:-ffarena}
      DB_PASSWORD: ${POSTGRES_PASSWORD:-ffarena}
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD:-ffarena-redis-secret}
      REDIS_PREFIX: ${REDIS_PREFIX:-ffarena-prod-}
      CACHE_STORE: redis
      QUEUE_CONNECTION: redis
      SESSION_DRIVER: redis
      JWT_SECRET: ${JWT_SECRET}
      WEBHOOK_SECRET: ${WEBHOOK_SECRET}
      SERVICE_HMAC_SECRET: ${SERVICE_HMAC_SECRET}
      GO_PAYMENT_URL: http://payment-gateway-go:8081
      RUST_SECURITY_URL: http://security-rust:8082
    ports:
      - "${APP_PORT:-8000}:80"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health/live"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 30s
    networks:
      - ffarena_net
    deploy:
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M

  payment-gateway-go:
    build:
      context: ./services/payment-gateway-go
      dockerfile: Dockerfile
    container_name: ffarena-payment-go-r9
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    environment:
      PORT: 8081
      APP_ENV: production
      DB_DRIVER: postgres
      DATABASE_URL: postgres://${POSTGRES_USER:-ffarena}:${POSTGRES_PASSWORD:-ffarena}@postgres:5432/${POSTGRES_DB:-ffarena}?sslmode=disable
      REDIS_URL: redis://:${REDIS_PASSWORD:-ffarena-redis-secret}@redis:6379/0
      JWT_SECRET: ${JWT_SECRET}
      WEBHOOK_SECRET: ${WEBHOOK_SECRET}
      SERVICE_ID: payment-gateway-go
      SERVICE_HMAC_SECRET: ${SERVICE_HMAC_SECRET}
      RATE_LIMIT_PER_MIN: 60
      ENABLE_METRICS: true
    ports:
      - "${GO_PAYMENT_PORT:-8081}:8081"
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:8081/health/live"]
      interval: 10s
      timeout: 3s
      retries: 3
      start_period: 10s
    networks:
      - ffarena_net
    deploy:
      resources:
        limits:
          memory: 256M
        reservations:
          memory: 128M

  security-rust:
    build:
      context: ./services/security-rust
      dockerfile: Dockerfile
    container_name: ffarena-security-rust-r9
    restart: unless-stopped
    depends_on:
      redis:
        condition: service_healthy
    environment:
      PORT: 8082
      APP_ENV: production
      REDIS_URL: redis://:${REDIS_PASSWORD:-ffarena-redis-secret}@redis:6379/0
      JWT_SECRET: ${JWT_SECRET}
      WEBHOOK_SECRET: ${WEBHOOK_SECRET}
      SERVICE_ID: security-rust
      SERVICE_HMAC_SECRET: ${SERVICE_HMAC_SECRET}
      RATE_LIMIT_PER_MIN: 60
      ENABLE_METRICS: true
    ports:
      - "${RUST_SECURITY_PORT:-8082}:8082"
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:8082/health"]
      interval: 10s
      timeout: 3s
      retries: 3
      start_period: 10s
    networks:
      - ffarena_net
    deploy:
      resources:
        limits:
          memory: 256M
        reservations:
          memory: 128M

  worker:
    build:
      context: .
      dockerfile: deploy/Dockerfile
    container_name: ffarena-worker-r9
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      app:
        condition: service_healthy
    environment:
      APP_ENV: production
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: ${POSTGRES_DB:-ffarena}
      DB_USERNAME: ${POSTGRES_USER:-ffarena}
      DB_PASSWORD: ${POSTGRES_PASSWORD:-ffarena}
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD:-ffarena-redis-secret}
      QUEUE_CONNECTION: redis
    command: ["php", "artisan", "queue:work", "--tries=3", "--timeout=90"]
    networks:
      - ffarena_net
    deploy:
      resources:
        limits:
          memory: 256M

networks:
  ffarena_net:
    driver: bridge
    name: ffarena_net_r9

volumes:
  postgres-data:
    driver: local
    name: ffarena_postgres_data_r9
  redis_data:
    driver: local
    name: ffarena_redis_data_r9
```

