# Security Services - Rust Implementation

## Overview

Rust security service provides anti-fraud intelligence with device, IP, identity, external providers, risk scoring, rate limiting, audit, observability.

## Providers

### Device Intelligence

- Key: device
- Supports: device
- Logic: device_label_from_ua checks user agent lowercase contains iphone->iPhone, android->Android Device, windows->Windows PC, mac->Mac, else Desktop, Unknown Device if no UA
- Risk: missing_device_hash +10, unknown_device +5, low <30, medium <70, high >=70
- Signals: missing_device_hash, unknown_device, device label
- Model: DeviceInfo device_hash, label, user_agent, first_seen, last_seen

### IP Intelligence

- Key: ip
- Supports: ip
- Logic: hash_ip SHA256 hex, subnet_hash first 3 octets .0, private_ip detection 10./192.168.
- Risk: missing_ip +15, private_ip signal, low<30 medium<70 high>=70
- Signals: ip_observed:8chars, subnet:xxx, private_ip, missing_ip
- Model: IpInfo ip_hash, subnet_hash, observation_count, suspicious_count

### External Intelligence

- Key: external
- Supports: external, third_party
- Logic: honest low risk 0, signals empty, env configurable endpoint for future external API, no hardcoded secret, no fake high risk
- Risk: 0 low

### Identity Intelligence

- Key: identity
- Supports: identity, kyc
- Logic: checks context verified bool, if verified true score 0 has_verification true signals verified, else score 20 has_verification false signals no_verification
- Risk: low<30 medium<70 high>=70
- Model: checks IdentityVerification model verified in Laravel, UserIdentity

## Manager

FraudProviderManager with HashMap<String, Arc<dyn FraudProvider>>, new registers device, ip, external, identity, get, all, keys, register.

## Risk Scoring

Overall score = sum provider risk_score
- critical >=100
- high >=70
- medium >=30
- low <30

Overall level = max level or based on overall_score.

## API

- POST /api/v1/security/evaluate - all providers
- POST /api/v1/security/device - device only
- POST /api/v1/security/ip - IP only
- POST /api/v1/security/identity - identity only
- GET /api/v1/security/providers - list keys
- POST /api/v1/security/risk-score - risk score
- GET /health - health

## Security

- Bearer auth except health
- Rate limiting 60/min per IP, 429 TooManyRequests with Retry-After
- Security headers nosniff SAMEORIGIN strict-origin-when-cross-origin
- Request ID X-Request-ID UUID
- HMAC webhook verification SHA256
- Audit logs
- No secrets in logs

## Observability

- Metrics trait increment/gauge/timing
- InMemoryMetrics with Mutex counters
- RequestContext snapshot request_id timestamp
- Structured logs with request_id
- Health probes /health, /health/live, /health/ready

## Integration with Laravel

RustFraudServiceAdapter with baseUrl from config services_go_rust.rust_security.url env RUST_SECURITY_URL localhost:8082, isAvailable GET /health timeout 2s, evaluate, evaluateDevice, evaluateIp, evaluateIdentity, riskScore, listProviders with fallback PHP logic risk_score 0 low.

RustFraudProvider implements FraudProviderInterface key rust_{type}, supports type, evaluate delegates to adapter with fallback.

FraudProviderManager registers Rust adapters when RUST_SECURITY_ENABLED=true.

## Testing

- Unit tests for device label, IP hash, identity verified, external low risk
- Integration tests for evaluate endpoint, rate limiting, security headers
- Laravel 783 tests still PASS with fallback

## Docker

Dockerfile Rust:1.78 builder cargo build --release, debian:bookworm-slim runtime ca-certificates, EXPOSE 8082, ENV PORT=8082.

docker-compose.yml with payment-gateway-go 8081 and security-rust 8082, network ffarena, healthcheck wget /health, restart unless-stopped.

## Production Ready

- No hardcoded secrets
- Env-based config
- Thread-safe Arc Mutex
- Honest low risk when no data
- Never fabricate high risk
- Fallback to PHP when unavailable
- 783 tests PASS
