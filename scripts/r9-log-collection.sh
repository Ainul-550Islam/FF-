#!/bin/bash
# FF Arena — R9 Log Collection with Redaction
# Collects Laravel, PostgreSQL, Redis, Go, Rust, workers logs and redacts secrets

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

LOG_DIR="storage/logs/r9-collection-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$LOG_DIR"

echo "=== R9 Log Collection ==="
echo "Date: $(date -u)"
echo "Log dir: $LOG_DIR"
echo ""

# Function to redact secrets
redact_file() {
    local file=$1
    local output=$2
    if [ ! -f "$file" ]; then
        echo "  File not found: $file"
        return
    fi
    # Redact passwords, tokens, secrets, JWT, private keys, auth headers, payment credentials
    sed -E \
        -e 's/(password[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(secret[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(token[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(jwt[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(api_key[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(private_key[[:space:]]*[:=][[:space:]]*)[^[:space:],}"]+/\1***REDACTED***/gi' \
        -e 's/(authorization[[:space:]]*:[[:space:]]*)Bearer[[:space:]]+[^[:space:]]+/\1***REDACTED***/gi' \
        -e 's/(X-Signature[[:space:]]*:[[:space:]]*)[^[:space:]]+/\1***REDACTED***/gi' \
        -e 's/(DATABASE_URL[[:space:]]*=[[:space:]]*)[^[:space:]]+/\1***REDACTED***/gi' \
        -e 's/(REDIS_URL[[:space:]]*=[[:space:]]*)[^[:space:]]+/\1***REDACTED***/gi' \
        "$file" > "$output"
    echo "  Redacted: $file -> $output"
}

echo "1. Collecting Laravel logs..."
if [ -d storage/logs ]; then
    for logfile in storage/logs/*.log; do
        if [ -f "$logfile" ]; then
            base=$(basename "$logfile")
            redact_file "$logfile" "$LOG_DIR/laravel-$base"
        fi
    done
    echo "  Laravel logs collected"
else
    echo "  No Laravel logs found"
fi

echo ""
echo "2. Collecting PostgreSQL logs..."
if [ -f pg.log ]; then
    redact_file "pg.log" "$LOG_DIR/postgres.log"
elif [ -d pgdata/log ]; then
    for logfile in pgdata/log/*.log; do
        if [ -f "$logfile" ]; then
            base=$(basename "$logfile")
            redact_file "$logfile" "$LOG_DIR/postgres-$base"
        fi
    done
else
    echo "  No PostgreSQL logs found — BLOCKED BY ENVIRONMENT or not running"
fi

echo ""
echo "3. Collecting Redis logs..."
# Redis logs typically via docker logs
if command -v docker &> /dev/null; then
    if docker ps | grep -q redis; then
        docker logs ffarena-redis-r9 > "$LOG_DIR/redis.raw.log" 2>&1 || docker logs ffarena-redis-services-r9 > "$LOG_DIR/redis.raw.log" 2>&1 || echo "  No redis container logs"
        if [ -f "$LOG_DIR/redis.raw.log" ]; then
            redact_file "$LOG_DIR/redis.raw.log" "$LOG_DIR/redis.log"
            rm "$LOG_DIR/redis.raw.log"
        fi
    else
        echo "  No Redis container running — BLOCKED BY ENVIRONMENT"
    fi
else
    echo "  Docker not available — BLOCKED BY ENVIRONMENT"
fi

echo ""
echo "4. Collecting Go payment service logs..."
if command -v docker &> /dev/null; then
    if docker ps | grep -q payment; then
        docker logs ffarena-payment-go-r9 > "$LOG_DIR/go-payment.raw.log" 2>&1 || docker logs ffarena-payment-go-services-r9 > "$LOG_DIR/go-payment.raw.log" 2>&1 || echo "  No Go container logs"
        if [ -f "$LOG_DIR/go-payment.raw.log" ]; then
            redact_file "$LOG_DIR/go-payment.raw.log" "$LOG_DIR/go-payment.log"
            rm "$LOG_DIR/go-payment.raw.log"
        fi
    else
        echo "  No Go container running — BLOCKED BY ENVIRONMENT"
    fi
else
    echo "  Docker not available — BLOCKED BY ENVIRONMENT"
fi

echo ""
echo "5. Collecting Rust security service logs..."
if command -v docker &> /dev/null; then
    if docker ps | grep -q security; then
        docker logs ffarena-security-rust-r9 > "$LOG_DIR/rust-security.raw.log" 2>&1 || docker logs ffarena-security-rust-services-r9 > "$LOG_DIR/rust-security.raw.log" 2>&1 || echo "  No Rust container logs"
        if [ -f "$LOG_DIR/rust-security.raw.log" ]; then
            redact_file "$LOG_DIR/rust-security.raw.log" "$LOG_DIR/rust-security.log"
            rm "$LOG_DIR/rust-security.raw.log"
        fi
    else
        echo "  No Rust container running — BLOCKED BY ENVIRONMENT"
    fi
else
    echo "  Docker not available — BLOCKED BY ENVIRONMENT"
fi

echo ""
echo "6. Collecting worker logs..."
if [ -d storage/logs ]; then
    if ls storage/logs/*worker*.log &> /dev/null; then
        for logfile in storage/logs/*worker*.log; do
            base=$(basename "$logfile")
            redact_file "$logfile" "$LOG_DIR/worker-$base"
        done
    else
        echo "  No worker logs found"
    fi
fi

echo ""
echo "7. Verifying redaction..."
echo "  Checking for unredacted secrets..."
if grep -r "password.*=" "$LOG_DIR" | grep -v "REDACTED" | grep -v "PLACEHOLDER" | head -5; then
    echo "  WARNING: Potential unredacted secrets found!"
else
    echo "  Redaction verified - no plaintext secrets"
fi

echo ""
echo "8. Log collection summary..."
ls -lh "$LOG_DIR"/
echo ""
echo "Total log files: $(ls -1 $LOG_DIR | wc -l)"
echo "Total size: $(du -sh $LOG_DIR | cut -f1)"

echo ""
echo "=== Log Collection Complete ==="
echo "Logs stored in: $LOG_DIR"
echo "All secrets redacted: ***REDACTED***"
