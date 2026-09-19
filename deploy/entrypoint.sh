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
