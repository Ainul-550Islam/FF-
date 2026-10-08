# Provider Sandbox E2E & Live Pilot Runbook

The code side of provider readiness is done (server-side success truth,
HMAC webhooks, idempotent settlement, `payments:probe`). What remains is
runtime proof. This runbook is the checklist — attach its evidence to the
launch gate.

## 0. Pre-flight (no credentials, no money)

```bash
php artisan payments:probe          # config sanity
php artisan payments:probe --ping   # + TLS routability to each base URL
```

Proceed only when the probe passes. `enabled but not configured` is a
warning, not a blocker: checkout degrades to the manual TrxID flow.

## 1. Sandbox E2E (per hosted provider: bKash, Nagad, Card, SSLCommerz)

For each provider, with `*_MODE=sandbox` and sandbox credentials:

1. Create a ৳100 test tournament; register a team as the captain.
2. Pay through the hosted checkout to completion.
3. Assert: payer return lands on `payment.pending`; payment settles to
   `paid` (bKash/Nagad/Card) or `paid` via `val_id` re-query (SSLCommerz);
   team flips to `confirmed`; exactly one `paid` event exists.
4. Replay the provider webhook (same event id) → assert `replay: true`,
   no second settlement, no second confirmation.
5. Post a forged-state return (`state=forged`) → assert 403 and no
   provider query in the logs.
6. Refund from the admin panel with step-up → assert single wallet
   credit and one `refunds` row.
7. For SSLCommerz only: deliver the IPN with a bad hash → assert 401.

Record for each step: provider, sandbox reference/TrxID, payment id,
timestamp. Save the `payments` + `payment_events` rows as the evidence
attachment.

## 2. Manual methods (Rocket, bank transfer)

1. Pay with method `rocket`/`bank` + a TrxID → assert payment stays
   `pending` and the team stays unconfirmed.
2. Hit `/payments/callback/{rocket,bank}` with a valid state token →
   assert redirect to `payment.pending` (200/302, never 500).
3. Admin verify with step-up → assert `verified` + team `confirmed`.
4. Admin refund with step-up → assert platform-side wallet credit and
   the "manual/external" note (external refunds are never fabricated).

## 3. Live pilot (real money, bounded blast radius)

1. Flip ONE provider to `production` with live credentials; keep the
   rest on sandbox. Run `payments:probe --ping` against the live host.
2. Publish a private ৳10 tournament; pay with a real wallet (ops-owned
   number); verify settlement + confirmation within 5 minutes.
3. Refund the pilot payment; reconcile the provider settlement report
   against `ledger_entries` the next morning.
4. Leave the pilot tournament open 24h with webhook delivery monitoring
   (`X-FFArena-Delivery` log + failed-jobs queue empty).
5. Promote provider-by-provider; never flip all six at once.

## 4. Rollback

- Set the provider's `*_ENABLED=false` → checkout stops offering it
  within 60s (status cache TTL); in-flight hosted payments still settle
  via callback/webhook; pending manual payments verify normally.
- No code deploy is required to pause a provider.

## 5. Promotion criteria (launch gate evidence)

- [ ] `payments:probe` passes on the production host (attach output).
- [ ] Sandbox E2E table complete for all four hosted providers.
- [ ] Manual-method checks complete for Rocket + bank.
- [ ] At least one live-pilot payment + refund reconciled per provider
      before it is offered to real tournaments.
- [ ] Coverage baseline published; `tests-postgres` and mobile jobs
      flipped to required (see `docs/TEST_EVIDENCE.md`).
