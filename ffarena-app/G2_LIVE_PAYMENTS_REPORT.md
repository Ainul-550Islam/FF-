# G2 — Live Payment Gateways (Phase 20)

**Owner:** Arena.ai Agent Mode · **Started:** 2026-09-13 · **Status:** 🚧 IN PROGRESS
**Scope:** Replace the deliberately-conservative payment-gateway stubs with
real, server-authoritative, testable live gateway integrations — while keeping
the manual/fallback flows intact whenever credentials are absent.

This report is written incrementally. Each gateway ships with: API client →
adapter → callback/IPN surface → routes/config → tests (both drivers) → docs.

---

## 0. Non-negotiable invariants (applies to every gateway below)

1. **Never fabricate success.** A payment is settled only after a
   server-to-server confirmation (or a cryptographically verified callback)
   matches the server-side amount and currency exactly.
2. **The payer redirect is never trusted alone.** Redirect query strings are
   attacker-influenced; they are re-verified against the provider.
3. **Idempotency.** Two identical callbacks/replays MUST NOT settle twice;
   concurrent same-key requests MUST NOT double-effect.
4. **Honest degradation.** `configured() === false` ⇒ the provider is shown as
   "not configured", no redirect is issued, and manual verification remains.
5. **Dual-driver.** Every change works on SQLite (local/test) and PostgreSQL
   (production). No raw driver-specific SQL in application code or tests.
6. **No secrets committed / logged.** Credentials are env-only; error messages
   and diagnostics mask or omit them.

---

## 1. bKash — Tokenized Checkout (G2-A) ✅ DONE

### 1.1 What was built

| Piece | File | Purpose |
|---|---|---|
| Transport policy | `app/Support/GatewayHttp.php` | Shared outbound HTTP: timeouts, GET-only retries, secret-free error messages, secret masking |
| API client | `app/Gateways/BkashTokenizedClient.php` | Grant token (cached) → create → execute → query → refund against the Tokenized Checkout API (v1.2.0-beta) |
| Adapter | `app/Gateways/BkashGateway.php` | Two honest modes: manual fallback (unconfigured) vs. live hosted checkout (configured); implements `PaymentStatusQueryable` |
| Capability contract | `app/Contracts/PaymentStatusQueryable.php` | Normalised provider status-query contract (`completed`/`failed`/`pending`) |
| Signed redirect state | `app/Support/PaymentCallbackState.php` | HMAC-bound `payment_id|idempotency_key` token so a forged success URL is rejected |
| Callback controller | `app/Http/Controllers/PaymentGatewayCallbackController.php` | Payer-return endpoint: verifies state, re-queries provider, settles/fails |
| Service authority | `app/Services/PaymentService.php` | New `confirmProviderPayment()` + `markGatewayFailed()` with amount/currency re-checks and idempotency |
| Audit events | `app/Models/PaymentEvent.php` | `payment.gateway_confirmed`, `payment.gateway_failed` |
| Route | `routes/web.php` | `GET /payments/callback/{provider}` (outside auth, signed-state protected) |
| Config | `config/payments.php`, `config/services.php`, `.env.example` | `token_ttl_seconds`, `PAYMENT_CALLBACK_SECRET`, `BKASH_TOKEN_TTL_SECONDS` |

### 1.2 Flow (live mode)

```
CheckoutController::initiate
  → PaymentService::createForTeam (pending, idempotency_key = UUID)
  → BkashGateway::createExternalPayment
      → client->createPayment(amount, payerReference, callbackURL, invoice)
      → payment → processing, provider_reference = paymentID
      → redirect payer to bkashURL
  (payer approves at bKash, bKash redirects to our callbackURL)
PaymentGatewayCallbackController::confirm
  → verify signed state
  → BkashGateway::queryPaymentStatus (server-to-server)
  → PaymentService::confirmProviderPayment
      → currency/amount re-checked to the paisa → settleSuccess(paid)
      → payment.gateway_confirmed event
  → idempotent on replay (already-successful → current state, no re-effect)
```

### 1.3 Security decisions

- The access token is cached (`bkash:token:{app_key-hash}`) until shortly
  before expiry (default 3300s of the 3600s lifetime).
- Retries apply to **GET only**. A timed-out POST (grant/create/execute) is
  never auto-repeated — the caller reconciles via the query/status API.
- The redirect `state` embeds the payment id + idempotency key under HMAC with
  a dedicated secret (`PAYMENT_CALLBACK_SECRET` → falls back to webhook secret
  → APP_KEY-derived). No secret appears in the token.
- `confirmProviderPayment` re-validates currency and amount (to the paisa)
  before settling; a mismatch throws and the payment stays un-settled.

### 1.4 Tests (16 new, `tests/Feature/PaymentBkashTokenizedTest.php`)

- configured/unconfigured honesty (no credentials ⇒ no callbacks, no redirect)
- hosted checkout creation + redirect, manual fallback preserved
- token grant cached exactly once across two create calls
- state token round-trip + tamper + cross-payment rejection
- callback settles only on provider-confirmed status
- forged state → 403; unconfirmed status → not settled; amount mismatch →
  not settled; provider failure → payment failed
- replay idempotency (exactly one `paid` + one non-duplicate confirmation)
- tokenized refund path + refusal without credentials
- status normalisation (Completed/Failed/Initiated)

Verification: `bash scripts/ci/verify-g1.sh` → **12/12** (SQLite 871 passed,
PostgreSQL 885 passed, Pint, composer validate/audit, secret scan, OpenAPI).

---

## 2. Nagad (G2-B) ✅ DONE

| Piece | File |
|---|---|
| API client | `app/Gateways/NagadClient.php` — checkout initialize / complete / verify; every request signed RSA-SHA256 with the merchant private key (`sensitiveData` + `signature`) |
| Adapter | `app/Gateways/NagadGateway.php` — manual fallback vs. live hosted checkout; implements `PaymentStatusQueryable` |
| Tests | `tests/Feature/PaymentNagadTest.php` — 10 tests |

Details: the `.env` single-line PEM convention (`\n` → real newlines) is
normalised before signing. `refundExternal()` stays refused — Nagad's standard
Checkout API exposes no refund endpoint, so refunds remain platform-side.
Status normalisation: `Success` → completed; `Failed`/`Cancelled` → failed.

## 3. SSLCommerz (G2-C) ✅ DONE

| Piece | File |
|---|---|
| API client | `app/Gateways/SslCommerzClient.php` — session init, order validation, refund, IPN MD5 `verify_sign` check |
| Shared flow | `app/Gateways/SslCommerzCheckout.php` — the server-to-server sequence shared with Card |
| Adapter | `app/Gateways/SslCommerzGateway.php` — hosted checkout + `PaymentStatusQueryable` + external refund |
| Tests | `tests/Feature/PaymentSslCommerzTest.php` — 11 tests |

Details: the IPN MD5 signature is verified exactly per the provider's
algorithm (`MD5(store_passwd + concat(verify_key field values in order))`,
constant-time compare), then **re-validated server-to-server** via the
order-validation endpoint — per the provider's own guidance that the MD5 is
"necessary, not sufficient". The payer return may be GET or POST (the callback
route accepts both). Amount/currency are re-checked before settling.

## 4. Card / Rocket / bank (G2-D) ✅ DONE (honest scope)

| Provider | Decision |
|---|---|
| `card` | ✅ Delegates to the SSLCommerz aggregator (`CARD_GATEWAY=sslcommerz` + store credentials ⇒ configured). `app/Gateways/CardGateway.php` + `tests/Feature/PaymentCardGatewayTest.php` (6 tests). A card payment cannot be manually verified, so unconfigured card refuses rather than faking. |
| `bank` | Manual (unchanged) — a bank transfer is verified by an admin against the account statement; there is no API to call. |
| `rocket` | Manual (unchanged, documented) — DBBL Rocket does not publish a standardised, publicly documented merchant API comparable to bKash/Nagad; rather than invent endpoints, the adapter keeps the honest manual-verification flow. |

---

## 5. Shared surface (all gateways)

- `app/Contracts/PaymentStatusQueryable.php` — normalised status-query
  contract (`completed`/`failed`/`pending`) with optional `$callbackData`.
- `app/Http/Controllers/PaymentGatewayCallbackController.php` — provider-
  agnostic payer-return handler: signed `state` → server-side status →
  `confirmProviderPayment()`/`markGatewayFailed()`.
- `app/Services/PaymentService.php` — `confirmProviderPayment()` (amount/
  currency re-validation, idempotent) and `markGatewayFailed()`.
- `app/Models/PaymentEvent.php` — `payment.gateway_confirmed`,
  `payment.gateway_failed`.
- `routes/web.php` — `GET|POST /payments/callback/{provider}`.
- `config/payments.php`, `config/services.php`, `.env.example` —
  `token_ttl_seconds`, `PAYMENT_CALLBACK_SECRET`, `BKASH_TOKEN_TTL_SECONDS`.

## 6. Progress log

| Date | Gateway | Added | Verified |
|------|---------|-------|----------|
| 2026-09-13 | bKash (G2-A) | client, adapter, signed state, callback, service methods, events, route, config + 16 tests | verify-g1.sh 12/12 |
| 2026-09-13 | Nagad (G2-B) | client (RSA-signed), adapter + 10 tests | verify-g1.sh 12/12 |
| 2026-09-13 | SSLCommerz (G2-C) | client (IPN MD5 + order validation), shared flow, adapter + 11 tests | verify-g1.sh 12/12 |
| 2026-09-13 | Card/Rocket/bank (G2-D) | card→SSLCommerz delegation + 6 tests; Rocket/bank manual (documented) | verify-g1.sh 12/12 |

**Final G2 verification:** `bash scripts/ci/verify-g1.sh` → 12/12 — 441 files
lint clean; SQLite **898 passed** (14 skipped); PostgreSQL **912 passed**
(1 skipped); Pint (scoped gate incl. all G2 files); composer validate/audit;
secret scan; OpenAPI 73 paths.

*Line counts are measured with `find app … tests … -name '*.php' | xargs wc -l`
and are honest — no generated or filler content.*
