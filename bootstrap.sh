#!/usr/bin/env bash
# FF Arena — G1 sandbox bootstrap (idempotent).
# Reinstalls PHP + PostgreSQL tooling and (re)creates the local PostgreSQL
# cluster + role/databases. Safe to re-run after a sandbox reset: binaries and
# the cluster's base/ directory are wiped between turns, while files under
# /home/user (this script, pgdata config, the app) persist.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
PGDATA="${PGDATA:-/home/user/pgdata}"
PGBIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | head -1 || true)"
PGPORT="${PGPORT:-5432}"

# --- 1. PHP + PostgreSQL packages (if the php binary is missing) ----------
if ! command -v php >/dev/null 2>&1; then
  echo "[bootstrap] installing PHP + PostgreSQL packages…"
  sudo apt-get update -qq
  sudo apt-get install -y -qq \
    php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-sqlite3 \
    php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd php8.4-pgsql php8.4-mysql \
    php8.4-pcov \
    php-cli postgresql-17 postgresql-client-17 >/dev/null
fi

# --- 1b. pcov INI (the .deb ships an .ini; ensure it is enabled) ---------
if [ -f /etc/php/8.4/mods-available/pcov.ini ] && [ ! -f /etc/php/8.4/cli/conf.d/20-pcov.ini ]; then
  sudo ln -sf /etc/php/8.4/mods-available/pcov.ini /etc/php/8.4/cli/conf.d/20-pcov.ini
fi

# --- 2. (Re)initialize the cluster if the base/ directory is missing ------
if [ ! -d "$PGDATA/base" ]; then
  echo "[bootstrap] (re)initializing PostgreSQL cluster at $PGDATA (UTF-8)…"
  rm -f "$PGDATA/postmaster.pid"
  rm -rf "$PGDATA"
  mkdir -p "$PGDATA"
  chmod 700 "$PGDATA"
  PGBIN="$(ls -d /usr/lib/postgresql/*/bin | head -1)"
  "$PGBIN/initdb" -D "$PGDATA" --auth=trust --username=postgres \
    --encoding=UTF8 --locale=C.UTF-8 >/dev/null
fi

# --- 3. Start the cluster ------------------------------------------------
if ! pg_isready -h 127.0.0.1 -p "$PGPORT" >/dev/null 2>&1; then
  echo "[bootstrap] starting PostgreSQL on 127.0.0.1:$PGPORT …"
  rm -f "$PGDATA/postmaster.pid"
  mkdir -p /tmp/pgsock && chmod 777 /tmp/pgsock
  PGBIN="$(ls -d /usr/lib/postgresql/*/bin | head -1)"
  "$PGBIN/pg_ctl" -D "$PGDATA" -l /home/user/pg.log \
    -o "-p $PGPORT -c listen_addresses=127.0.0.1 -c unix_socket_directories=/tmp/pgsock" start >/dev/null
  sleep 2
fi

# --- 4. Role + databases -------------------------------------------------
if ! psql -h 127.0.0.1 -U postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname='ffarena'" | grep -q 1; then
  echo "[bootstrap] creating role ffarena…"
  psql -h 127.0.0.1 -U postgres -c "CREATE ROLE ffarena LOGIN CREATEDB PASSWORD 'ffarena';" >/dev/null
fi
for db in ffarena ffarena_test; do
  if ! psql -h 127.0.0.1 -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1; then
    echo "[bootstrap] creating database $db…"
    psql -h 127.0.0.1 -U postgres -c "CREATE DATABASE $db OWNER ffarena;" >/dev/null
  fi
done

# --- 5. Composer (save the phar into the workspace so it persists) --------
if ! command -v composer >/dev/null 2>&1 && [ ! -f /home/user/composer.phar ]; then
  echo "[bootstrap] fetching composer.phar…"
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --quiet --install-dir=/home/user --filename=composer.phar
  rm -f /tmp/composer-setup.php
fi

# --- 6. k6 load-testing binary (workspace-local so it persists) -----------
if ! command -v k6 >/dev/null 2>&1 && [ ! -f /home/user/bin/k6 ]; then
  echo "[bootstrap] fetching k6 binary…"
  mkdir -p /home/user/bin
  K6_VER="v0.57.0"
  curl -sSL "https://github.com/grafana/k6/releases/download/${K6_VER}/k6-${K6_VER}-linux-amd64.tar.gz" \
    -o /tmp/k6.tgz
  tar -xzf /tmp/k6.tgz -C /tmp
  mv "/tmp/k6-${K6_VER}-linux-amd64/k6" /home/user/bin/k6
  chmod +x /home/user/bin/k6
  rm -rf /tmp/k6.tgz "/tmp/k6-${K6_VER}-linux-amd64"
fi
if [ -x /home/user/bin/k6 ] && ! command -v k6 >/dev/null 2>&1; then
  sudo ln -sf /home/user/bin/k6 /usr/local/bin/k6
fi

echo "[bootstrap] done — php $(php -r 'echo PHP_VERSION;'), PostgreSQL $("$PGBIN/postgres" --version 2>/dev/null | awk '{print $3}')"
pg_isready -h 127.0.0.1 -p "$PGPORT"
