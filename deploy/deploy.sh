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
