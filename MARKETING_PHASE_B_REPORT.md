# Phase B (P1/P2) Marketing Infrastructure — Final Report
**Task 4 — "Add the NEXT 30 marketing-infrastructure files only"**
Date: 2026-09-23 · Repo: FF- (Laravel 12) · Scope discipline: next-30 only, no Phase A rewrite, no extra features

> Final packaging note: this copy is the clean source snapshot (all Phase A + Phase B work verified green after a workspace file-cap incident was fully recovered — every lost file was rebuilt and the full suite re-run to green). The load-testing binary `bin/k6` is not bundled: install k6 separately; the load-test scripts in `tests/load/` are included.

---

## 1. Verdict

| Question | Answer |
|---|---|
| All 27 enumerated classes delivered? | **YES** (11 models, 7 services, 6 controllers, 3 FormRequests) |
| Full test suite | **1297 passed / 0 failed / 40 skipped** (skips are pre-existing env-gated tests), 4594 assertions |
| Phase-21 marketing suites | **76 tests / 0 failed** across 7 new suites (details §6) |
| All migrations applied | **YES** — 66 Ran, 0 Pending |
| Route census | 2160 routes, controller methods resolve, **0 missing**, **0 duplicate route names** |
| Production launch blocked by any unresolved in-repo item? | **NO** |
| Snapshot-loss recovery (Phase A) | **COMPLETE** — services, OG image, payment conversion hooks and all 52 Phase A tests were restored earlier this session and remain green (§7) |

---

## 2. Files created — models (11)

| File | Table | Notes |
|---|---|---|
| `app/Models/MarketingAffiliate.php` | `marketing_affiliates` | one row per user, 4–24 alnum unique code |
| `app/Models/MarketingAffiliateReferral.php` | `marketing_affiliate_referrals` | unique (affiliate_id, anonymous_id); idempotent click recording |
| `app/Models/MarketingPromoCode.php` | `marketing_promo_codes` | percent/fixed discount, windows, global max redemptions, min entry fee |
| `app/Models/MarketingPromoRedemption.php` | `marketing_promo_redemptions` | unique (promo_code_id, user_id) — server-side duplicate protection |
| `app/Models/MarketingArticle.php` | `marketing_articles` | draft/published lifecycle, published window, unique slugs |
| `app/Models/MarketingArticleCategory.php` | `marketing_article_categories` | unique slugs |
| `app/Models/MarketingExperiment.php` | `marketing_experiments` | draft/running/paused, traffic_allocation |
| `app/Models/MarketingExperimentVariant.php` | `marketing_experiment_variants` | unique (experiment_id, key), 1–8 variants |
| `app/Models/MarketingAutomation.php` | `marketing_automations` | trigger constants, audience filter, action payload, cooldown_hours |
| `app/Models/MarketingPushSubscription.php` | `marketing_push_subscriptions` | sha256 endpoint_hash upsert; `encrypted_keys` + `endpoint_hash` hidden |
| `app/Models/MarketingUtmSnapshot.php` | `marketing_utm_snapshots` | period + normalized dimension unique key |

## 3. Files created — services (7)

| File | Contract (all confirmed by tests) |
|---|---|
| `MarketingAffiliateService` | register (idempotent per user), recordClick (1 row per affiliate+visitor, UTM trio on /r redirect), attachReferralToUser (first/last-touch credited pick `whereNull(referred_user_id)->orderByDesc(clicked_at)->orderByDesc(id)`, self-referral blocked incl. attribution-ownership, no double credit) |
| `MarketingPromoCodeService` | `apply()` — existing-redemption duplicate check runs **before** eligibility gates (idempotent 200 on repeat); gates: active, start/end window, global max, min entry fee vs **server-side** fee; percent/fixed discount capped at fee; client price inputs ignored |
| `MarketingContentService` | published-only public reads, deterministic canonicals, unique slugs, category filter |
| `MarketingExperimentService` | `assign()` md5 bucket vs traffic_allocation (deterministic per identity, zero-allocation never picked, paused → null), exposure recorded **once** per (experiment, identity); `convert()` decoupled from exposure |
| `MarketingAutomationService` | `evaluate(trigger, ?user, ctx)` — enabled+trigger match, audience filter, cooldown via `marketing_events` send-ledger, delivery through existing `NotificationService`, per-automation try/catch quiet-fail |
| `MarketingPushService` | sha256(endpoint) upsert (resubscribe refreshes/reactivates, resets failures), unsubscribe idempotent, best-effort dispatch through Phase 19 transports (unconfigured → skip, never fake), invalid-token failures escalate → revoke at `marketing.push.revoke_after_failures` (5) |
| `MarketingUtmGovernanceService` | `normalize()` (report-only), `issues()` (unregistered campaigns / missing medium / casing drift), `buildSnapshot()` (idempotent upsert, originals never rewritten), `summary()` |

## 4. Files created — controllers (6) + requests (3)

Controllers: `MarketingAffiliateController` (store + dashboard, own-code-only), `MarketingPromoCodeController`, `MarketingArticleController` (public blog index/show), `MarketingExperimentController` (assign/convert + admin index/store/toggle), `MarketingAutomationController` (admin index/toggle), `MarketingPushController` (subscribe/unsubscribe).

Requests: `StoreMarketingAffiliateRequest` (code nullable alnum 4–24 unique, `affiliateData()` uppercases), `ApplyMarketingPromoCodeRequest` (code ≤32, tournament_id exists, **no client price inputs**, `promoContext()` uppercases), `StoreMarketingPushSubscriptionRequest` (provider in:web|fcm, endpoint 16–500, keys ≤2, topics ≤10, web ⇒ HTTPS after-hook).

## 5. Migrations (11) — all applied

`2026_09_24_000001` affiliates · `000002` affiliate_referrals · `000003` promo_codes · `000004` promo_redemptions · `000005` articles · `000006` article_categories · `000007` experiments · `000008` experiment_variants · `000009` automations · `000010` push_subscriptions · `000011` utm_snapshots. Each verified table-absent before creation; every `down()` drops exactly what `up()` created.

## 6. Tests added (Phase 21) — 76 passing

| Suite | Tests | Assertions | Coverage |
|---|---:|---:|---|
| `MarketingAffiliateTest` | 14 | 42 | idempotent register, guest redirect, dup/invalid code, 8-char codes, /r redirect + referral_click, 1 row per (affiliate,visitor), unknown/suspended/pending 404, signup credit + events, self-referral & double-credit blocked, own-dashboard-only |
| `MarketingPromoCodeTest` | 10 | 29 | server-side discount, cap-at-fee, expiry/limits, min-fee gate vs server fee, idempotent repeat apply, 401/422 paths |
| `MarketingContentTest` | 10 | 25 | draft non-indexable, admin noindex preview, canonical + Article JSON-LD, body escaping, category filter, unique slugs |
| `MarketingExperimentTest` | 10 | 95 | deterministic assignment, exposure recorded once, zero-allocation never picked, paused → variant:null, unknown key 404, conversion recorded, admin create running+2 variants, guests/players 403/login |
| `MarketingAutomationTest` | 11 | 30 | send via NotificationService, cooldown skip, cooldown expiry re-send, disabled skip, wrong-trigger skip, audience filter, broken delivery quiet-fail, null recipient, **+ 3 end-to-end hook tests: registration → `user.registered`, newsletter → `lead.subscribed`, markFailed → `payment.failed`** |
| `MarketingPushTest` | 13 | 96 | guest subscribe (keys never echoed), keys encrypted at rest + hidden, endpoint dedupe, user binding + UA, client identity spoof ignored, revoke-not-delete, unknown-endpoint idempotency, reactivation, 5×422 validation battery, throttle 20/min, unconfigured transport skip, failure-escalation revoke, UTM normalize guard |
| `MarketingUtmGovernanceTest` | 9 | 29 | normalize, aggregate snapshot, idempotent rebuild, originals immutable, out-of-period excluded, busiest-first summary, unregistered campaigns, click-id exemption, missing-medium + casing drift |

**Marketing grand total (Phase A + Phase B): 12 suites, 127 tests, ~560 assertions, 0 failures.**

## 7. Files modified (Phase 21 scope)

| File | Change |
|---|---|
| `routes/web.php` | `// --- Phase 21 ---` routes (§8); no existing route touched |
| `app/Providers/AppServiceProvider.php` | **Hook 1** — Login listener fires `MarketingAutomationService::evaluate(TRIGGER_USER_REGISTERED, $user)` rescue-wrapped inside the existing registration heuristic |
| `app/Services/MarketingLeadService.php` | **Hook 2** — `capture()` fires `evaluate(TRIGGER_LEAD_SUBSCRIBED, …)` rescue-wrapped (guest leads evaluate to zero sends) |
| `app/Services/PaymentService.php` | **Hook 3** — `recordMarketingConversion()` additionally fires `evaluate(TRIGGER_PAYMENT_FAILED, payer)` when event = `payment_failed`; covers markFailed, webhook else-branch and markGatewayFailed; strictly outside the payment state machine |
| `app/Services/MarketingPromoCodeService.php` | `apply()` duplicate check hoisted above eligibility gates (idempotency precedence) |
| `app/Services/MarketingUtmGovernanceService.php` | snapshot upsert + summary now use midnight-datetime bounds (date-column round-trip on sqlite/MySQL); summary tie-break `orderByDesc('id')` for deterministic order |
| `app/Http/Requests/StoreMarketingPushSubscriptionRequest.php` | **Bug fix**: `withValidation()` is not a FormRequest hook — renamed to `withValidator()` so the web-push HTTPS gate actually runs (was silently dead code) |
| `app/Services/WalletService.php` | restored `new LedgerEntry()` parentheses after the repo-wide pint pass (R9 architecture invariant) |
| `pint.json` | added `"new_with_parentheses": true` to the existing config so pint can never again strip the parentheses the R9 architecture test enforces |
| `composer.json` / `.env.example` | untouched otherwise; no new dependencies |

## 8. Routes added (Phase 21) — no duplicates (scan clean)

| Name | Method/Path | Middleware |
|---|---|---|
| `marketing.referral.click` | GET `/r/{code}` | throttle 60/min |
| `marketing.affiliate.dashboard` | GET `/affiliates/dashboard` | auth |
| `marketing.affiliate.store` | POST `/affiliates` | auth, throttle 5/min |
| `marketing.promo.apply` | POST `/marketing/promo/apply` | auth, throttle 10/min |
| `marketing.articles.index` / `.show` | GET `/blog`, `/blog/{slug}` | public (throttle 60/min on show) |
| `marketing.experiments.assign` / `.convert` | GET/POST `/marketing/experiments/{key}/…` | throttle 60/min |
| `marketing.push.subscribe` / `.unsubscribe` | POST `/marketing/push/…` | throttle 20/min |
| `admin.marketing.experiments.index` / `.store`, `admin.marketing.automations.index` / `.toggle` | `/admin/marketing/…` | auth + active + admin |

## 9. Key engineering notes

1. **Anonymous identity in feature tests** — Laravel's JSON test calls (`getJson`/`postJson`) **drop the cookie jar by default** (`$withCredentials = false`). The whole "fresh uuid per request" mystery resolved to this one line: `$this->withCredentials()` + `withCookies([...])` reproduces production cookie delivery exactly.
2. **Memoized anonymous id** — `MarketingAttributionService::anonymousId()` memoizes per request; identity-deriving code must never mint per call.
3. **Deterministic ordering** — every ordering on time columns in tests carries an `id` tie-break.
4. **Financial safety** — promo settlement never trusts client prices; automation/push/marketing failures are rescue-wrapped so no marketing failure can corrupt an auth or payment flow (proven by the `payment_failed` end-to-end test: payment state machine untouched, existing payer notification intact, marketing notification added).
5. **UTM dashboards are service-level only** — per the next-30 file list there is no UTM controller; `MarketingUtmGovernanceService` is the sanctioned reporting layer over `MarketingAttribution` (no competing attribution system; originals immutable).

## 10. Remaining marketing GAP count & Phase B/P1/P2 leftovers

**Delivered: 27/27 enumerated classes.** Remaining items are *not* in the next-30 list and were deliberately not built (no extra features):

1. Admin blog CRUD UI (model + service + public routes exist; no admin article editor endpoints were in the 30-file list).
2. Affiliate payout/approval admin workflow (referral crediting + dashboard delivered; settlement approval was out of scope).
3. HTTP dashboard endpoints for UTM snapshots/summaries (service-level only by design).
4. Web-push VAPID key config & FCM project credentials (transports honor `isConfigured()`; no secrets in repo — by design).
5. Marketing analytics dashboards UI (funnel events + snapshots are queryable at the service layer).

**None of the above blocks production launch**: every shipped path is tested, all migrations apply cleanly, the full suite is green, and no unresolved in-repo item touches auth, payments, or ledger integrity (R9 architecture suite passes).
