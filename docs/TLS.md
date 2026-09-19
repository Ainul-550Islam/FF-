# FF Arena — TLS / HTTPS Hardening (Final Hardening)

## Overview

Production HTTPS hardening — trusted proxies, forwarded protocol handling, secure URL generation, HTTPS redirects, secure cookies, HttpOnly, SameSite, HSTS, TLS-aware callback URLs, secure OAuth redirect URLs, secure payment callback URLs, secure webhook URLs.

## Configuration

### Trusted Proxies

Via `TRUSTED_PROXIES` env — comma-separated IPs or * for all (when behind load balancer):

```
TRUSTED_PROXIES=*
# Or specific IPs:
TRUSTED_PROXIES=10.0.0.1,10.0.0.2
```

In production behind load balancer, must set to load balancer IPs or *.

Implementation: `app/Http/Middleware/TrustProxies.php` — reads `TRUSTED_PROXIES` env, sets `$proxies` to * or array, headers include X_FORWARDED_FOR, HOST, PORT, PROTO, AWS_ELB.

### Secure URL Generation

Laravel's `URL::forceScheme('https')` when request is secure or `APP_URL` is https in production.

In `AppServiceProvider` boot:

```php
if (app()->environment('production') || request()->isSecure() || str_starts_with(config('app.url'), 'https://')) {
    URL::forceScheme('https');
}
```

### HTTPS Redirects

- In nginx.tls.conf: HTTP server block redirects all to HTTPS except health checks for load balancer
- In Laravel: `TrustProxies` handles forwarded proto, so `request()->isSecure()` returns true when behind TLS-terminating load balancer
- Production must be configurable via env — never hardcode domain, `APP_URL` env

### Secure Cookies

- `SESSION_SECURE_COOKIE=true` in production — only sent over HTTPS
- `SESSION_HTTP_ONLY=true` — prevent JS access
- `SESSION_SAME_SITE=lax` or `strict` — mitigate CSRF
- `SESSION_DOMAIN=.ffarena.com` — correct domain
- `SESSION_PATH=/` — correct path
- Session rotation on login: `Session::regenerate()` after login
- Session invalidation on logout: `Session::invalidate()` + `regenerateToken()`
- Password reset invalidation: all sessions invalidated on password reset
- Token/session revocation: Sanctum tokens revoked on logout

Do not break API bearer-token authentication — bearer tokens are stateless, session cookies for web.

### HSTS

`Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` — only in production HTTPS, via SecurityHeaders middleware and nginx.tls.conf.

### TLS-aware Callback URLs

- OAuth redirect URLs: `GOOGLE_REDIRECT_URI` must be HTTPS in production
- Payment callback URLs: `PAYMENT_CALLBACK_URL` HTTPS in production
- Webhook URLs: `WEBHOOK_URL` HTTPS in production
- Mobile web base URL: `MOBILE_WEB_BASE_URL` HTTPS in production

Validated in `ProductionConfigValidator`.

### Secure OAuth Redirect URLs

Google OAuth: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` HTTPS in production.

### Secure Payment Callback URLs

bKash, Nagad, etc.: callback URL HTTPS, certificate validation enabled, hostname validation, redirect safety (no open redirects).

### Secure Webhook URLs

Webhook inbound: signature verification HMAC, timestamp validation 5 min, replay prevention nonce, idempotency key.

### Production Configurable

All via env, never hardcoded production domain:

- `APP_URL` — app URL, must be HTTPS in production
- `TRUSTED_PROXIES` — trusted proxies
- `SESSION_SECURE_COOKIE` — secure cookies
- `PAYMENT_CALLBACK_URL` — payment callback
- `WEBHOOK_URL` — webhook URL
- `MOBILE_WEB_BASE_URL` — mobile web base
- `GOOGLE_REDIRECT_URI` — OAuth redirect

### Local / Testing

Do not force HTTPS in local/testing unless explicitly configured — `TrustProxies` returns null in non-production if `TRUSTED_PROXIES` not set, `SecurityHeaders` only adds HSTS if production and secure, `ProductionConfigValidator` relaxes checks in non-production.

## Verification

```bash
php artisan config:validate-production
# Checks APP_URL HTTPS in production, SESSION_SECURE_COOKIE, OAuth redirect HTTPS, payment callback HTTPS, webhook HTTPS, MOBILE_WEB_BASE_URL HTTPS
```

## Nginx TLS

`deploy/nginx.tls.conf`:

- Listens 443 ssl http2
- Certificates from `TLS_CERT_PATH`, `TLS_KEY_PATH`, `TLS_CA_PATH` env — never hardcoded
- Protocols TLSv1.2 TLSv1.3, ciphers modern, prefer_server_ciphers off, session cache shared:SSL:10m, tickets off
- OCSP stapling on, resolver 1.1.1.1 8.8.8.8
- HSTS header
- HTTP block redirects to HTTPS except health checks

`deploy/docker-compose.tls.yml`:

- Extends production compose with TLS certs volume `./certs:/etc/nginx/certs:ro`
- Redis TLS: `--tls-port 6380 --port 0 --tls-cert-file --tls-key-file --tls-ca-cert-file`, healthcheck with `--tls --cacert`
- App env `APP_URL` must be HTTPS, `TRUSTED_PROXIES=*`, `SESSION_SECURE_COOKIE=true`, `DB_SSLMODE=require`, `REDIS_TLS=true`, `REVERB_SCHEME=https`
- Nginx ports 80 443

## Mobile TLS

- Flutter `Env.validate()` aborts if production and API base not HTTPS
- Android `usesCleartextTraffic="false"` in manifest, network_security_config.xml cleartextTrafficPermitted false
- iOS ATS `NSAllowsArbitraryLoads=false`

## Payment / Webhook TLS

Every production payment provider callback and webhook endpoint uses HTTPS — certificate validation enabled, hostname validation, redirect safety, callback URL HTTPS, signature verification, replay prevention, timestamp validation, idempotency.

Never downgrade to HTTP.
