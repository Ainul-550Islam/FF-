# Mobile App — Security Model (Phase 18)

The mobile app is a **consumer** of the FF Arena platform. This document
records the security boundaries the client enforces on its side of the
trust line. The server remains authoritative for everything that matters.

## 1. Trust model

- The backend is authoritative for **rank, points, wallet balance, payment
  success, verification, team ownership, tournament state and restriction
  state**. The client never duplicates ranking formulas, never marks a
  payment successful locally, and never computes balances.
- All communication goes through `/api/v1` only. The client never touches the
  database, never calls internal services directly and never scrapes Blade
  pages.

## 2. Access tokens

- The access token lives **only** in the platform keystore/keychain
  (`flutter_secure_storage`) and in memory for the process lifetime. It is
  never written to plaintext files, SharedPreferences, logs or analytics.
- `AuthSession.toJson()` deliberately omits the token.
- The client uses the existing documented token lifecycle. It does **not**
  invent a refresh flow.
- A `token_expired`, `token_revoked`, `account_inactive` or `unauthenticated`
  response from ANY request clears auth state and routes the user to the
  security/account screen (`SessionManager` + `ApiClient.onSessionTerminated`).

## 3. No secret logging

- The ApiClient logs at most `METHOD status path elapsed_ms` — never headers
  or bodies.
- Telemetry (`ProductMetrics`) enforces a field allow-list
  (`app_version`, `platform`, `event`, `category`, `duration_ms`).
  Passwords, OTP codes, tokens, raw IPs, risk scores and device fingerprints
  are dropped by construction.
- The crash reporter only ever receives a redacted context label and error
  category, never payloads.

## 4. Push tokens

- The raw push token is sent exactly once to `POST /api/v1/me/devices`; the
  server stores only its **sha256** hash and never echoes token material
  back (the client also never logs it).
- Device registrations are owner-only (server-enforced policy, mirrored in
  `DeviceRepository`). The client can only list/delete its own devices.
- When no provider is configured, push is disabled honestly — no fake
  delivery, no placeholder credentials.

## 5. Payments

- The client posts `{team_id, provider}` and follows the server's
  `redirect_url` only when one is provided. Completion is determined by
  polling `GET /payments/{id}` until the **server** reports a terminal
  status — never by the redirect alone.
- Provider selection uses the server's own `/payments/methods` statuses
  (`enabled && configured`); the client never assumes a provider exists.
- Provider/settlement internals are never exposed.

## 6. Deep links

- `ffarena://tournament/{id}`, `ffarena://match/{id}`,
  `ffarena://profile/{id}`.
- Every target requires an authenticated, authorized server response before
  rendering.
- The parser strips query/fragment, so an attacker cannot smuggle secrets,
  room passwords, payment secrets or tokens into a link.

## 7. Local storage

- Secure storage: access token + session envelope only.
- Offline cache: read-only copies of last successful GET responses, marked
  stale and never treated as authoritative.
- Nothing else is persisted client-side.

## 8. Certificate pinning

Certificate pinning is intentionally **not** implemented: there is no
operational key-rotation strategy to go with it, and pinning without
rotation causes outages and lockouts. Standard platform TLS validation is
used.

## 9. Admin surface

Administration remains web-first. The mobile app contains no admin
sub-app and the API exposes no admin capabilities to mobile scopes.
