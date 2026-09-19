# FF Arena Microservices - Payment Gateway (Go) + Security (Rust)

## Overview

This directory contains two new microservices that extend the existing Laravel FF Arena without rewriting working business logic (G1 rule preserved).

- **payment-gateway-go**: Go 1.22 payment gateway with bKash, Nagad, Rocket, Manual providers
- **security-rust**: Rust Actix-web 4 security service with device/IP/identity/external intelligence

Both are **G1 safe**: Laravel adapters fallback to PHP if Go/Rust unavailable, feature flags disabled by default, no hardcoded secrets, no public DB.

## Services

### Go Payment Gateway (8081)

- Providers: manual (BDT,USD), bkash (BDT), nagad (BDT), rocket (BDT)
- Contracts: PaymentProvider interface key/label/supportsCurrency/supportsRefund/create/query/verify/callback/refund/capabilities/metadata
- Security: Bearer auth, HMAC SHA256 webhook verify, Idempotency-Key, Rate limit 60/min, Security headers, Audit logs
- Financial: Wallet locking via sync.RWMutex, immutable ledger balance_after, idempotency map, never mark completed without confirmation
- Observability: MetricsInterface, structured logs, X-Request-ID, health probes
- Storage: MemoryStore thread-safe, future S3

See `payment-gateway-go/README.md` and `openapi.yaml` for full API.

### Rust Security Service (8082)

- Providers: device (deviceLabelFromUA, missing hash +10), ip (SHA256 hash_ip, subnet_hash, private_ip), external (honest low 0), identity (verified check 0/20)
- Contracts: FraudProvider trait key/supports/evaluate
- Risk scoring: overall sum, critical >=100, high >=70, medium >=30, low <30
- Security: Bearer auth, Rate limit 60/min, Security headers, Request ID, Audit
- Observability: Metrics trait, structured logs, health probes

See `security-rust/README.md` for full API.

## Integration with Laravel

Laravel adapters preserve existing logic:

- `App\Services\GoPaymentGatewayAdapter` - HTTP client to Go service, fallback to PHP ManualProvider
- `App\Services\RustFraudServiceAdapter` - HTTP client to Rust service, fallback to PHP providers
- `App\Payments\Providers\GoPaymentProvider` - implements PaymentProviderInterface via Go adapter
- `App\Fraud\Providers\RustFraudProvider` - implements FraudProviderInterface via Rust adapter
- `config/services_go_rust.php` - env-based URLs, secrets, enabled flags (disabled by default)
- `app/Payments/PaymentProviderManager.php` - registers Go adapters when GO_PAYMENT_ENABLED=true
- `app/Fraud/FraudProviderManager.php` - registers Rust adapters when RUST_SECURITY_ENABLED=true

Config safety: no hardcoded domains/secrets, all env-based, redact in logs.

## Running

```bash
# Go
cd services/payment-gateway-go
go mod download
PORT=8081 go run ./cmd/server

# Rust
cd services/security-rust
cargo run

# Both via Docker
cd services
docker-compose up --build

# Laravel still works without Go/Rust (fallback)
cd ../
php artisan serve
```

## Testing

```bash
# Go
cd services/payment-gateway-go
go test ./... -v

# Rust
cd services/security-rust
cargo test

# Laravel (existing 783 tests)
cd ../
php vendor/bin/phpunit
```

## OpenAPI

- Laravel: `docs/openapi.yaml` 872 lines 51 paths
- Go: `services/payment-gateway-go/openapi.yaml` 51 paths matching Laravel
- Rust: OpenAPI via code, documented in README

## Feature Flags

- `GO_PAYMENT_ENABLED=false` default, enable via env
- `RUST_SECURITY_ENABLED=false` default
- `FEATURE_PAYMENT_BKASH=true`, `FEATURE_PAYMENT_NAGAD=false`, etc in `config/features.php` 30 flags
- `EnsureFeatureEnabled` middleware `feature:xxx` returns 404 if disabled

## Security Preservation

- CSRF preserved except webhooks
- Bearer auth Sanctum + Go/Rust Bearer
- HMAC SHA256 webhook verification
- Idempotency via Idempotency-Key header
- Rate limiting 60/min with Retry-After
- Wallet locking via transaction/mutex
- Immutable ledger balance_after
- Secret redaction via RedactSensitiveDataProcessor
- No flag disables security: test_feature_flags_do_not_disable_security

## Financial Reconcile

- Wallet balance_minor = sum LedgerEntry amount_minor
- No duplicate credit via idempotency_key unique
- Never mark completed without confirmation: pending only via callback
- Refund honest: manual refunded, rocket refund_not_supported

## Observability

- Metrics: increment/gauge/timing vendor-neutral (NullMetrics, InMemoryMetrics, PrometheusMetrics)
- Structured logs: JSON with request_id, method, path, duration_ms
- Tracing: X-Request-ID UUID
- Audit: AuditLog model + Go/Rust audit logs
- Health: /health, /health/live, /health/ready
- Error reporting: ErrorReporterInterface never breaks pipeline

## Backward Compatibility

- Go/Rust disabled by default, Laravel PHP providers still work
- Additive: new providers via manager.register, no breaking existing
- API v1 stable, v2 strategy documented in docs/API_V2_STRATEGY.md
- DB additive migrations nullable-first, safe indexes

## Files Created

- services/payment-gateway-go/ - Go service full code
- services/security-rust/ - Rust service full code
- services/docker-compose.yml
- services/README.md
- app/Services/GoPaymentGatewayAdapter.php
- app/Services/RustFraudServiceAdapter.php
- app/Payments/Providers/GoPaymentProvider.php
- app/Fraud/Providers/RustFraudProvider.php
- config/services_go_rust.php
- docs/PAYMENT_GATEWAY_GO_RUST.md (this file plus detailed docs)

## Production Ready

- Dockerfiles for both services
- Health checks
- Graceful fallback
- No hardcoded secrets
- Tests
- OpenAPI docs
- Preserves existing Laravel logic
