# Mobile Privacy & Telemetry (Phase 19)

This document describes exactly what the FF Arena mobile app collects, sends
and stores, and what it never does.

## 1. What the app NEVER sends

The following are never sent to any telemetry, analytics, crash or logging
system:

* passwords, OTP codes, access/refresh tokens;
* raw IP addresses or device fingerprints;
* risk scores or anti-fraud signals;
* private message / support / dispute content;
* wallet balances, ledger details or payment credentials.

## 2. Telemetry allow-list

`mobile/lib/core/telemetry/product_metrics.dart` records only:

| Field | Notes |
| --- | --- |
| `app_version` | from build |
| `platform` | OS family |
| `event` | a fixed event name |
| `category` | optional category |
| `duration_ms` | optional timing |

Allowed events: `app_open`, `screen_view`, `api_error_category`,
`performance timing`, `notification_open`, `update_required`. The sink is
opt-in: nothing is transmitted unless a sink is attached, and the default
build attaches none. Telemetry is never required for core operation.

## 3. Crash reporting

`mobile/lib/core/telemetry/crash_reporter.dart` defines
`CrashReporter`. The default is a no-op; a debug logger exists for
development. Any production adapter (Sentry/Crashlytics) must be injected at
build time and must receive only sanitized, categorized facts — the
interface never receives credentials, tokens, OTPs, raw IPs or device
fingerprints.

## 4. API diagnostics

Error diagnostics capture only: API error code, HTTP status, correlation /
request id, app version and OS/platform. Never the bearer token, response
secrets or private financial data.

## 5. Storage

* Access tokens: platform keystore/Keychain via `flutter_secure_storage`
  (never logs, never plaintext files).
* Offline cache: last successful GET responses in the app documents
  directory, read-only, never used to authorize a mutation.
* Push tokens: server-side encrypted-at-rest only; never on device beyond
  the OS provider.

## 6. Push privacy

* Push bodies for sensitive categories (payment/payout/dispute/security) are
  redacted server-side.
* Notifications never reveal amounts, balances, OTPs or risk reasoning.

## 7. Consent

* Push permission is requested at an appropriate UX point; denial never
  blocks features.
* If optional telemetry is ever enabled, it is exposed as a privacy setting
  and defaulted per product policy.
