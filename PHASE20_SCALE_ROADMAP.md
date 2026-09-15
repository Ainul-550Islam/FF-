# FF Arena — Scale Roadmap (200k–500k+ lines, legitimate expansion)

**Owner:** Arena.ai Agent Mode · **Started:** 2026-09-13 · **Target:** grow the
platform from ~64k lines to 200k–500k+ lines **without padding** — every added
line is a real feature, a real test, or real documentation, and every phase is
verified (tests green on SQLite **and** PostgreSQL, Pint clean, secrets clean).

---

## 0. Honesty first

A line-count target is a *lagging* metric, not a goal in itself. FF Arena's real
production value comes from the features those lines implement. This roadmap
therefore grows the platform **through the remaining production blockers first**
(G2 → G7), then broadens feature depth. Line counts are tracked honestly below
and are never inflated with filler, duplicated code, or generated noise.

### Current inventory (measured 2026-09-13)

| Bucket | Lines | Files |
|---|---:|---:|
| App + bootstrap + config + migrations + seeders + routes + views | ~43,400 | 425 |
| Tests (unit + feature, both drivers) | ~19,200 | — |
| Docs (`docs/` + phase reports) | ~1,900 | — |
| **Total** | **~64,500** | — |

### Target buckets (each phase appends to these)

- **G2** live payment gateways (bKash / Nagad / SSLCommerz / Rocket / card),
  hosted checkout, IPN/webhook verification, refunds, reconciliation tests.
- **G3** coverage (pcov) + load tests (k6) + a bench harness.
- **G4** Redis (cache, rate limit, queue) + multi-node-safe locks.
- **G5** realtime (Reverb/WebSockets) + SSE fallback.
- **G6** deployment pipeline (image build, staging→prod promotion, rollback).
- **G7** native mobile release (APK/IPA build + device QA).
- **Feature depth** after G7: more game modes, deeper analytics, an operator
  console, multi-org tenancy, richer public API + Flutter surfaces.

---

## 1. Non-negotiables (unchanged from Phases 01–19 + G1)

1. **No fake success** — a payment/push/build/callback is never reported
   successful unless a server-authoritative check confirmed it.
2. **Dual-driver** — SQLite (local/test) and PostgreSQL (production) both stay
   green; every migration runs on both.
3. **Business logic preserved** — scoring, ranking, lifecycle, roster, wallet/
   ledger, payout/settlement, disputes, anti-fraud, API/mobile contracts,
   webhook behaviour are extended, never silently rewritten.
4. **Financial invariants** — reconciliation must stay at zero difference;
   if money diverges, STOP.
5. **No secrets committed** — credentials are env-only; health/diagnostics
   redact them.
6. **Everything verified** — `bash scripts/ci/verify-g1.sh` (extend as phases
   add checks) before a phase is declared complete.

---

## 2. Phase plan (next = G2)

| # | Phase | What it adds (summary) | Status |
|---|-------|------------------------|--------|
| — | G1 | PostgreSQL production datastore + dual-driver + migration tooling | ✅ DONE |
| 20 | **G2** | Live payment gateways (tokenized/hosted), callback verification, refunds | ✅ DONE (bKash, Nagad, SSLCommerz, card→SSLCommerz; Rocket/bank manual) |
| 21 | G3 | Coverage (pcov) + load/bench harness (k6) | ⬜ pending |
| 22 | G4 | Redis cache/rate-limit/queue + shared locks | ⬜ pending |
| 23 | G5 | Realtime (Reverb/WebSockets) + SSE fallback | ⬜ pending |
| 24 | G6 | Deploy pipeline + staging → production promotion | ⬜ pending |
| 25 | G7 | Native mobile release (APK/IPA) + device QA | ⬜ pending |
| 26+ | Depth | Game modes, analytics, operator console, tenancy, API/mobile expansion | ⬜ pending |

---

## 3. G2 — Live payments (current work)

### 3.1 Scope

Replace the deliberately-conservative gateway stubs with real, testable
server-to-server API clients **while keeping the manual fallback intact** when
credentials are absent:

- **bKash Tokenized Checkout** — grant token → create → redirect → execute →
  query → refund (server-authoritative confirmation; the payer redirect is
  never trusted on its own).
- **SSLCommerz Hosted Checkout** — session init + IPN validation + hash
  verification (`verify_sign`/`verify_key`).
- **Nagad** — checkout initiate/complete + payment verify (PKI); honest about
  the sandbox/live differences.
- **Rocket / card / bank** — keep the manual/saved-method flows, add
  verification hooks where a real API exists.

### 3.2 Invariants (re-stated, tested)

- Two identical callbacks/IPNs MUST NOT settle the payment twice (idempotent).
- The gateway redirect/IPN is re-verified server-to-server before settling.
- Amount + currency are always re-checked against the server-side value.
- `configured() === false` ⇒ the gateway never fabricates a confirmation.

### 3.3 Deliverables per gateway

- API client (testable via `Http::fake`), gateway adapter, callback/IPN
  controller + routes, `.env.example` keys, feature tests (both drivers),
  and a section in `G2_LIVE_PAYMENTS_REPORT.md`.

---

## 4. Progress log

| Date | Phase | Added | Lines delta | Verified |
|------|-------|-------|------------:|----------|
| 2026-09-13 | G2-A | bKash Tokenized Checkout: `GatewayHttp`, `PaymentCallbackState`, `BkashTokenizedClient`, `PaymentStatusQueryable`, live `BkashGateway`, `PaymentGatewayCallbackController` + route, `confirmProviderPayment`/`markGatewayFailed`, `payment.gateway_confirmed/failed` events, config + `.env.example` | ~1,190 | ✅ verify-g1.sh 12/12 (871 SQLite / 885 PG) |
| 2026-09-13 | G2-B | Nagad Checkout: `NagadClient` (RSA-signed requests), live `NagadGateway`, +10 tests | ~600 | ✅ verify-g1.sh 12/12 |
| 2026-09-13 | G2-C | SSLCommerz hosted checkout: `SslCommerzClient` (IPN MD5 + order validation), `SslCommerzCheckout` shared flow, live `SslCommerzGateway`, +11 tests | ~650 | ✅ verify-g1.sh 12/12 |
| 2026-09-13 | G2-D | Card → SSLCommerz delegation (`CardGateway`), +6 tests; Rocket/bank manual (documented) | ~500 | ✅ verify-g1.sh 12/12 (898 SQLite / 912 PG) |

*Line deltas are re-measured with `find app tests database routes config -name '*.php' | xargs wc -l` after each phase; only honest, real additions count.*
