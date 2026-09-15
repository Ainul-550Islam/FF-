# Phase 09 — Prize Distribution + Payouts + Revenue/Commission + Financial Reconciliation Report

**Project:** FF Arena (Laravel 12 + SQLite) · **Date:** 2026-09-07
**Scope:** Prize configuration, immutable prize snapshots, a controlled prize-
distribution state machine, payout records + wallet integration, revenue/
commission accounting, financial reconciliation and an immutable settlement
snapshot — built ON TOP of Phases 01–08 without rebuilding or replacing any of
them. Phase 10+ (anti-cheat, identity verification, notifications, realtime,
public/mobile APIs, analytics, DevOps) is explicitly out of scope.

---

## 1. Existing Financial Audit

Inspected in place before any change:

- `Tournament.prize_pool` — a `decimal(12,2)` (float-cast) column the
  organizer sets at create/edit time; validated `numeric|min:0`. It is a
  **declared** prize pool displayed on the home/tournament pages. **It is NOT
  derived from collected entry fees** — no code links `prize_pool` to
  `payments` (confirmed by grep: `prize_pool` appears only in
  `TournamentController`, `Tournament`, `TournamentLifecycleService` and the
  Blade views). This business rule is preserved: Phase 09 treats the declared
  pool as the prize-pool authority and reconciles it separately against net
  collections.
- `Payment`/`Wallet`/`LedgerEntry`/`Refund`/`PaymentEvent` — Phase 08
  financial architecture (integer poisha, immutable ledger, refunds) is used
  unchanged as the money substrate.
- `GameMatch`/`Score`/`ScoringRule`/`ScoringService::standings()` — Phase 06
  deterministic standings (points desc, tie-breaker chain, team-id fallback)
  is the single source of truth for final rankings. Standings exclude bye,
  cancelled and disputed matches.
- `Tournament` lifecycle (Phase 02/05): `finished` is terminal and only
  reachable once every match is `completed`.
- `Dispute`/`DisputeService` (Phase 07): actionable disputes
  (`open`/`under_review`) halt results; resolved disputes return matches to
  `completed`.
- Existing admin controls (Phase 07/08): payments list/verify/fail/refund and
  wallet credit/debit — all admin-only, preserved.

**Findings:** there was no prize distribution, no payouts, no revenue/
commission engine, no reconciliation and no settlement snapshot. Prizes were
a purely cosmetic number.

## 2. Prize Architecture

- **`PrizeTier`** — configurable prize rule per finishing rank: fixed
  (integer poisha) OR percentage (integer basis points), admin-configured,
  server-validated.
- **`PrizeDistribution`** — the distribution workflow (state machine) with the
  frozen `pool_minor` and `total_allocated_minor` captured at calculation.
- **`PrizeSnapshotItem`** — immutable rank → team → amount rows, written at
  calculation time; never edited afterwards.
- **`Payout`** — one payout record per awarded rank, server-derived recipient/
  rank/amount, controlled state machine, unique idempotency key, unique
  (distribution, rank).
- **`PayoutEvent`** — append-only payout audit trail.
- **`FinancialSettlement`** — immutable per-tournament financial snapshot
  (unique per tournament).
- **`SettlementAdjustment`** — approved correction/reversal records.
- **`PrizeDistributionService`** orchestrates configuration, calculation
  (standings + snapshot), approval (payout creation), processing
  (disbursement) and cancellation.
- **`PayoutService`** drives the payout state machine and atomic wallet
  credits via the Phase 08 `WalletService`.
- **`ReconciliationService`** computes gross/refunds/net/pool/allocated/
  completed/revenue/adjustments and classifies balanced/underfunded/
  overallocated/mismatch.

## 3. Prize Configuration

- Fixed minor-unit amounts (`amount_minor`, poisha) or percentage tiers
  (`percentage_bp`, basis points; 10000 bp = 100%).
- Server-side validation rejects: negative amounts, negative percentages,
  duplicate positions, positions outside 1–100, percentage totals over 100%,
  and any allocation (fixed sum + resolved percentages) that exceeds the
  declared prize pool.
- Percentages resolve against the frozen pool with integer arithmetic
  (`intdiv(pool * bp, 10000)`) — no floating point.
- Tiers are editable only before calculation (or while the distribution is a
  draft); once calculated they are locked (snapshot authority).

## 4. Prize Snapshots

`calculate()` freezes, atomically, the resolved tiers + final standings into
`prize_snapshot_items` (rank, team, type, amount) and records
`pool_minor`/`total_allocated_minor` on the distribution. Later edits to
`prize_pool`, tiers, fees, scores or the leaderboard never rewrite the
snapshot (verified by test). Re-calculation of an already-calculated
distribution is a no-op.

## 5. Final Standings Integration

Distribution uses `ScoringService::standings($tournament)` exclusively — the
same deterministic Phase 06 engine that powers the leaderboard. No independent
winner calculation, no database-order dependence; ties fall back to the
configured tie-breaker chain and finally to team id.

## 6. Settlement Eligibility

`PrizeDistributionService::assertEligible()` blocks distribution while the
tournament is not `finished`, while any match is not completed (or bye/
cancelled), or while any dispute is actionable. Standings availability is
checked in `calculate()`.

## 7. Payout Architecture

`Payout` records hold tournament, distribution, recipient team + recipient
user, rank, amount (poisha), currency, status, method, provider, provider
reference, idempotency key, approver, processor, processed_at, failure reason
and timestamps. Recipients are **always** derived server-side from the
snapshot + the winning team's captain (FF Arena has no team wallet; the
captain is the team's accountable owner and paid the entry fee). Rank and
amount come only from the snapshot.

## 8. Payout State Machine

`pending → approved, cancelled` · `approved → processing, cancelled` ·
`processing → completed, failed` · terminal: completed/failed/cancelled.
`completed → processing` and `completed → approved` are impossible (no
outgoing transitions from completed; guarded in the service). A payout is
never processed twice (idempotency via row lock + status checks + unique
constraints).

## 9. Wallet Integration

Internal (`wallet`) payouts credit the recipient's wallet via
`WalletService::credit()` — never a direct `balance_minor` write — producing a
`TYPE_PAYOUT` ledger entry with running balance, inside the same transaction
that marks the payout `completed`. So `payout=completed` ⟺ `wallet credited`
always; a frozen wallet rolls the whole operation back (payout stays approved
and the distribution fails).

## 10. External Payout Limitations

- `PayoutGatewayInterface` + `PayoutGatewayManager` with `WalletPayoutGateway`
  (internal) and `ManualPayoutGateway` (manual out-of-platform settlement).
- No live bKash/bank payout API exists, so **no external success is faked**:
  manual payouts stay in `processing` until an admin marks them completed with
  an external reference. Internal / manually-processed / provider-confirmed /
  failed states are distinguished.

## 11. Revenue / Commission Accounting

`config/finance.php` holds the commission rule: percentage (basis points) or
fixed (poisha). **Default is zero** — no pre-existing commission business rule
exists, so no fee is silently introduced. Commission is computed server-side
on net collections; fixed commission is capped at eligible revenue. Gross
collection, refunds, prize allocation and platform revenue are kept as
separate line items, never conflated.

## 12. Reconciliation

`ReconciliationService::summary()` computes from source records:

gross − refunds = net · declared pool · allocated · completed payouts ·
commission · adjustments · remaining. Status classification:
- **overallocated** — allocated prizes exceed the declared pool
- **underfunded** — net collection cannot cover allocation + commission +
  adjustments
- **mismatch** — distribution incomplete or completed payouts ≠ allocation
- **balanced** — otherwise. Discrepancies are surfaced, never hidden.

## 13. Financial Snapshots

At distribution completion, `finalize()` writes an immutable
`FinancialSettlement` (unique per tournament): gross/refunded/net/pool/
allocated/completed/revenue/adjustments, reconciliation result, finalizing
admin and timestamp. It is written once and never overwritten; post-
finalization changes are refused and must go through correction/reversal
records.

## 14. Refund Interaction

Refunds reduce net collection (`gross − refunded`); a refunded entry fee never
increases the prize pool (the pool is declared). If refunds push net below the
allocation, reconciliation reports `underfunded` — the discrepancy is visible.
After finalization, the snapshot is immutable.

## 15. Dispute Interaction

Distribution is blocked while any dispute is `open`/`under_review`. Once
disputes are resolved (Phase 07 returns matches to `completed`), the Phase 06
standings engine recomputes the final ranking, and `calculate()` snapshots the
corrected standings.

## 16. Security Review

Fixed/covered within scope: payout IDOR (admin-only lists; wallet page shows
only the user's own payouts — verified), cross-tournament payout access
(snapshot only contains the tournament's own teams), recipient/rank/amount
tampering (all derived server-side from snapshot + captain; client input
ignored), prize-pool tampering (allocation > pool rejected), commission
tampering (config-only, zero default, capped), duplicate payout (unique
(distribution, rank) + idempotency key + row locks), duplicate wallet credit
(idempotent process), unauthorized approval/process/finalization (admin-only
middleware + policies; organizer/player → 403), payout status tampering
(mass-assignment guarded + state machine), race conditions (transactions +
`lockForUpdate` + unique constraints). No secrets/cards/credentials are stored
in any audit record.

## 17. Database Changes

One new migration `2026_09_05_000000_add_prize_payout_settlement.php` creates
7 tables — `prize_tiers`, `prize_distributions`, `prize_snapshot_items`,
`payouts`, `payout_events`, `financial_settlements`,
`settlement_adjustments` — all with foreign keys, unique constraints and
indexes, integer minor-unit money. No Phase 01–08 table or column is modified
or dropped.

## 18. Files Created

- `database/migrations/2026_09_05_000000_add_prize_payout_settlement.php`
- `config/finance.php`
- `app/Models/PrizeTier.php`
- `app/Models/PrizeDistribution.php`
- `app/Models/PrizeSnapshotItem.php`
- `app/Models/Payout.php`
- `app/Models/PayoutEvent.php`
- `app/Models/FinancialSettlement.php`
- `app/Models/SettlementAdjustment.php`
- `app/Contracts/PayoutGatewayInterface.php`
- `app/Gateways/WalletPayoutGateway.php`
- `app/Gateways/ManualPayoutGateway.php`
- `app/Services/PayoutGatewayManager.php`
- `app/Services/PayoutService.php`
- `app/Services/ReconciliationService.php`
- `app/Services/PrizeDistributionService.php`
- `app/Policies/PrizeDistributionPolicy.php`
- `app/Policies/PrizeTierPolicy.php`
- `app/Policies/PayoutPolicy.php`
- `app/Policies/FinancialSettlementPolicy.php`
- `app/Http/Controllers/SettlementController.php`
- `app/Http/Controllers/PayoutController.php`
- `resources/views/admin/settlements.blade.php`
- `resources/views/admin/settlement.blade.php`
- `resources/views/admin/payouts.blade.php`
- `tests/Feature/PrizePayoutSettlementTest.php`
- `tests/Feature/SettlementSecurityTest.php`

## 19. Files Modified

- `app/Support/Money.php` — `toBasisPoints()` / `basisPointsToPercent()`
- `app/Models/LedgerEntry.php` — `TYPE_PAYOUT` constant
- `app/Models/Tournament.php` — `prizePoolMinor()`, settlement relations
- `app/Models/User.php` — `payouts()` relation
- `app/Models/Team.php` — `payouts()` relation
- `app/Http/Controllers/WalletController.php` — own-payout history
- `routes/web.php` — settlement + payout admin routes
- `resources/views/wallet/index.blade.php` — Prize Payouts card
- `resources/views/admin/dashboard.blade.php` — financial links

## 20. Tests Added

`PrizePayoutSettlementTest` (34 tests) — prize config (valid fixed/percentage,
negative/over-100%/duplicate/over-pool/invalid-position rejection), percentage
resolution, snapshot correctness + immutability, deterministic tie-break,
eligibility (open/cancelled/incomplete-match/dispute/no-standings blocked),
payout recipient/rank/amount, wallet credit + ledger, no-double-processing,
frozen-wallet failure, duplicate-rank prevention, manual payout flow, gross/
refund/net, underfunded/balanced/overallocated/mismatch, commission (zero
default, percentage, fixed cap), adjustments, frozen settlement, full HTTP
flow, own-payouts-only wallet page.

`SettlementSecurityTest` (18 tests) — guest/organizer/player denial, organizer
cannot calculate/approve/process, player cannot act on payouts,
cross-tournament snapshot isolation, mass-assignment guards (6 models),
completed-payout and completed-distribution idempotency, illegal transitions,
snapshot-derived rank/amount under bogus input, pool-tampering rejection,
wallet integrity.

## 21. Exact Test Results

- **Full suite: 340 passed (1068 assertions) — 0 failures, 0 skipped, 0 risky.**
- Phase 09 suites: **52 passed (128 assertions)**.
- Phase 01–08 regression: **288 passed (940 assertions)** — unchanged.

## 22. Migration Results

`php artisan migrate:fresh --seed --force` — **OK**; **19 migrations** applied
(18 existing + `2026_09_05_000000_add_prize_payout_settlement`), seed
completes.

## 23. Lint Results

`php -l` on **31 changed/new PHP files** — all "No syntax errors".

## 24. Route Results

**82 routes** (68 baseline + 14 new: 8 settlement + 6 payout admin routes).

## 25. HTTP Smoke Results

Live `php artisan serve` (migrated + seeded DB):
`/admin/settlements` guest → 302 · `/admin/payouts` guest → 302 ·
`/wallet` guest → 302 · admin `/admin/dashboard` → 200 ·
admin `/admin/settlements` → 200 · admin `/admin/payouts` → 200 ·
admin settlement detail page → 200 · organizer `/admin/settlements` → 403 ·
organizer `/admin/payouts` → 403.
The settlement flow, wallet credit, ledger entry, reconciliation and dispute
blocking are exercised end-to-end by the feature tests against the same HTTP
routes.

## 26. Phase 01–08 Regression Results

All 288 Phase 01–08 tests (940 assertions) pass unchanged alongside the new
suite — authorization, lifecycle, roster, check-in/waitlist, brackets,
scoring, disputes and payments/wallet/ledger are untouched.

## 27. Remaining Limitations

- The declared `prize_pool` remains organizer-set; it is reconciled against
  net collections but not auto-derived from them (preserving Phase 01–08
  behavior).
- Prizes are paid to the **team captain's wallet** (FF Arena has no team
  wallet entity); split-player payout models are out of scope.
- Only the internal `wallet` provider and the `manual` provider ship; no real
  external payout API exists, so provider-confirmed external payouts are not
  implemented (and not faked).
- Commission defaults to zero; enabling it is a config/env decision.
- Post-finalization corrections require new `SettlementAdjustment` records via
  a future admin flow; the snapshot itself is never mutated.
- SQLite is single-writer; `lockForUpdate` is a no-op there but the
  transaction + unique-constraint strategy is correct and portable to
  MySQL/PostgreSQL.
- Prize tiers are configured through a fixed 10-row form (no JS-heavy
  dynamic rows), per the no-JS Blade constraint.

---

## 28. Complete File Contents

### FILE: database/migrations/2026_09_05_000000_add_prize_payout_settlement.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 09 — prize distribution + payouts + revenue/commission +
     * financial reconciliation.
     *
     * prize_tiers            : configurable prize rules per tournament
     *                          (fixed minor-unit amounts OR percentage-based),
     *                          validated server-side and snapshotted before
     *                          distribution.
     * prize_distributions    : the prize-distribution state machine
     *                          (draft → calculated → approved → processing →
     *                          completed / failed / cancelled) with the prize
     *                          pool snapshot used at calculation time.
     * prize_snapshot_items   : immutable rank → team → amount snapshot rows
     *                          captured when a distribution is calculated.
     *                          Historical prize calculations never change when
     *                          the tournament, fees or tiers are later edited.
     * payouts                : one payout record per awarded rank, with the
     *                          server-derived recipient, integer minor-unit
     *                          amount, controlled status machine and a unique
     *                          idempotency key. A payout is never processed
     *                          twice.
     * payout_events          : append-only audit trail for payout lifecycle
     *                          changes.
     * financial_settlements  : immutable per-tournament financial snapshot
     *                          (gross/refunds/net/pool/allocated/completed/
     *                          revenue/adjustments + reconciliation result +
     *                          finalizer). Written once at finalization.
     * settlement_adjustments : approved manual correction/reversal records
     *                          (signed minor units) used instead of mutating
     *                          historical financial values.
     *
     * All monetary columns are integer minor units (BDT poisha); there is no
     * floating-point money. No Phase 01–08 table or column is modified or
     * dropped.
     */
    public function up(): void
    {
        Schema::create('prize_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position'); // 1-based rank (1st, 2nd, …)
            $table->string('type', 16);          // fixed | percentage
            $table->unsignedBigInteger('amount_minor')->nullable();   // fixed poisha
            $table->unsignedInteger('percentage_bp')->nullable();     // basis points
            $table->timestamps();

            $table->unique(['tournament_id', 'position'], 'prize_tiers_tournament_position_unique');
        });

        Schema::create('prize_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft'); // draft|calculated|approved|processing|completed|failed|cancelled
            $table->unsignedBigInteger('pool_minor')->default(0);            // pool snapshot at calculation
            $table->unsignedBigInteger('total_allocated_minor')->default(0); // sum of snapshot items
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'prize_distributions_idem_unique');
            $table->index('tournament_id', 'prize_distributions_tournament_index');
            $table->index('status', 'prize_distributions_status_index');
        });

        Schema::create('prize_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('prize_distributions')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');          // rank
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('type', 16);                   // fixed | percentage (as configured)
            $table->unsignedBigInteger('amount_minor');   // resolved amount (poisha)
            $table->timestamp('created_at')->nullable();

            $table->unique(['distribution_id', 'position'], 'prize_snapshot_distribution_position_unique');
            $table->index('tournament_id', 'prize_snapshot_tournament_index');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('prize_distributions')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('BDT');
            $table->string('status', 16)->default('pending'); // pending|approved|processing|completed|failed|cancelled
            $table->string('payout_method', 16)->default('wallet'); // wallet | manual
            $table->string('provider', 30)->default('wallet');
            $table->string('provider_reference', 80)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['distribution_id', 'rank'], 'payouts_distribution_rank_unique');
            $table->unique('idempotency_key', 'payouts_idem_unique');
            $table->index('tournament_id', 'payouts_tournament_index');
            $table->index('recipient_user_id', 'payouts_recipient_index');
            $table->index('status', 'payouts_status_index');
        });

        Schema::create('payout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 8)->default('BDT');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payout_id', 'payout_events_payout_index');
            $table->index('event', 'payout_events_event_index');
        });

        Schema::create('financial_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('gross_collected_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('net_collected_minor')->default(0);
            $table->unsignedBigInteger('prize_pool_minor')->default(0);
            $table->unsignedBigInteger('allocated_prizes_minor')->default(0);
            $table->unsignedBigInteger('completed_payouts_minor')->default(0);
            $table->unsignedBigInteger('platform_revenue_minor')->default(0);
            $table->bigInteger('adjustments_minor')->default(0); // signed
            $table->string('reconciliation_status', 20); // balanced|underfunded|overallocated|mismatch
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('tournament_id', 'financial_settlements_tournament_unique');
        });

        Schema::create('settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor'); // signed: credit positive, debit negative
            $table->string('type', 16)->default('correction'); // correction | reversal
            $table->string('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('tournament_id', 'settlement_adjustments_tournament_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_adjustments');
        Schema::dropIfExists('financial_settlements');
        Schema::dropIfExists('payout_events');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('prize_snapshot_items');
        Schema::dropIfExists('prize_distributions');
        Schema::dropIfExists('prize_tiers');
    }
};
```

### FILE: config/finance.php
```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial settlement (Phase 09)
    |--------------------------------------------------------------------------
    |
    | Global financial configuration for prize distribution, payouts and
    | reconciliation. All monetary values are integer minor units (BDT
    | poisha) or basis points (1/100th of a percent) — never floats.
    |
    | Commission defaults to ZERO: no pre-existing commission business rule
    | exists in FF Arena, so no fee is silently introduced into existing or
    | new tournaments. Set PLATFORM_COMMISSION_* to enable a platform fee.
    |
    */

    'commission' => [
        // 'percentage' (basis points of net collections) or 'fixed' (poisha).
        'type' => env('PLATFORM_COMMISSION_TYPE', 'percentage'),

        // Percentage commission in basis points (10000 = 100%). Default 0.
        'percentage_bp' => (int) env('PLATFORM_COMMISSION_BP', 0),

        // Fixed commission in poisha (100 = ৳1.00). Default 0.
        'fixed_minor' => (int) env('PLATFORM_COMMISSION_FIXED_MINOR', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | The default payout provider for prize payouts.
    |  - 'wallet' : internal wallet credit (the only provider shipped today).
    |  - 'manual' : manually processed external payout (bank/bKash agent) —
    |               no external API is called and no success is faked.
    |
    */
    'default_payout_provider' => env('DEFAULT_PAYOUT_PROVIDER', 'wallet'),
];
```

### FILE: app/Models/PrizeTier.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable prize rule for one finishing rank of a tournament
 * (Phase 09).
 *
 * Either a fixed minor-unit amount (BDT poisha) or a percentage (stored in
 * integer basis points) of the declared prize pool. Rules are validated
 * server-side (no negative values, no duplicate positions, allocation never
 * exceeds the pool) and are snapshotted before distribution so later edits
 * never rewrite historical prizes.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class PrizeTier extends Model
{
    use HasFactory;

    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_PERCENTAGE,
    ];

    public const MAX_POSITION = 100;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'amount_minor' => 'integer',
        'percentage_bp' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function typeLabel(): string
    {
        return $this->isFixed() ? 'Fixed' : 'Percentage';
    }
}
```

### FILE: app/Models/PrizeDistribution.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The prize-distribution workflow for a tournament (Phase 09).
 *
 * A controlled state machine — a client can never move a distribution
 * between arbitrary states:
 *
 *   draft      → calculated, cancelled
 *   calculated → approved, cancelled, draft (recalculate)
 *   approved   → processing, cancelled
 *   processing → completed, failed
 *   completed  → (terminal)
 *   failed     → (terminal — a retry starts a fresh distribution)
 *   cancelled  → (terminal)
 *
 * The `pool_minor` and snapshot items recorded at calculation time are
 * immutable: later edits to the tournament prize pool, fees, tiers or scores
 * never rewrite an already-calculated distribution.
 */
class PrizeDistribution extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_CALCULATED, self::STATUS_CANCELLED],
        self::STATUS_CALCULATED => [self::STATUS_APPROVED, self::STATUS_CANCELLED, self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Statuses of the currently "live" (non-terminal) distribution attempt.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CALCULATED,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
    ];

    protected $fillable = [];

    protected $casts = [
        'pool_minor' => 'integer',
        'total_allocated_minor' => 'integer',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function snapshotItems()
    {
        return $this->hasMany(PrizeSnapshotItem::class, 'distribution_id')->orderBy('position');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class, 'distribution_id')->orderBy('rank');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return ! in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CALCULATED => 'Calculated',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Draft',
        };
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_APPROVED, self::STATUS_PROCESSING => 'live',
            self::STATUS_CALCULATED => 'ready',
            default => 'pending',
        };
    }
}
```

### FILE: app/Models/PrizeSnapshotItem.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable rank → team → amount row captured when a prize distribution
 * is calculated (Phase 09).
 *
 * The resolved amount (integer poisha) is frozen here so historical prize
 * calculations never change when the tournament prize pool, tiers, scoring,
 * leaderboard or fees are later edited. Rows are only created by
 * PrizeDistributionService and are never updated or deleted by the app.
 */
class PrizeSnapshotItem extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'amount_minor' => 'integer',
    ];

    public function distribution()
    {
        return $this->belongsTo(PrizeDistribution::class, 'distribution_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
```

### FILE: app/Models/Payout.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A prize payout record for one awarded rank (Phase 09).
 *
 * The recipient (user/team), rank and amount are ALWAYS derived server-side
 * from the prize snapshot and final standings — never from client input.
 *
 * Controlled state machine:
 *
 *   pending    → approved, cancelled
 *   approved   → processing, cancelled
 *   processing → completed, failed
 *   completed  → (terminal)
 *   failed     → (terminal)
 *   cancelled  → (terminal)
 *
 * Internal wallet payouts credit the recipient's wallet through the
 * Phase 08 WalletService (with a matching ledger entry) inside the same
 * transaction that marks the payout completed, so a payout can never be
 * "completed but not credited" or vice versa. A payout is never processed
 * twice (unique idempotency key + unique (distribution, rank)).
 */
class Payout extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Payout methods. `wallet` credits the recipient's internal wallet;
     * `manual` is a manually processed external payout (never faked).
     */
    public const METHOD_WALLET = 'wallet';
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [];

    protected $casts = [
        'rank' => 'integer',
        'amount_minor' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function distribution()
    {
        return $this->belongsTo(PrizeDistribution::class, 'distribution_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'recipient_team_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function events()
    {
        return $this->hasMany(PayoutEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isInternal(): bool
    {
        return $this->provider === self::METHOD_WALLET;
    }

    public function amountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_APPROVED, self::STATUS_PROCESSING => 'live',
            default => 'pending',
        };
    }
}
```

### FILE: app/Models/PayoutEvent.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for payout lifecycle changes (approved, processing,
 * completed, failed, cancelled) (Phase 09).
 *
 * No passwords, API secrets, card numbers or unnecessary credentials are
 * ever stored. Rows are written only by PayoutService.
 */
class PayoutEvent extends Model
{
    use HasFactory;

    public const EVENT_APPROVED = 'payout.approved';
    public const EVENT_PROCESSING = 'payout.processing';
    public const EVENT_COMPLETED = 'payout.completed';
    public const EVENT_FAILED = 'payout.failed';
    public const EVENT_CANCELLED = 'payout.cancelled';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'metadata' => 'array',
    ];

    public function payout()
    {
        return $this->belongsTo(Payout::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

### FILE: app/Models/FinancialSettlement.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable per-tournament financial snapshot (Phase 09).
 *
 * Written once when a prize distribution is finalized (completed). It
 * preserves gross collection, refunds, net collection, prize pool, allocated
 * prizes, completed payouts, platform revenue, adjustments, the
 * reconciliation result and the finalizing admin/timestamp.
 *
 * After finalization these values are never edited in place — corrections
 * are represented by SettlementAdjustment records, never by mutating the
 * snapshot.
 */
class FinancialSettlement extends Model
{
    use HasFactory;

    public const STATUS_BALANCED = 'balanced';
    public const STATUS_UNDERFUNDED = 'underfunded';
    public const STATUS_OVERALLOCATED = 'overallocated';
    public const STATUS_MISMATCH = 'mismatch';

    public const STATUSES = [
        self::STATUS_BALANCED,
        self::STATUS_UNDERFUNDED,
        self::STATUS_OVERALLOCATED,
        self::STATUS_MISMATCH,
    ];

    protected $fillable = [];

    protected $casts = [
        'gross_collected_minor' => 'integer',
        'refunded_minor' => 'integer',
        'net_collected_minor' => 'integer',
        'prize_pool_minor' => 'integer',
        'allocated_prizes_minor' => 'integer',
        'completed_payouts_minor' => 'integer',
        'platform_revenue_minor' => 'integer',
        'adjustments_minor' => 'integer',
        'finalized_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function statusLabel(): string
    {
        return ucfirst($this->reconciliation_status);
    }

    public function statusPill(): string
    {
        return match ($this->reconciliation_status) {
            self::STATUS_BALANCED => 'confirmed',
            self::STATUS_OVERALLOCATED => 'failed',
            self::STATUS_UNDERFUNDED, self::STATUS_MISMATCH => 'pending',
            default => 'draft',
        };
    }
}
```

### FILE: app/Models/SettlementAdjustment.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved manual correction/reversal to a tournament's financial
 * settlement (Phase 09).
 *
 * A signed integer minor-unit amount (positive credit, negative debit) with a
 * mandatory reason and the acting admin. Adjustments flow into the
 * reconciliation math and are frozen into the FinancialSettlement snapshot.
 *
 * Append-only: rows are never edited after creation, and no adjustments are
 * accepted once a settlement has been finalized.
 */
class SettlementAdjustment extends Model
{
    use HasFactory;

    public const TYPE_CORRECTION = 'correction';
    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [
        self::TYPE_CORRECTION,
        self::TYPE_REVERSAL,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

### FILE: app/Contracts/PayoutGatewayInterface.php
```php
<?php

namespace App\Contracts;

use App\Models\Payout;
use DomainException;

/**
 * Provider abstraction for prize payout execution (Phase 09).
 *
 * The application never fakes a successful external payout: adapters must
 * honestly report what they can and cannot do. Today the only shipped
 * provider is the internal wallet; external (manual) processing is supported
 * as a safe pending/manual workflow with no invented success.
 */
interface PayoutGatewayInterface
{
    /**
     * The stable provider identifier (stored on payouts.provider).
     */
    public function id(): string;

    /**
     * Whether this provider settles payouts internally (credits the
     * recipient's wallet via WalletService). Internal payouts complete
     * atomically during processing and need no manual confirmation.
     */
    public function isInternal(): bool;

    /**
     * Whether this provider can execute external (out-of-platform)
     * disbursements.
     */
    public function supportsExternal(): bool;

    /**
     * Execute an external disbursement for the given payout.
     *
     * @return array{status: string, provider_reference: ?string}
     *
     * @throws DomainException when external disbursement is not supported
     *                          (e.g. no live credentials or manual flow).
     */
    public function disburseExternal(Payout $payout): array;
}
```

### FILE: app/Gateways/WalletPayoutGateway.php
```php
<?php

namespace App\Gateways;

use App\Contracts\PayoutGatewayInterface;
use App\Models\Payout;
use DomainException;

/**
 * Internal wallet payout adapter (Phase 09).
 *
 * Prizes are settled by crediting the recipient's wallet through
 * WalletService (which writes the immutable ledger entry) inside the payout
 * transaction. There is no external network call, so nothing here can fail
 * part-way and no external success is ever invented.
 */
class WalletPayoutGateway implements PayoutGatewayInterface
{
    public function id(): string
    {
        return 'wallet';
    }

    public function isInternal(): bool
    {
        return true;
    }

    public function supportsExternal(): bool
    {
        return false;
    }

    public function disburseExternal(Payout $payout): array
    {
        throw new DomainException('The wallet provider settles payouts internally and has no external disbursement step.');
    }
}
```

### FILE: app/Gateways/ManualPayoutGateway.php
```php
<?php

namespace App\Gateways;

use App\Contracts\PayoutGatewayInterface;
use App\Models\Payout;
use DomainException;

/**
 * Manual payout adapter (Phase 09).
 *
 * For payouts settled outside the platform (bank transfer, bKash Send Money
 * by an agent, etc.) where no API integration exists. The application never
 * fakes a successful external transfer: payouts stay in `processing` until an
 * admin manually marks them completed (recording the external reference).
 */
class ManualPayoutGateway implements PayoutGatewayInterface
{
    public function id(): string
    {
        return 'manual';
    }

    public function isInternal(): bool
    {
        return false;
    }

    public function supportsExternal(): bool
    {
        return false;
    }

    public function disburseExternal(Payout $payout): array
    {
        throw new DomainException('Manual payouts have no external API — complete them by hand and record the reference.');
    }
}
```

### FILE: app/Services/PayoutGatewayManager.php
```php
<?php

namespace App\Services;

use App\Contracts\PayoutGatewayInterface;
use App\Gateways\ManualPayoutGateway;
use App\Gateways\WalletPayoutGateway;
use DomainException;

/**
 * Resolves payout gateway adapters by provider id (Phase 09).
 */
class PayoutGatewayManager
{
    /**
     * Registered adapters, keyed by provider id.
     *
     * @var array<string, PayoutGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct(WalletPayoutGateway $wallet, ManualPayoutGateway $manual)
    {
        $this->gateways[$wallet->id()] = $wallet;
        $this->gateways[$manual->id()] = $manual;
    }

    /**
     * Resolve a gateway by provider id.
     */
    public function gateway(string $provider): PayoutGatewayInterface
    {
        if (! isset($this->gateways[$provider])) {
            throw new DomainException("Unknown payout provider: {$provider}");
        }

        return $this->gateways[$provider];
    }

    /**
     * The default provider id used for prize payouts.
     */
    public function defaultProvider(): string
    {
        return (string) config('finance.default_payout_provider', 'wallet');
    }
}
```

### FILE: app/Services/PayoutService.php
```php
<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Payout lifecycle + wallet integration (Phase 09).
 *
 * The single authority for payout state transitions and disbursement.
 * Internal (wallet) payouts credit the recipient's wallet through
 * WalletService inside the same transaction that marks the payout completed,
 * so the payout record and the wallet/ledger can never diverge. External
 * (manual) payouts move to `processing` and are completed by hand — no
 * external success is ever faked.
 *
 * Idempotency: processing an already-completed payout returns the current
 * state; the unique (distribution_id, rank) and idempotency-key constraints
 * are the race-condition backstops.
 */
class PayoutService
{
    public function __construct(
        protected WalletService $wallets,
        protected PayoutGatewayManager $gateways,
    ) {
    }

    /**
     * Process an approved payout.
     *
     *  - Internal wallet provider: credit the recipient's wallet (with the
     *    matching ledger entry) and mark the payout completed, atomically.
     *  - Manual provider: move to `processing`; an admin completes it by hand
     *    (completeManually).
     */
    public function process(Payout $payout, User $actor): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if (! $gateway->isInternal()) {
            return $this->advanceToProcessing($payout, $actor);
        }

        if ($payout->recipient_user_id === null) {
            throw new DomainException('This payout has no recipient.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            // Idempotency: a completed payout is simply reported back.
            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $recipient = $fresh->recipient;

            if ($recipient === null) {
                throw new DomainException('This payout has no recipient.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            // The wallet credit and its ledger entry commit atomically with
            // the payout state below — the two can never diverge.
            $wallet = $this->wallets->walletFor($recipient);

            $this->wallets->credit(
                $wallet,
                $fresh->amountMinor(),
                LedgerEntry::TYPE_PAYOUT,
                'Prize payout — ' . ($fresh->tournament?->name ?? 'Tournament') . ' (' . $this->ordinal((int) $fresh->rank) . ' place)',
                $actor,
                'payout',
                $fresh->id,
            );

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Manually complete an external (non-internal) payout, recording the
     * provider reference. Internal wallet payouts complete automatically
     * during process() and can never be completed by hand.
     */
    public function completeManually(Payout $payout, User $actor, ?string $reference = null): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if ($gateway->isInternal()) {
            throw new DomainException('Internal wallet payouts complete automatically during processing.');
        }

        return DB::transaction(function () use ($payout, $actor, $reference) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PROCESSING) {
                throw new DomainException('Only processing payouts can be marked completed.');
            }

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();

            if ($reference !== null && trim($reference) !== '') {
                $fresh->provider_reference = trim($reference);
            }

            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor(), ['reference' => $reference]);

            return $fresh;
        });
    }

    /**
     * Approve a pending payout.
     */
    public function approve(Payout $payout, User $actor): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new DomainException('Only pending payouts can be approved.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_APPROVED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PENDING) {
                throw new DomainException('Only pending payouts can be approved.');
            }

            $fresh->status = Payout::STATUS_APPROVED;
            $fresh->approved_by = $actor->id;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_APPROVED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Mark a payout failed with a reason.
     */
    public function markFailed(Payout $payout, User $actor, string $reason = ''): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
            throw new DomainException('This payout cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor, $reason) {
            $payout->status = Payout::STATUS_FAILED;
            $payout->failure_reason = $reason !== '' ? $reason : null;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_FAILED, $payout->amountMinor(), ['reason' => $reason]);

            return $payout;
        });
    }

    /**
     * Cancel a payout that has not started paying out.
     */
    public function cancel(Payout $payout, User $actor): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true)) {
            throw new DomainException('This payout cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $payout->status = Payout::STATUS_CANCELLED;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_CANCELLED, $payout->amountMinor());

            return $payout;
        });
    }

    /**
     * Move an external payout into processing (no disbursement yet).
     */
    protected function advanceToProcessing(Payout $payout, User $actor): Payout
    {
        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_PROCESSING, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Append a row to the payout audit trail.
     */
    public function recordEvent(Payout $payout, ?User $actor, string $event, int $amountMinor, array $metadata = []): PayoutEvent
    {
        $record = new PayoutEvent();
        $record->payout_id = $payout->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    /**
     * English ordinal for display ("1st", "2nd", "3rd", "4th", …).
     */
    protected function ordinal(int $rank): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($rank % 100) >= 11 && ($rank % 100) <= 13) {
            return $rank . 'th';
        }

        return $rank . $suffixes[$rank % 10];
    }
}
```

### FILE: app/Services/ReconciliationService.php
```php
<?php

namespace App\Services;

use App\Models\FinancialSettlement;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\SettlementAdjustment;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tournament financial reconciliation (Phase 09).
 *
 * Computes, from source records only:
 *
 *   gross collected   = sum of successfully settled payments (incl. refunded)
 *   refunded          = sum of refunded payments
 *   net collected     = gross − refunded
 *   prize pool        = declared pool (tournament.prize_pool)
 *   allocated prizes  = frozen snapshot allocation
 *   completed payouts = sum of completed payouts
 *   platform revenue  = configured commission (default zero)
 *   adjustments       = approved correction/reversal records
 *   remaining         = net − (allocated + revenue + adjustments)
 *
 * and classifies the result as balanced / underfunded / overallocated /
 * mismatch. Discrepancies are surfaced, never hidden.
 */
class ReconciliationService
{
    /**
     * @return array{
     *   gross_collected_minor:int, refunded_minor:int, net_collected_minor:int,
     *   prize_pool_minor:int, allocated_prizes_minor:int,
     *   completed_payouts_minor:int, platform_revenue_minor:int,
     *   adjustments_minor:int, remaining_minor:int, reconciliation_status:string
     * }
     */
    public function summary(Tournament $tournament): array
    {
        $gross = (int) Payment::where('tournament_id', $tournament->id)
            ->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_VERIFIED, Payment::STATUS_REFUNDED])
            ->sum('amount_minor');

        $refunded = (int) Payment::where('tournament_id', $tournament->id)
            ->where('status', Payment::STATUS_REFUNDED)
            ->sum('amount_minor');

        $net = $gross - $refunded;
        $pool = $tournament->prizePoolMinor();

        $distribution = PrizeDistribution::where('tournament_id', $tournament->id)->orderByDesc('id')->first();

        $allocated = $distribution === null
            ? 0
            : (int) $distribution->snapshotItems()->sum('amount_minor');

        $completed = (int) Payout::where('tournament_id', $tournament->id)
            ->where('status', Payout::STATUS_COMPLETED)
            ->sum('amount_minor');

        $revenue = $this->platformRevenueMinor($net);
        $adjustments = (int) SettlementAdjustment::where('tournament_id', $tournament->id)->sum('amount_minor');

        $remaining = $net - $allocated - $revenue - $adjustments;

        $status = $this->status([
            'allocated' => $allocated,
            'pool' => $pool,
            'net' => $net,
            'revenue' => $revenue,
            'adjustments' => $adjustments,
            'completed' => $completed,
        ], $distribution);

        return [
            'gross_collected_minor' => $gross,
            'refunded_minor' => $refunded,
            'net_collected_minor' => $net,
            'prize_pool_minor' => $pool,
            'allocated_prizes_minor' => $allocated,
            'completed_payouts_minor' => $completed,
            'platform_revenue_minor' => $revenue,
            'adjustments_minor' => $adjustments,
            'remaining_minor' => $remaining,
            'reconciliation_status' => $status,
        ];
    }

    /**
     * The platform commission on the net collected amount (integer poisha).
     *
     * Percentage commission is applied in basis points; fixed commission is
     * capped at the eligible revenue so commission can never exceed it.
     */
    public function platformRevenueMinor(int $netMinor): int
    {
        $type = (string) config('finance.commission.type', 'percentage');

        if ($type === 'fixed') {
            $fixed = max(0, (int) config('finance.commission.fixed_minor', 0));

            return min($fixed, max(0, $netMinor));
        }

        $basisPoints = max(0, (int) config('finance.commission.percentage_bp', 0));

        return intdiv(max(0, $netMinor) * $basisPoints, 10000);
    }

    /**
     * Freeze the current summary into an immutable FinancialSettlement.
     * Idempotent: an existing settlement for the tournament is returned
     * unchanged — historical values are never overwritten.
     */
    public function finalize(Tournament $tournament, User $admin, PrizeDistribution $distribution): FinancialSettlement
    {
        $existing = FinancialSettlement::where('tournament_id', $tournament->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $s = $this->summary($tournament);

        return DB::transaction(function () use ($tournament, $admin, $s) {
            $existing = FinancialSettlement::where('tournament_id', $tournament->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $settlement = new FinancialSettlement();
            $settlement->tournament_id = $tournament->id;
            $settlement->gross_collected_minor = $s['gross_collected_minor'];
            $settlement->refunded_minor = $s['refunded_minor'];
            $settlement->net_collected_minor = $s['net_collected_minor'];
            $settlement->prize_pool_minor = $s['prize_pool_minor'];
            $settlement->allocated_prizes_minor = $s['allocated_prizes_minor'];
            $settlement->completed_payouts_minor = $s['completed_payouts_minor'];
            $settlement->platform_revenue_minor = $s['platform_revenue_minor'];
            $settlement->adjustments_minor = $s['adjustments_minor'];
            $settlement->reconciliation_status = $s['reconciliation_status'];
            $settlement->finalized_by = $admin->id;
            $settlement->finalized_at = now();
            $settlement->save();

            return $settlement;
        });
    }

    /**
     * Record an approved manual adjustment (signed poisha). Blocked once the
     * tournament's settlement has been finalized.
     */
    public function addAdjustment(Tournament $tournament, int $amountMinor, string $type, string $reason, User $admin): SettlementAdjustment
    {
        if (FinancialSettlement::where('tournament_id', $tournament->id)->exists()) {
            throw new DomainException('This tournament has already been finalized; adjustments are frozen.');
        }

        if ($amountMinor === 0) {
            throw new DomainException('An adjustment amount is required.');
        }

        if (! in_array($type, SettlementAdjustment::TYPES, true)) {
            throw new DomainException('Adjustment type must be correction or reversal.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required for an adjustment.');
        }

        $adjustment = new SettlementAdjustment();
        $adjustment->tournament_id = $tournament->id;
        $adjustment->amount_minor = $amountMinor;
        $adjustment->type = $type;
        $adjustment->reason = $reason;
        $adjustment->actor_id = $admin->id;
        $adjustment->save();

        return $adjustment;
    }

    /**
     * Classify the reconciliation state.
     *
     *  - overallocated: allocated prizes exceed the declared prize pool.
     *  - underfunded  : net collection cannot cover allocation + commission
     *                   + adjustments.
     *  - mismatch     : a distribution exists but payouts are incomplete or
     *                   not finalized, or completed payouts differ from the
     *                   allocation.
     *  - balanced     : otherwise.
     *
     * @param array{allocated:int,pool:int,net:int,revenue:int,adjustments:int,completed:int} $s
     */
    protected function status(array $s, ?PrizeDistribution $distribution): string
    {
        if ($s['allocated'] > $s['pool']) {
            return FinancialSettlement::STATUS_OVERALLOCATED;
        }

        if ($s['net'] < $s['allocated'] + $s['revenue'] + $s['adjustments']) {
            return FinancialSettlement::STATUS_UNDERFUNDED;
        }

        if ($distribution !== null) {
            if (! $distribution->isCompleted() || $s['completed'] !== $s['allocated']) {
                return FinancialSettlement::STATUS_MISMATCH;
            }
        }

        return FinancialSettlement::STATUS_BALANCED;
    }
}
```

### FILE: app/Services/PrizeDistributionService.php
```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\GameMatch;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prize distribution workflow (Phase 09).
 *
 * Orchestrates prize-tier configuration, the draft → calculated → approved →
 * processing → completed/failed/cancelled state machine, the immutable prize
 * snapshot and the payout records. Final standings come exclusively from the
 * Phase 06 ScoringService; eligibility is gated on the Phase 05/07 lifecycle
 * and dispute state.
 *
 * Amounts are integer poisha throughout; percentage tiers resolve against the
 * frozen prize pool with integer arithmetic (no floats).
 */
class PrizeDistributionService
{
    public function __construct(
        protected ScoringService $scoring,
        protected PayoutService $payouts,
        protected ReconciliationService $reconciliation,
        protected PayoutGatewayManager $gateways,
    ) {
    }

    /**
     * The configured prize tiers for a tournament, ordered by rank.
     *
     * @return Collection<int, PrizeTier>
     */
    public function tiers(Tournament $tournament): Collection
    {
        return $tournament->prizeTiers()->orderBy('position')->get();
    }

    /**
     * The currently live (non-terminal) distribution, or null.
     */
    public function activeDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()
            ->whereIn('status', PrizeDistribution::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent distribution (any state), or null.
     */
    public function latestDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------------
    // Prize configuration
    // ------------------------------------------------------------------

    /**
     * Replace a tournament's prize tiers with a validated set.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     */
    public function saveTiers(Tournament $tournament, array $rows, User $admin): void
    {
        $this->assertTiersEditable($tournament);

        $pool = $tournament->prizePoolMinor();
        $normalized = $this->normalizeTiers($rows, $pool);

        DB::transaction(function () use ($tournament, $normalized) {
            $tournament->prizeTiers()->delete();

            foreach ($normalized as $row) {
                $tier = new PrizeTier();
                $tier->tournament_id = $tournament->id;
                $tier->position = $row['position'];
                $tier->type = $row['type'];
                $tier->amount_minor = $row['amount_minor'];
                $tier->percentage_bp = $row['percentage_bp'];
                $tier->save();
            }
        });
    }

    /**
     * Validate and normalise prize-tier rows into a deterministic,
     * position-ordered structure. Server-authoritative: rejects negative
     * amounts/percentages, duplicate positions, invalid positions, totals
     * over 100% and allocations that exceed the available prize pool.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     * @return array<int, array{position:int, type:string, amount_minor:?int, percentage_bp:?int}>
     */
    public function normalizeTiers(array $rows, int $pool): array
    {
        $seen = [];
        $normalized = [];
        $fixedSum = 0;
        $basisPointsSum = 0;

        foreach ($rows as $row) {
            $position = (int) ($row['position'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));

            // Blank rows are ignored (fixed-row forms submit empty slots).
            if ($value === '') {
                continue;
            }

            if ($position < 1 || $position > PrizeTier::MAX_POSITION) {
                throw new DomainException('Prize positions must be between 1 and ' . PrizeTier::MAX_POSITION . '.');
            }

            if (isset($seen[$position])) {
                throw new DomainException('Duplicate prize position: ' . $position . '.');
            }

            $seen[$position] = true;

            if (! in_array($type, PrizeTier::TYPES, true)) {
                throw new DomainException('Prize type must be fixed or percentage.');
            }

            if ($type === PrizeTier::TYPE_FIXED) {
                $amountMinor = Money::toMinor($value);

                if ($amountMinor <= 0) {
                    throw new DomainException('Fixed prize amounts must be positive.');
                }

                $fixedSum += $amountMinor;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => $amountMinor,
                    'percentage_bp' => null,
                ];
            } else {
                $basisPoints = Money::toBasisPoints($value);

                if ($basisPoints <= 0 || $basisPoints > 10000) {
                    throw new DomainException('Prize percentages must be between 0 and 100.');
                }

                $basisPointsSum += $basisPoints;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => null,
                    'percentage_bp' => $basisPoints,
                ];
            }
        }

        if ($normalized === []) {
            throw new DomainException('At least one prize tier is required.');
        }

        usort($normalized, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        if ($basisPointsSum > 10000) {
            throw new DomainException('Prize percentages cannot total more than 100%.');
        }

        $total = $fixedSum;

        foreach ($normalized as $row) {
            if ($row['type'] === PrizeTier::TYPE_PERCENTAGE) {
                $total += intdiv($pool * $row['percentage_bp'], 10000);
            }
        }

        if ($total > $pool) {
            throw new DomainException(
                'The configured prize allocation exceeds the available prize pool (৳' . Money::toDecimal($pool) . ').'
            );
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Distribution workflow
    // ------------------------------------------------------------------

    /**
     * Calculate the prize distribution: snapshot the tiers and the final
     * standings into immutable rows and move to `calculated`.
     *
     * Idempotent: re-running on an already-calculated distribution returns it
     * unchanged. A draft distribution is recomputed in place.
     */
    public function calculate(Tournament $tournament, User $admin): PrizeDistribution
    {
        $this->assertEligible($tournament);

        return DB::transaction(function () use ($tournament, $admin) {
            // Serialize concurrent calculations against the tournament row.
            Tournament::query()->where('id', $tournament->id)->lockForUpdate()->first();

            $active = $this->activeDistribution($tournament);

            if ($active === null) {
                if ($tournament->prizeDistributions()->where('status', PrizeDistribution::STATUS_COMPLETED)->exists()) {
                    throw new DomainException('This tournament has already been settled.');
                }

                $active = new PrizeDistribution();
                $active->tournament_id = $tournament->id;
                $active->status = PrizeDistribution::STATUS_DRAFT;
                $active->created_by = $admin->id;
                $active->idempotency_key = (string) Str::uuid();
                $active->save();
            } elseif ($active->status !== PrizeDistribution::STATUS_DRAFT) {
                // Already calculated/approved/processing — idempotent no-op.
                return $active;
            }

            $tiers = $this->tiers($tournament);

            if ($tiers->isEmpty()) {
                throw new DomainException('No prize tiers configured. Configure prizes first.');
            }

            $standings = $this->scoring->standings($tournament);

            if ($standings->isEmpty()) {
                throw new DomainException('Final standings are not available for this tournament.');
            }

            $pool = $tournament->prizePoolMinor();
            $tierByPosition = $tiers->keyBy('position');
            $maxPosition = (int) $tiers->max('position');

            // Recalculate a draft in place (snapshot rows are replaced).
            $active->snapshotItems()->delete();

            $total = 0;

            foreach ($standings as $row) {
                $rank = (int) $row->rank;

                if ($rank > $maxPosition) {
                    break;
                }

                $tier = $tierByPosition->get($rank);

                if ($tier === null) {
                    continue;
                }

                $team = $row->team;

                if (! $team instanceof Team || $team->captain_id === null) {
                    throw new DomainException('Ranked team #' . $rank . ' has no captain to receive the prize.');
                }

                $amountMinor = $this->resolveAmount($tier, $pool);

                $item = new PrizeSnapshotItem();
                $item->distribution_id = $active->id;
                $item->tournament_id = $tournament->id;
                $item->position = $rank;
                $item->team_id = $team->id;
                $item->type = $tier->type;
                $item->amount_minor = $amountMinor;
                $item->save();

                $total += $amountMinor;
            }

            $active->pool_minor = $pool;
            $active->total_allocated_minor = $total;
            $active->status = PrizeDistribution::STATUS_CALCULATED;
            $active->save();

            return $active;
        });
    }

    /**
     * Approve a calculated distribution, creating the payout records from the
     * snapshot. Idempotent on an already-approved distribution.
     */
    public function approve(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists. Calculate it first.');
        }

        if (in_array($distribution->status, [
            PrizeDistribution::STATUS_APPROVED,
            PrizeDistribution::STATUS_PROCESSING,
            PrizeDistribution::STATUS_COMPLETED,
        ], true)) {
            return $distribution;
        }

        if ($distribution->status !== PrizeDistribution::STATUS_CALCULATED) {
            throw new DomainException('Only a calculated distribution can be approved.');
        }

        return DB::transaction(function () use ($distribution, $admin) {
            $items = $distribution->snapshotItems()->with('team')->orderBy('position')->get();

            if ($items->isEmpty()) {
                throw new DomainException('No prizes were allocated; nothing to approve.');
            }

            $provider = $this->gateways->defaultProvider();

            foreach ($items as $item) {
                if (Payout::where('distribution_id', $distribution->id)->where('rank', $item->position)->exists()) {
                    continue;
                }

                $payout = new Payout();
                $payout->distribution_id = $distribution->id;
                $payout->tournament_id = $distribution->tournament_id;
                $payout->recipient_team_id = $item->team_id;
                $payout->recipient_user_id = $item->team?->captain_id;
                $payout->rank = $item->position;
                $payout->amount_minor = $item->amount_minor;
                $payout->currency = 'BDT';
                $payout->status = Payout::STATUS_APPROVED;
                $payout->payout_method = $provider === 'wallet' ? Payout::METHOD_WALLET : Payout::METHOD_MANUAL;
                $payout->provider = $provider;
                $payout->idempotency_key = (string) Str::uuid();
                $payout->approved_by = $admin->id;
                $payout->save();

                $this->payouts->recordEvent($payout, $admin, \App\Models\PayoutEvent::EVENT_APPROVED, $payout->amountMinor());
            }

            $distribution->status = PrizeDistribution::STATUS_APPROVED;
            $distribution->approved_by = $admin->id;
            $distribution->approved_at = now();
            $distribution->save();

            return $distribution;
        });
    }

    /**
     * Process an approved distribution: disburse every payout, then complete
     * the distribution and freeze the financial settlement.
     *
     * Idempotent: a completed distribution is returned unchanged; a
     * `processing` distribution resumes its remaining payouts. If a payout
     * fails, the payout and the distribution are marked failed.
     */
    public function process(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            // Idempotency: an already-completed distribution is returned.
            $latest = $this->latestDistribution($tournament);

            if ($latest !== null && $latest->status === PrizeDistribution::STATUS_COMPLETED) {
                return $latest;
            }

            throw new DomainException('No prize distribution exists.');
        }

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {
            return $distribution;
        }

        if ($distribution->status === PrizeDistribution::STATUS_PROCESSING) {
            // Resume — fall through to process remaining payouts.
        } elseif ($distribution->status === PrizeDistribution::STATUS_APPROVED) {
            $distribution->status = PrizeDistribution::STATUS_PROCESSING;
            $distribution->save();
        } else {
            throw new DomainException('Only an approved distribution can be processed.');
        }

        $remaining = $distribution->payouts()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
            ->orderBy('rank')
            ->get();

        foreach ($remaining as $payout) {
            try {
                $this->payouts->process($payout, $admin);
            } catch (DomainException $e) {
                $this->payouts->markFailed($payout, $admin, $e->getMessage());

                DB::transaction(function () use ($distribution, $e) {
                    $distribution->status = PrizeDistribution::STATUS_FAILED;
                    $distribution->failure_reason = 'A payout failed: ' . $e->getMessage();
                    $distribution->save();
                });

                return $distribution;
            }
        }

        // Internal (wallet) payouts complete during processing; manual payouts
        // stay in `processing` until an admin marks them completed by hand.
        $unfinished = $distribution->payouts()
            ->where('status', '!=', Payout::STATUS_COMPLETED)
            ->exists();

        if ($unfinished) {
            return $distribution;
        }

        DB::transaction(function () use ($distribution, $tournament, $admin) {
            $distribution->status = PrizeDistribution::STATUS_COMPLETED;
            $distribution->completed_at = now();
            $distribution->save();

            $this->reconciliation->finalize($tournament, $admin, $distribution);
        });

        return $distribution;
    }

    /**
     * Cancel a distribution that has not started paying out, cancelling its
     * not-yet-terminal payouts.
     */
    public function cancel(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists.');
        }

        if (! in_array($distribution->status, [
            PrizeDistribution::STATUS_DRAFT,
            PrizeDistribution::STATUS_CALCULATED,
            PrizeDistribution::STATUS_APPROVED,
        ], true)) {
            throw new DomainException('This distribution cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($distribution) {
            $distribution->payouts()
                ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
                ->update(['status' => Payout::STATUS_CANCELLED]);

            $distribution->status = PrizeDistribution::STATUS_CANCELLED;
            $distribution->save();

            return $distribution;
        });
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /**
     * Assert that a tournament is eligible for prize distribution:
     * finished, not cancelled, all matches resolved (no live/pending/disputed
     * matches) and no actionable disputes. Standings availability is checked
     * in calculate().
     */
    public function assertEligible(Tournament $tournament): void
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED) {
            throw new DomainException('Prize distribution requires a finished tournament.');
        }

        $unresolved = $tournament->matches()
            ->whereNotIn('status', [
                GameMatch::STATUS_COMPLETED,
                GameMatch::STATUS_BYE,
                GameMatch::STATUS_CANCELLED,
            ])
            ->exists();

        if ($unresolved) {
            throw new DomainException('All matches must be completed before prize distribution.');
        }

        if ($tournament->disputes()->whereIn('status', Dispute::ACTIONABLE_STATUSES)->exists()) {
            throw new DomainException('Unresolved disputes must be resolved before prize distribution.');
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Resolve a tier into an integer minor-unit amount against the pool.
     */
    protected function resolveAmount(PrizeTier $tier, int $pool): int
    {
        if ($tier->type === PrizeTier::TYPE_FIXED) {
            return (int) $tier->amount_minor;
        }

        return intdiv($pool * (int) $tier->percentage_bp, 10000);
    }

    /**
     * Prize tiers can only be edited while there is no active distribution or
     * the active distribution is still a draft. Once calculated, the snapshot
     * is authoritative and the tiers are locked.
     */
    protected function assertTiersEditable(Tournament $tournament): void
    {
        $active = $this->activeDistribution($tournament);

        if ($active !== null && $active->status !== PrizeDistribution::STATUS_DRAFT) {
            throw new DomainException('Prize tiers are locked once the distribution is calculated. Cancel it to reconfigure.');
        }
    }
}
```

### FILE: app/Policies/PrizeDistributionPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Prize distribution is a platform financial operation. Only admins may
 * view, configure, calculate, approve, process or cancel a distribution —
 * tournament organizers and players have no access, matching the Phase 08
 * rule that only admins verify/refund payments.
 */
class PrizeDistributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function configure(User $user): bool
    {
        return $user->isAdmin();
    }

    public function calculate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function process(User $user): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user): bool
    {
        return $user->isAdmin();
    }

    public function adjust(User $user): bool
    {
        return $user->isAdmin();
    }
}
```

### FILE: app/Policies/PrizeTierPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Prize-tier configuration is admin-only (Phase 09).
 */
class PrizeTierPolicy
{
    public function configure(User $user): bool
    {
        return $user->isAdmin();
    }
}
```

### FILE: app/Policies/PayoutPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

/**
 * Payout authorization (Phase 09).
 *
 * Only admins may list and act on payouts. A recipient may view their own
 * payout history; nobody may view another user's payouts.
 */
class PayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Payout $payout): bool
    {
        return $user->isAdmin() || $payout->recipient_user_id === $user->id;
    }

    public function approve(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function process(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function complete(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function fail(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }
}
```

### FILE: app/Policies/FinancialSettlementPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Financial settlement snapshots are admin-only (Phase 09).
 */
class FinancialSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function finalize(User $user): bool
    {
        return $user->isAdmin();
    }
}
```

### FILE: app/Http/Controllers/SettlementController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\PrizeDistribution;
use App\Models\Tournament;
use App\Services\PrizeDistributionService;
use App\Services\ReconciliationService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin financial settlement (Phase 09).
 *
 * Prize configuration, distribution lifecycle, reconciliation and
 * finalization. Every route sits behind the `admin` middleware AND calls the
 * PrizeDistributionPolicy/FinancialSettlementPolicy, so access is
 * object-level — never just a generic admin gate.
 */
class SettlementController extends Controller
{
    public function __construct(
        protected PrizeDistributionService $distributions,
        protected ReconciliationService $reconciliation,
    ) {
    }

    /**
     * List tournaments that have a settlement (finished, or with an existing
     * distribution) with their reconciliation status.
     */
    public function index()
    {
        $this->authorize('viewAny', PrizeDistribution::class);

        $tournaments = Tournament::query()
            ->where(function ($q) {
                $q->where('status', Tournament::STATUS_FINISHED)
                    ->orWhereHas('prizeDistributions');
            })
            ->with('financialSettlement')
            ->withCount(['prizeDistributions', 'payouts'])
            ->orderByDesc('created_at')
            ->paginate(25);

        $summaries = [];

        foreach ($tournaments as $tournament) {
            $summaries[$tournament->id] = $this->reconciliation->summary($tournament);
        }

        return view('admin.settlements', compact('tournaments', 'summaries'));
    }

    /**
     * The settlement detail page for one tournament: reconciliation, prize
     * tiers, distribution, snapshot, payouts and adjustments.
     */
    public function show(Tournament $tournament)
    {
        $this->authorize('viewAny', PrizeDistribution::class);

        $tiers = $this->distributions->tiers($tournament);
        $distribution = $this->distributions->latestDistribution($tournament);
        $snapshot = $distribution?->snapshotItems()->with('team')->orderBy('position')->get();
        $payouts = $tournament->payouts()
            ->with(['recipient', 'team', 'processedBy', 'approvedBy'])
            ->orderBy('rank')
            ->get();
        $summary = $this->reconciliation->summary($tournament);
        $settlement = $tournament->financialSettlement;
        $adjustments = $tournament->settlementAdjustments()->with('actor')->orderBy('id')->get();

        $tiersEditable = true;
        $active = $this->distributions->activeDistribution($tournament);

        if ($active !== null && $active->status !== PrizeDistribution::STATUS_DRAFT) {
            $tiersEditable = false;
        }

        return view('admin.settlement', compact(
            'tournament',
            'tiers',
            'distribution',
            'snapshot',
            'payouts',
            'summary',
            'settlement',
            'adjustments',
            'tiersEditable',
            'active',
        ));
    }

    /**
     * Replace the tournament's prize tiers.
     */
    public function storePrizeTiers(Request $request, Tournament $tournament)
    {
        $this->authorize('configure', PrizeDistribution::class);

        $rows = [];

        foreach ((array) $request->input('tiers', []) as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $position = $tier['position'] ?? null;
            $value = $tier['value'] ?? null;

            if ($position === null || trim((string) $value) === '') {
                continue;
            }

            $rows[] = [
                'position' => (int) $position,
                'type' => (string) ($tier['type'] ?? ''),
                'value' => (string) $value,
            ];
        }

        try {
            $this->distributions->saveTiers($tournament, $rows, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Prize configuration saved.');
    }

    /**
     * Calculate the prize distribution (snapshot tiers + final standings).
     */
    public function calculate(Tournament $tournament)
    {
        $this->authorize('calculate', PrizeDistribution::class);

        try {
            $distribution = $this->distributions->calculate($tournament, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Prize distribution calculated (' . Money::formatMinor($distribution->total_allocated_minor) . ' allocated).');
    }

    /**
     * Approve the calculated distribution (creates payout records).
     */
    public function approve(Tournament $tournament)
    {
        $this->authorize('approve', PrizeDistribution::class);

        try {
            $this->distributions->approve($tournament, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Prize distribution approved. Payouts created.');
    }

    /**
     * Process the approved distribution (disburse payouts + finalize).
     */
    public function process(Tournament $tournament)
    {
        $this->authorize('process', PrizeDistribution::class);

        try {
            $distribution = $this->distributions->process($tournament, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {
            return back()->with('success', 'Prize distribution completed and settlement finalized.');
        }

        return back()->with('error', 'Prize distribution failed: ' . ($distribution->failure_reason ?? 'unknown error'));
    }

    /**
     * Cancel a not-yet-processed distribution.
     */
    public function cancel(Tournament $tournament)
    {
        $this->authorize('cancel', PrizeDistribution::class);

        try {
            $this->distributions->cancel($tournament, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Prize distribution cancelled.');
    }

    /**
     * Record an approved manual adjustment (pre-finalization only).
     */
    public function adjust(Request $request, Tournament $tournament)
    {
        $this->authorize('adjust', PrizeDistribution::class);

        $data = $request->validate([
            'amount' => 'required|string|regex:/^-?\d+(\.\d{1,2})?$/',
            'type' => 'required|in:correction,reversal',
            'reason' => 'required|string|max:255',
        ]);

        $negative = str_starts_with($data['amount'], '-');
        $raw = ltrim($data['amount'], '-');

        try {
            $minor = Money::toMinor($raw);
            $minor = $negative ? -$minor : $minor;

            $this->reconciliation->addAdjustment($tournament, $minor, $data['type'], $data['reason'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Financial adjustment recorded.');
    }
}
```

### FILE: app/Http/Controllers/PayoutController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Tournament;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin payout management (Phase 09).
 *
 * List payouts and drive the payout state machine. All routes sit behind the
 * `admin` middleware and call the PayoutPolicy.
 */
class PayoutController extends Controller
{
    public function __construct(
        protected PayoutService $payouts,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payout::class);

        $payouts = Payout::query()
            ->with(['tournament', 'recipient', 'team', 'processedBy'])
            ->orderByDesc('created_at');

        $status = $request->query('status');

        if ($status !== null && $status !== '') {
            $payouts->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');

        if ($tournamentId > 0) {
            $payouts->where('tournament_id', $tournamentId);
        }

        $payouts = $payouts->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        $statuses = [
            Payout::STATUS_PENDING,
            Payout::STATUS_APPROVED,
            Payout::STATUS_PROCESSING,
            Payout::STATUS_COMPLETED,
            Payout::STATUS_FAILED,
            Payout::STATUS_CANCELLED,
        ];

        return view('admin.payouts', compact('payouts', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    public function approve(Payout $payout)
    {
        $this->authorize('approve', $payout);

        try {
            $this->payouts->approve($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout approved.');
    }

    public function process(Payout $payout)
    {
        $this->authorize('process', $payout);

        try {
            $this->payouts->process($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout processed.');
    }

    public function complete(Request $request, Payout $payout)
    {
        $this->authorize('complete', $payout);

        $reference = (string) $request->input('reference', '');

        try {
            $this->payouts->completeManually($payout, auth()->user(), $reference);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout marked completed.');
    }

    public function fail(Request $request, Payout $payout)
    {
        $this->authorize('fail', $payout);

        $reason = (string) $request->input('reason', '');

        try {
            $this->payouts->markFailed($payout, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout marked failed.');
    }

    public function cancel(Payout $payout)
    {
        $this->authorize('cancel', $payout);

        try {
            $this->payouts->cancel($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout cancelled.');
    }
}
```

### FILE: resources/views/admin/settlements.blade.php
```blade
@extends('layouts.app')
@section('title', 'Settlements — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">🧾 Financial Settlements</h1>

    <div class="card">
        @if($tournaments->isEmpty())
            <p class="muted">No tournaments require settlement yet.</p>
        @else
            <table>
                <tr>
                    <th>Tournament</th>
                    <th>Status</th>
                    <th>Distribution</th>
                    <th>Net collected</th>
                    <th>Allocated</th>
                    <th>Reconciliation</th>
                    <th></th>
                </tr>
                @foreach($tournaments as $tournament)
                    @php $summary = $summaries[$tournament->id] ?? null; @endphp
                    <tr>
                        <td>
                            <strong>{{ $tournament->name }}</strong>
                            <div class="muted" style="font-size:12px">{{ $tournament->slug }}</div>
                        </td>
                        <td><span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span></td>
                        <td>
                            @if($tournament->financialSettlement)
                                <span class="pill confirmed">FINALIZED</span>
                            @else
                                <span class="pill pending">PENDING</span>
                            @endif
                        </td>
                        <td>৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</td>
                        <td>৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</td>
                        <td>
                            <span class="pill {{ $tournament->financialSettlement?->statusPill() ?? 'pending' }}">
                                {{ $tournament->financialSettlement?->statusLabel() ?? 'not finalized' }}
                            </span>
                        </td>
                        <td>
                            <a class="btn btn-sm btn-cyan" href="{{ route('admin.settlements.show', $tournament) }}">Manage</a>
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $tournaments->links() }}</div>
        @endif
    </div>
@endsection
```

### FILE: resources/views/admin/settlement.blade.php
```blade
@extends('layouts.app')
@section('title', 'Settlement — ' . $tournament->name . ' — FF Arena Admin')
@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin:30px 0 4px">
        <h1 style="margin:0">🧾 Settlement: {{ $tournament->name }}</h1>
        <div style="display:flex; gap:8px; align-items:center">
            <span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span>
            <a class="btn btn-sm" href="{{ route('admin.settlements.index') }}">← All settlements</a>
        </div>
    </div>
    <p class="muted" style="margin-bottom:16px">
        <a href="{{ route('tournaments.show', $tournament) }}">View tournament</a>
    </p>

    {{-- Reconciliation summary --}}
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Gross collected</div><div class="num">৳{{ number_format($summary['gross_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color:var(--red)">−৳{{ number_format($summary['refunded_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Net collected</div><div class="num">৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Prize pool (declared)</div><div class="num">৳{{ number_format($summary['prize_pool_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Allocated prizes</div><div class="num" style="color:var(--purple)">৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Completed payouts</div><div class="num" style="color:var(--green)">৳{{ number_format($summary['completed_payouts_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Platform revenue</div><div class="num">৳{{ number_format($summary['platform_revenue_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Adjustments</div><div class="num">৳{{ number_format($summary['adjustments_minor'] / 100, 2) }}</div></div>
        <div class="stat">
            <div class="muted">Remaining</div>
            <div class="num" style="color:{{ $summary['remaining_minor'] >= 0 ? 'var(--green)' : 'var(--red)' }}">
                ৳{{ number_format($summary['remaining_minor'] / 100, 2) }}
            </div>
        </div>
        <div class="stat">
            <div class="muted">Reconciliation</div>
            <div class="num" style="font-size:19px; color:var(--amber)">{{ ucfirst($summary['reconciliation_status']) }}</div>
        </div>
    </div>

    {{-- Prize configuration --}}
    <div class="card" style="margin-top:18px">
        <h3>🏆 Prize Configuration</h3>
        @if(!$tiersEditable)
            <p class="muted" style="font-size:13px">Tiers are locked — the distribution has been calculated.</p>
        @endif

        @if($tiersEditable)
            <form method="POST" action="{{ route('admin.settlements.prizes', $tournament) }}">
                @csrf
                <table>
                    <tr><th>Rank</th><th>Type</th><th>Value</th></tr>
                    @for($i = 1; $i <= 10; $i++)
                        @php $tier = $tiers->firstWhere('position', $i); @endphp
                        <tr>
                            <td style="width:120px">#{{ $i }} {{ ['1st','2nd','3rd'][$i-1] ?? 'th' }}</td>
                            <td style="width:180px">
                                <select name="tiers[{{ $i }}][type]">
                                    <option value="fixed" @selected($tier && $tier->type === 'fixed')>Fixed (৳)</option>
                                    <option value="percentage" @selected($tier && $tier->type === 'percentage')>Percentage (%)</option>
                                </select>
                            </td>
                            <td>
                                <input type="hidden" name="tiers[{{ $i }}][position]" value="{{ $i }}">
                                <input type="text" name="tiers[{{ $i }}][value]" placeholder="e.g. 1000 or 50"
                                       value="{{ $tier ? ($tier->type === 'percentage' ? \App\Support\Money::basisPointsToPercent($tier->percentage_bp) : \App\Support\Money::toDecimal($tier->amount_minor)) : '' }}">
                            </td>
                        </tr>
                    @endfor
                </table>
                <button class="btn btn-primary btn-sm" style="margin-top:12px">Save Prizes</button>
            </form>
        @else
            <table>
                <tr><th>Rank</th><th>Type</th><th>Value</th></tr>
                @forelse($tiers as $tier)
                    <tr>
                        <td>#{{ $tier->position }}</td>
                        <td>{{ $tier->typeLabel() }}</td>
                        <td>
                            @if($tier->isFixed())
                                ৳{{ number_format($tier->amount_minor / 100, 2) }}
                            @else
                                {{ \App\Support\Money::basisPointsToPercent($tier->percentage_bp) }}%
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No prize tiers configured.</td></tr>
                @endforelse
            </table>
        @endif
    </div>

    {{-- Distribution --}}
    <div class="card" style="margin-top:18px">
        <h3>
            🎁 Prize Distribution
            @if($distribution)
                <span class="pill {{ $distribution->statusPill() }}">{{ $distribution->statusLabel() }}</span>
            @endif
        </h3>

        @if($distribution && $snapshot && $snapshot->isNotEmpty())
            <table>
                <tr><th>Rank</th><th>Team</th><th>Type</th><th>Amount</th></tr>
                @foreach($snapshot as $item)
                    <tr>
                        <td>#{{ $item->position }}</td>
                        <td><strong>{{ $item->team?->name ?? '—' }}</strong></td>
                        <td class="muted">{{ ucfirst($item->type) }}</td>
                        <td>৳{{ number_format($item->amount_minor / 100, 2) }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="muted">No distribution calculated yet.</p>
        @endif

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px">
            @if($tournament->status === 'finished')
                @if(!$distribution || $distribution->isTerminal())
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">@csrf
                        <button class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif($distribution->status === 'draft')
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">@csrf
                        <button class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif($distribution->status === 'calculated')
                    <form method="POST" action="{{ route('admin.settlements.approve', $tournament) }}">@csrf
                        <button class="btn btn-primary btn-sm">Approve</button>
                    </form>
                @elseif($distribution->status === 'approved')
                    <form method="POST" action="{{ route('admin.settlements.process', $tournament) }}" onsubmit="return confirm('Process all payouts and finalize settlement?')">@csrf
                        <button class="btn btn-green btn-sm">Process Payouts</button>
                    </form>
                @endif
            @endif

            @if($distribution && in_array($distribution->status, ['draft', 'calculated', 'approved'], true))
                <form method="POST" action="{{ route('admin.settlements.cancel', $tournament) }}">@csrf
                    <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Cancel</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Payouts --}}
    <div class="card" style="margin-top:18px">
        <h3>💸 Payouts</h3>
        @if($payouts->isEmpty())
            <p class="muted">No payouts yet.</p>
        @else
            <table>
                <tr><th>Rank</th><th>Team</th><th>Recipient</th><th>Amount</th><th>Method</th><th>Status</th></tr>
                @foreach($payouts as $payout)
                    <tr>
                        <td>#{{ $payout->rank }}</td>
                        <td>{{ $payout->team?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $payout->recipient?->name ?? '—' }}</td>
                        <td>৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                        <td class="muted">{{ $payout->payout_method }}</td>
                        <td>
                            <span class="pill {{ $payout->statusPill() }}">{{ $payout->statusLabel() }}</span>
                            @if($payout->failure_reason)
                                <div class="muted" style="font-size:12px">{{ $payout->failure_reason }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:13px; margin-top:12px">
                <a href="{{ route('admin.payouts.index') }}">Manage all payouts →</a>
            </p>
        @endif
    </div>

    {{-- Adjustments --}}
    <div class="card" style="margin-top:18px">
        <h3>🔧 Financial Adjustments</h3>
        @if($adjustments->isEmpty())
            <p class="muted">No adjustments.</p>
        @else
            <table>
                <tr><th>Type</th><th>Amount</th><th>Reason</th><th>By</th></tr>
                @foreach($adjustments as $adjustment)
                    <tr>
                        <td class="muted">{{ ucfirst($adjustment->type) }}</td>
                        <td style="{{ $adjustment->amount_minor < 0 ? 'color:var(--red)' : 'color:var(--green)' }}">
                            {{ $adjustment->amount_minor < 0 ? '−' : '+' }}৳{{ number_format(abs($adjustment->amount_minor) / 100, 2) }}
                        </td>
                        <td class="muted" style="font-size:13px">{{ $adjustment->reason }}</td>
                        <td class="muted" style="font-size:13px">{{ $adjustment->actor?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if(!$settlement)
            <form method="POST" action="{{ route('admin.settlements.adjust', $tournament) }}" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-top:12px">
                @csrf
                <div style="min-width:140px">
                    <label>Amount (৳, negative to debit)</label>
                    <input type="text" name="amount" placeholder="e.g. 100 or -100" required>
                </div>
                <div style="min-width:150px">
                    <label>Type</label>
                    <select name="type">
                        <option value="correction">Correction</option>
                        <option value="reversal">Reversal</option>
                    </select>
                </div>
                <div style="min-width:220px">
                    <label>Reason</label>
                    <input type="text" name="reason" required>
                </div>
                <button class="btn btn-sm btn-cyan">Add Adjustment</button>
            </form>
        @else
            <p class="muted" style="font-size:13px; margin-top:12px">Adjustments are frozen after finalization.</p>
        @endif
    </div>

    {{-- Finalized snapshot --}}
    @if($settlement)
        <div class="card" style="margin-top:18px">
            <h3>📦 Finalized Settlement</h3>
            <table>
                <tr><th>Gross</th><th>Refunded</th><th>Net</th><th>Pool</th><th>Allocated</th><th>Completed payouts</th><th>Revenue</th><th>Adjustments</th><th>Result</th><th>Finalized by</th><th>Finalized at</th></tr>
                <tr>
                    <td>৳{{ number_format($settlement->gross_collected_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->refunded_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->net_collected_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->prize_pool_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->allocated_prizes_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->completed_payouts_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->platform_revenue_minor / 100, 2) }}</td>
                    <td>৳{{ number_format($settlement->adjustments_minor / 100, 2) }}</td>
                    <td><span class="pill {{ $settlement->statusPill() }}">{{ $settlement->statusLabel() }}</span></td>
                    <td class="muted">{{ $settlement->finalizedBy?->name ?? '—' }}</td>
                    <td class="muted" style="font-size:12px">{{ $settlement->finalized_at?->format('d M Y, h:i A') }}</td>
                </tr>
            </table>
        </div>
    @endif
@endsection
```

### FILE: resources/views/admin/payouts.blade.php
```blade
@extends('layouts.app')
@section('title', 'Payouts — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">💸 Payouts</h1>

    <div class="card">
        <form method="GET" action="{{ route('admin.payouts.index') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
            <div style="min-width:160px">
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:220px">
                <label>Tournament</label>
                <select name="tournament_id">
                    <option value="">All tournaments</option>
                    @foreach($tournaments as $t)
                        <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-cyan">Filter</button>
        </form>
    </div>

    <div class="card">
        @if($payouts->isEmpty())
            <p class="muted">No payouts match your filters.</p>
        @else
            <table>
                <tr>
                    <th>ID</th><th>Tournament</th><th>Rank</th><th>Team</th><th>Recipient</th>
                    <th>Amount</th><th>Method</th><th>Status</th><th>Action</th>
                </tr>
                @foreach($payouts as $payout)
                    <tr>
                        <td><strong>#{{ $payout->id }}</strong></td>
                        <td>{{ $payout->tournament?->name ?? '—' }}</td>
                        <td>#{{ $payout->rank }}</td>
                        <td>{{ $payout->team?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $payout->recipient?->name ?? '—' }}</td>
                        <td>৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                        <td class="muted">{{ $payout->payout_method }}</td>
                        <td>
                            <span class="pill {{ $payout->statusPill() }}">{{ $payout->statusLabel() }}</span>
                            @if($payout->failure_reason)
                                <div class="muted" style="font-size:12px">{{ $payout->failure_reason }}</div>
                            @endif
                        </td>
                        <td>
                            @if($payout->status === 'pending')
                                <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}" style="display:inline">@csrf
                                    <button class="btn btn-green btn-sm">Approve</button>
                                </form>
                            @elseif($payout->status === 'approved')
                                <form method="POST" action="{{ route('admin.payouts.process', $payout) }}" style="display:inline">@csrf
                                    <button class="btn btn-green btn-sm">Process</button>
                                </form>
                            @elseif($payout->status === 'processing' && $payout->payout_method === 'manual')
                                <form method="POST" action="{{ route('admin.payouts.complete', $payout) }}" style="display:inline-flex; gap:6px; align-items:center">
                                    @csrf
                                    <input type="text" name="reference" placeholder="External ref" style="max-width:120px">
                                    <button class="btn btn-green btn-sm">Complete</button>
                                </form>
                            @endif

                            @if(in_array($payout->status, ['pending', 'approved'], true))
                                <form method="POST" action="{{ route('admin.payouts.cancel', $payout) }}" style="display:inline">@csrf
                                    <button class="btn btn-sm">Cancel</button>
                                </form>
                            @endif

                            @if(in_array($payout->status, ['pending', 'approved', 'processing'], true))
                                <form method="POST" action="{{ route('admin.payouts.fail', $payout) }}" style="display:inline">@csrf
                                    <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Fail</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $payouts->links() }}</div>
        @endif
    </div>
@endsection
```

### FILE: tests/Feature/PrizePayoutSettlementTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\FinancialSettlement;
use App\Models\GameMatch;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PrizeDistributionService;
use App\Services\PayoutService;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 09 — prize configuration, snapshots, standings, eligibility, payouts,
 * wallet integration and financial reconciliation.
 */
class PrizePayoutSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    protected function makeTournament(User $organizer, string $status = 'finished', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Settlement Tournament';
        $t->slug = $o['slug'] ?? ('settle-' . Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    /**
     * Attach a completed match + score to a team so the Phase 06 standings
     * engine ranks it. Kills control total points (placement 1 = 12 points).
     */
    protected function addScore(Tournament $tournament, Team $team, int $kills): Score
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->winner_team_id = $team->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        $score = new Score();
        $score->match_id = $match->id;
        $score->team_id = $team->id;
        $score->kills = $kills;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = $kills;
        $score->points = 12 + $kills;
        $score->status = 'pending';
        $score->save();

        return $score;
    }

    protected function makePayment(Tournament $t, Team $team, User $payer, int $minor, string $status = 'verified'): Payment
    {
        $p = new Payment();
        $p->tournament_id = $t->id;
        $p->team_id = $team->id;
        $p->payer_user_id = $payer->id;
        $p->amount_minor = $minor;
        $p->amount = Money::toDecimal($minor);
        $p->currency = 'BDT';
        $p->method = 'bkash';
        $p->trx_id = 'TX' . Str::random(6);
        $p->provider = 'bkash';
        $p->provider_reference = $p->trx_id;
        $p->status = $status;
        $p->paid_at = now();
        $p->save();

        return $p;
    }

    protected function distributions(): PrizeDistributionService
    {
        return app(PrizeDistributionService::class);
    }

    protected function payouts(): PayoutService
    {
        return app(PayoutService::class);
    }

    protected function reconciliation(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    // ------------------------------------------------------------------
    // Prize configuration
    // ------------------------------------------------------------------

    public function test_valid_fixed_prize_configuration_is_saved(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '3000'],
            ['position' => 2, 'type' => 'fixed', 'value' => '1500'],
            ['position' => 3, 'type' => 'fixed', 'value' => '500'],
        ], $admin);

        $this->assertSame(3, PrizeTier::where('tournament_id', $t->id)->count());
        $this->assertSame(300000, PrizeTier::where('tournament_id', $t->id)->where('position', 1)->firstOrFail()->amount_minor);
    }

    public function test_valid_percentage_configuration_resolves_against_pool(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 5);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '60'],
            ['position' => 2, 'type' => 'percentage', 'value' => '40'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $first = PrizeSnapshotItem::where('distribution_id', $dist->id)->where('position', 1)->firstOrFail();
        $this->assertSame(300000, $first->amount_minor); // 60% of ৳5000
    }

    public function test_negative_fixed_prize_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '-500'],
        ], $admin);
    }

    public function test_negative_percentage_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '-10'],
        ], $admin);
    }

    public function test_percentages_over_100_are_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '60'],
            ['position' => 2, 'type' => 'percentage', 'value' => '50'],
        ], $admin);
    }

    public function test_duplicate_position_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '1000'],
            ['position' => 1, 'type' => 'fixed', 'value' => '500'],
        ], $admin);
    }

    public function test_allocation_exceeding_pool_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '6000'],
        ], $admin);
    }

    public function test_invalid_position_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 0, 'type' => 'fixed', 'value' => '1000'],
        ], $admin);
    }

    // ------------------------------------------------------------------
    // Standings + snapshot
    // ------------------------------------------------------------------

    public function test_correct_winner_and_amount_are_snapshotted(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA);
        $teamB = $this->makeTeam($t, $capB);
        $this->addScore($t, $teamA, 10); // 22 pts → rank 1
        $this->addScore($t, $teamB, 5);  // 17 pts → rank 2

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '4000'],
            ['position' => 2, 'type' => 'fixed', 'value' => '1000'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $snapshot = $dist->snapshotItems()->orderBy('position')->get();
        $this->assertCount(2, $snapshot);
        $this->assertSame(1, $snapshot[0]->position);
        $this->assertSame($teamA->id, $snapshot[0]->team_id);
        $this->assertSame(400000, $snapshot[0]->amount_minor);
        $this->assertSame(2, $snapshot[1]->position);
        $this->assertSame($teamB->id, $snapshot[1]->team_id);
        $this->assertSame(100000, $snapshot[1]->amount_minor);
    }

    public function test_ties_are_broken_deterministically(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA); // created first → lower id
        $teamB = $this->makeTeam($t, $capB);
        $this->addScore($t, $teamA, 3);
        $this->addScore($t, $teamB, 3);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $first = $dist->snapshotItems()->where('position', 1)->firstOrFail();
        $this->assertSame($teamA->id, $first->team_id);
    }

    public function test_snapshot_is_immutable_after_calculation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '50'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);
        $before = $dist->snapshotItems()->firstOrFail()->amount_minor;

        // Editing the declared pool afterwards never rewrites the snapshot.
        $t->prize_pool = 99999;
        $t->save();

        $this->assertSame($before, $dist->fresh()->snapshotItems()->firstOrFail()->amount_minor);

        // Tier edits are locked once calculated.
        try {
            $this->distributions()->saveTiers($t, [
                ['position' => 1, 'type' => 'percentage', 'value' => '99'],
            ], $admin);
            $this->fail('Expected tier edits to be locked after calculation.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        // Re-calculating is idempotent — the snapshot is untouched.
        $again = $this->distributions()->calculate($t, $admin);
        $this->assertSame($dist->id, $again->id);
        $this->assertSame($before, $again->snapshotItems()->firstOrFail()->amount_minor);
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    public function test_open_tournament_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'open');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('finished');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_cancelled_tournament_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'cancelled');

        $this->expectException(DomainException::class);

        $this->distributions()->calculate($t, $admin);
    }

    public function test_incomplete_match_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 5);

        $live = new GameMatch();
        $live->tournament_id = $t->id;
        $live->status = GameMatch::STATUS_LIVE;
        $live->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('completed');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_unresolved_dispute_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $score = $this->addScore($t, $team, 5);

        $dispute = new Dispute();
        $dispute->tournament_id = $t->id;
        $dispute->match_id = $score->match_id;
        $dispute->opened_by = $org->id;
        $dispute->category = 'wrong_winner';
        $dispute->description = 'Result contested';
        $dispute->status = Dispute::STATUS_OPEN;
        $dispute->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dispute');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_unresolved_standings_block_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished'); // no scores

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('standings');

        $this->distributions()->calculate($t, $admin);
    }

    // ------------------------------------------------------------------
    // Payout + wallet integration
    // ------------------------------------------------------------------

    public function test_payout_recipient_is_the_teams_captain(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame($captain->id, $payout->recipient_user_id);
        $this->assertSame($team->id, $payout->recipient_team_id);
        $this->assertSame(1, $payout->rank);
        $this->assertSame(500000, $payout->amount_minor);
        $this->assertSame('BDT', $payout->currency);
    }

    public function test_payout_credits_wallet_and_writes_ledger(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());

        $entry = LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntry::TYPE_PAYOUT)->firstOrFail();
        $this->assertSame(LedgerEntry::DIRECTION_CREDIT, $entry->direction);
        $this->assertSame(500000, $entry->amount_minor);
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
    }

    public function test_payout_is_never_processed_twice(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        // Re-processing the completed distribution must be a no-op.
        $this->distributions()->process($t, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());
        $this->assertSame(1, LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntry::TYPE_PAYOUT)->count());
    }

    public function test_frozen_wallet_fails_payout_and_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $wallet = $this->wallets()->walletFor($captain);
        $wallet->status = \App\Models\Wallet::STATUS_FROZEN;
        $wallet->save();

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $dist = $this->distributions()->process($t, $admin);

        $this->assertSame(PrizeDistribution::STATUS_FAILED, $dist->status);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::STATUS_FAILED, $payout->status);
        $this->assertSame(0, $wallet->fresh()->balanceMinor());
    }

    public function test_duplicate_payout_for_same_rank_is_prevented(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // Approving twice must not create duplicate payouts for the same rank.
        $this->distributions()->approve($t, $admin);
        $this->distributions()->approve($t, $admin);

        $this->assertSame(1, Payout::where('tournament_id', $t->id)->where('rank', 1)->count());
    }

    public function test_manual_payout_requires_manual_completion(): void
    {
        config(['finance.default_payout_provider' => 'manual']);

        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::METHOD_MANUAL, $payout->payout_method);

        $this->distributions()->process($t, $admin);

        // Manual payout stays processing; no wallet credit; distribution not complete.
        $payout->refresh();
        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);
        $this->assertSame(0, $this->wallets()->walletFor($captain)->balanceMinor());
        $this->assertSame(PrizeDistribution::STATUS_PROCESSING, PrizeDistribution::where('tournament_id', $t->id)->firstOrFail()->status);

        $this->payouts()->completeManually($payout, $admin, 'REF-123');
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->fresh()->status);
        $this->assertSame('REF-123', $payout->fresh()->provider_reference);
    }

    // ------------------------------------------------------------------
    // Reconciliation
    // ------------------------------------------------------------------

    public function test_gross_refund_and_net_collection_are_computed(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA);
        $teamB = $this->makeTeam($t, $capB);

        $this->makePayment($t, $teamA, $capA, 500000, Payment::STATUS_VERIFIED);
        $this->makePayment($t, $teamB, $capB, 500000, Payment::STATUS_REFUNDED);

        $summary = $this->reconciliation()->summary($t);

        $this->assertSame(1000000, $summary['gross_collected_minor']);
        $this->assertSame(500000, $summary['refunded_minor']);
        $this->assertSame(500000, $summary['net_collected_minor']);
    }

    public function test_reconciliation_reports_underfunded(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // No entry fees collected → allocated prizes exceed net collection.
        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_UNDERFUNDED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_balanced_after_settlement(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_BALANCED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_overallocated(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 100]);

        // Data anomaly: allocation exceeds the declared pool.
        $dist = new PrizeDistribution();
        $dist->tournament_id = $t->id;
        $dist->status = PrizeDistribution::STATUS_COMPLETED;
        $dist->pool_minor = 10000;
        $dist->total_allocated_minor = 20000;
        $dist->save();

        $item = new PrizeSnapshotItem();
        $item->distribution_id = $dist->id;
        $item->tournament_id = $t->id;
        $item->position = 1;
        $item->team_id = null;
        $item->type = 'fixed';
        $item->amount_minor = 20000;
        $item->save();

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_OVERALLOCATED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_mismatch_before_completion(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // Calculated but not yet processed → mismatch.
        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_MISMATCH, $summary['reconciliation_status']);
    }

    public function test_commission_defaults_to_zero(): void
    {
        $this->assertSame(0, $this->reconciliation()->platformRevenueMinor(100000));
    }

    public function test_percentage_commission_is_applied_in_basis_points(): void
    {
        config(['finance.commission.type' => 'percentage']);
        config(['finance.commission.percentage_bp' => 800]); // 8%

        $this->assertSame(8000, $this->reconciliation()->platformRevenueMinor(100000));

        config(['finance.commission.percentage_bp' => 0]);
    }

    public function test_fixed_commission_cannot_exceed_revenue(): void
    {
        config(['finance.commission.type' => 'fixed']);
        config(['finance.commission.fixed_minor' => 999999]);

        $this->assertSame(100000, $this->reconciliation()->platformRevenueMinor(100000));

        config(['finance.commission.type' => 'percentage']);
        config(['finance.commission.fixed_minor' => 0]);
    }

    public function test_adjustments_flow_into_reconciliation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->reconciliation()->addAdjustment($t, 10000, 'correction', 'Sponsor top-up', $admin);

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(10000, $summary['adjustments_minor']);
        $this->assertSame(490000, $summary['remaining_minor']);
    }

    public function test_settlement_snapshot_is_frozen_once_finalized(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $settlement = FinancialSettlement::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(500000, $settlement->net_collected_minor);
        $this->assertSame($admin->id, $settlement->finalized_by);

        // A second finalize never overwrites the frozen snapshot.
        $this->reconciliation()->finalize($t, $admin, PrizeDistribution::where('tournament_id', $t->id)->firstOrFail());
        $this->assertSame(1, FinancialSettlement::where('tournament_id', $t->id)->count());

        // Post-finalization adjustments are refused.
        $this->expectException(DomainException::class);
        $this->reconciliation()->addAdjustment($t, 100, 'correction', 'late edit', $admin);
    }

    // ------------------------------------------------------------------
    // Full HTTP flow
    // ------------------------------------------------------------------

    public function test_full_distribution_flow_via_http(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->actingAs($admin)->post(route('admin.settlements.prizes', $t), [
            'tiers' => [
                1 => ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, PrizeTier::where('tournament_id', $t->id)->count());

        $this->actingAs($admin)->post(route('admin.settlements.calculate', $t))->assertRedirect();
        $dist = PrizeDistribution::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(PrizeDistribution::STATUS_CALCULATED, $dist->status);

        $this->actingAs($admin)->post(route('admin.settlements.approve', $t))->assertRedirect();
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::STATUS_APPROVED, $payout->status);
        $this->assertSame($captain->id, $payout->recipient_user_id);

        $this->actingAs($admin)->post(route('admin.settlements.process', $t))->assertRedirect();

        $payout->refresh();
        $dist->refresh();
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->status);
        $this->assertSame(PrizeDistribution::STATUS_COMPLETED, $dist->status);
        $this->assertSame(500000, $this->wallets()->walletFor($captain)->balanceMinor());
        $this->assertNotNull(FinancialSettlement::where('tournament_id', $t->id)->first());
    }

    public function test_user_sees_only_their_own_payouts_on_wallet_page(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');

        $tA = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Alpha Cup']);
        $teamA = $this->makeTeam($tA, $capA);
        $this->addScore($tA, $teamA, 10);
        $this->distributions()->saveTiers($tA, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($tA, $admin);
        $this->distributions()->approve($tA, $admin);
        $this->distributions()->process($tA, $admin);

        $tB = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Beta Cup']);
        $teamB = $this->makeTeam($tB, $capB);
        $this->addScore($tB, $teamB, 10);
        $this->distributions()->saveTiers($tB, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($tB, $admin);
        $this->distributions()->approve($tB, $admin);
        $this->distributions()->process($tB, $admin);

        $response = $this->actingAs($capA)->get(route('wallet.index'))->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertDontSee('Beta Cup');
    }
}
```

### FILE: tests/Feature/SettlementSecurityTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\FinancialSettlement;
use App\Models\GameMatch;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Score;
use App\Models\SettlementAdjustment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PrizeDistributionService;
use App\Services\PayoutService;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 09 — settlement/payout security: authorization, tampering, mass
 * assignment, cross-tournament access and duplicate prevention.
 */
class SettlementSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    protected function makeTournament(User $organizer, string $status = 'finished', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Security Tournament';
        $t->slug = $o['slug'] ?? ('sec-' . Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . strtoupper(Str::random(8));
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    protected function addScore(Tournament $tournament, Team $team, int $kills): Score
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team->id;
        $match->winner_team_id = $team->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        $score = new Score();
        $score->match_id = $match->id;
        $score->team_id = $team->id;
        $score->kills = $kills;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = $kills;
        $score->points = 12 + $kills;
        $score->status = 'pending';
        $score->save();

        return $score;
    }

    protected function distributions(): PrizeDistributionService
    {
        return app(PrizeDistributionService::class);
    }

    protected function payouts(): PayoutService
    {
        return app(PayoutService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    protected function settledDistribution(Tournament $t, User $admin, Team $team, string $prizeValue = '5000'): PrizeDistribution
    {
        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => $prizeValue],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        return PrizeDistribution::where('tournament_id', $t->id)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_settlement_pages(): void
    {
        $this->get(route('admin.settlements.index'))->assertRedirect(route('login'));
        $this->get(route('admin.payouts.index'))->assertRedirect(route('login'));
    }

    public function test_organizer_cannot_access_settlement_pages(): void
    {
        $org = $this->makeUser('organizer');

        $this->actingAs($org)->get(route('admin.settlements.index'))->assertForbidden();
        $this->actingAs($org)->get(route('admin.payouts.index'))->assertForbidden();
    }

    public function test_player_cannot_access_settlement_pages(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->get(route('admin.settlements.index'))->assertForbidden();
        $this->actingAs($player)->get(route('admin.payouts.index'))->assertForbidden();
    }

    public function test_organizer_cannot_calculate_or_approve_or_process(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->actingAs($org)->post(route('admin.settlements.calculate', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.approve', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.process', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.prizes', $t), [])->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.adjust', $t), [])->assertForbidden();
    }

    public function test_player_cannot_act_on_payouts(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();

        $this->actingAs($captain)->post(route('admin.payouts.process', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.approve', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.cancel', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.fail', $payout))->assertForbidden();
    }

    public function test_snapshot_only_contains_teams_of_its_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');

        $tA = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Alpha']);
        $teamA = $this->makeTeam($tA, $capA);
        $this->addScore($tA, $teamA, 10);

        // A second finished tournament with a foreign team + scores.
        $tB = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Beta']);
        $teamB = $this->makeTeam($tB, $capB);
        $this->addScore($tB, $teamB, 99);

        $this->distributions()->saveTiers($tA, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $dist = $this->distributions()->calculate($tA, $admin);

        $teamIds = $dist->snapshotItems()->pluck('team_id')->all();
        $this->assertContains($teamA->id, $teamIds);
        $this->assertNotContains($teamB->id, $teamIds);
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_payout_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Payout())->fill([
            'distribution_id' => 1,
            'tournament_id' => 1,
            'recipient_user_id' => 1,
            'rank' => 1,
            'amount_minor' => 999999,
            'status' => 'completed',
        ]);
    }

    public function test_prize_tier_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeTier())->fill([
            'tournament_id' => 1,
            'position' => 1,
            'type' => 'fixed',
            'amount_minor' => 999999,
        ]);
    }

    public function test_distribution_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeDistribution())->fill([
            'tournament_id' => 1,
            'status' => 'completed',
            'pool_minor' => 999999,
        ]);
    }

    public function test_snapshot_item_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeSnapshotItem())->fill([
            'distribution_id' => 1,
            'position' => 1,
            'amount_minor' => 999999,
        ]);
    }

    public function test_financial_settlement_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new FinancialSettlement())->fill([
            'tournament_id' => 1,
            'net_collected_minor' => 999999,
            'reconciliation_status' => 'balanced',
        ]);
    }

    public function test_settlement_adjustment_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new SettlementAdjustment())->fill([
            'tournament_id' => 1,
            'amount_minor' => -999999,
        ]);
    }

    // ------------------------------------------------------------------
    // State machine + idempotency
    // ------------------------------------------------------------------

    public function test_completed_payout_cannot_be_reprocessed_or_cancelled(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->payouts()->process($payout, $admin);

        $payout->refresh();
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->status);
        $this->assertTrue($payout->isTerminal());

        // completed has no outgoing transitions.
        $this->assertFalse($payout->canTransitionTo(Payout::STATUS_PROCESSING));
        $this->assertFalse($payout->canTransitionTo(Payout::STATUS_APPROVED));

        // Re-processing is idempotent — no new credit.
        $again = $this->payouts()->process($payout, $admin);
        $this->assertSame(Payout::STATUS_COMPLETED, $again->status);

        // Cancelling a completed payout is refused.
        $this->expectException(DomainException::class);
        $this->payouts()->cancel($payout->fresh(), $admin);
    }

    public function test_completed_distribution_cannot_be_processed_again(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $dist = $this->distributions()->process($t, $admin);
        $this->assertSame(PrizeDistribution::STATUS_COMPLETED, $dist->status);

        // Re-processing returns the completed distribution without new effects.
        $again = $this->distributions()->process($t, $admin);
        $this->assertSame($dist->id, $again->id);
        $this->assertSame(1, Payout::where('tournament_id', $t->id)->count());
    }

    public function test_calculated_distribution_cannot_be_completed_directly(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $dist = $this->distributions()->calculate($t, $admin);
        $this->assertSame(PrizeDistribution::STATUS_CALCULATED, $dist->status);

        // completed is not a legal transition from calculated.
        $this->assertFalse($dist->canTransitionTo(PrizeDistribution::STATUS_COMPLETED));
        $this->assertFalse($dist->canTransitionTo(PrizeDistribution::STATUS_PROCESSING));

        $this->expectException(\DomainException::class);
        $this->distributions()->process($t, $admin);
    }

    // ------------------------------------------------------------------
    // Payout integrity under tampering
    // ------------------------------------------------------------------

    public function test_payout_rank_and_amount_come_only_from_the_snapshot(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        // Try to influence the payout via bogus request data — it is ignored.
        $this->actingAs($admin)->post(route('admin.settlements.prizes', $t), [
            'tiers' => [
                1 => ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
            ],
            'payout_amount' => 1,
            'recipient_user_id' => 99999,
        ])->assertRedirect();

        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame($captain->id, $payout->recipient_user_id);
        $this->assertSame(1, $payout->rank);
        $this->assertSame(500000, $payout->amount_minor);
    }

    public function test_prize_pool_tampering_is_rejected_by_validation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 100]);

        // A tier larger than the pool is rejected server-side.
        $this->expectException(\DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '999999'],
        ], $admin);
    }

    public function test_wallet_balance_is_only_mutated_by_wallet_service(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->payouts()->process($payout, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
        $this->assertSame(1, $wallet->ledgerEntries()->where('type', \App\Models\LedgerEntry::TYPE_PAYOUT)->count());
    }
}
```

### FILE: app/Support/Money.php
```php
<?php

namespace App\Support;

use DomainException;

/**
 * Integer minor-unit money handling (BDT poisha, 100 minor units per taka).
 *
 * No floating-point arithmetic is ever used for monetary values: decimals are
 * converted to/from integer minor units via string math, so amounts like
 * "123.45" are exactly 12345 poisha.
 */
final class Money
{
    /**
     * Minor units per major unit (BDT: 100 poisha per taka).
     */
    public const MINOR_UNITS = 100;

    /**
     * Convert a decimal amount ("123.45", 100, 99.9, "0") into integer minor
     * units (poisha). Negative amounts are rejected — financial operations in
     * FF Arena are never negative.
     */
    public static function toMinor(mixed $amount): int
    {
        $string = trim((string) $amount);

        if ($string === '' || $string === '-') {
            throw new DomainException('Invalid amount.');
        }

        $negative = str_starts_with($string, '-');
        if ($negative) {
            $string = substr($string, 1);
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new DomainException('Invalid amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');

        // Round a 3rd decimal place half-up; keep only two fraction digits.
        if (strlen($fraction) > 2) {
            $third = (int) $fraction[2];
            $fraction = substr($fraction, 0, 2);
            if ($third >= 5) {
                $fraction = str_pad((string) ((int) $fraction + 1), 2, '0', STR_PAD_LEFT);
                if ((int) $fraction >= 100) {
                    $whole = (string) ((int) $whole + 1);
                    $fraction = '00';
                }
            }
        }

        $fraction = str_pad($fraction, 2, '0');

        $minor = ((int) $whole * self::MINOR_UNITS) + (int) $fraction;

        if ($negative) {
            throw new DomainException('Negative amounts are not allowed.');
        }

        return $minor;
    }

    /**
     * Convert integer minor units into a 2-decimal string ("12345" → "123.45").
     */
    public static function toDecimal(int $minor): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);

        $whole = intdiv($minor, self::MINOR_UNITS);
        $fraction = $minor % self::MINOR_UNITS;

        return ($negative ? '-' : '') . $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format minor units for display (e.g. "৳123.45").
     */
    public static function formatMinor(int $minor): string
    {
        return '৳' . number_format((float) self::toDecimal($minor), 2);
    }

    /**
     * Convert a percentage string ("50", "33.33", "100") into integer basis
     * points (1/100th of a percent): "33.33" → 3333, "100" → 10000.
     *
     * Reuses the exact integer string math of toMinor() (a percent has two
     * decimal places, so 1% = 100 basis points). No floating-point is used.
     */
    public static function toBasisPoints(mixed $percent): int
    {
        return self::toMinor($percent);
    }

    /**
     * Convert integer basis points back to a 2-decimal percent string
     * (3333 → "33.33").
     */
    public static function basisPointsToPercent(int $basisPoints): string
    {
        return self::toDecimal($basisPoints);
    }
}
```

### FILE: app/Models/LedgerEntry.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, append-only wallet ledger entry.
 *
 * Double-entry-style: each entry carries a direction (credit/debit), an
 * integer minor-unit amount and the running balance after the movement, so
 * every financial movement is explainable and the wallet balance can be
 * reconciled against the ledger at any time.
 *
 * Entries are only ever created by WalletService; they can never be edited
 * or deleted.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL = 'reversal';
    public const TYPE_PAYOUT = 'payout';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'balance_after' => 'integer',
        'reference_id' => 'integer',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }
}
```

### FILE: app/Models/Tournament.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    /**
     * Lifecycle states. These are the single source of truth for the values
     * stored in the `status` column.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that are visible on public listings. DRAFT (not yet published)
     * and CANCELLED tournaments are hidden.
     */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_LIVE,
        self::STATUS_FINISHED,
    ];

    /**
     * Valid state transitions. A tournament may only move along these edges;
     * it can never jump arbitrarily between states. FINISHED and CANCELLED
     * are terminal.
     *
     * draft    → open, cancelled
     * open     → closed, live, cancelled   (open == published + accepting)
     * closed   → live, cancelled
     * live     → finished
     * finished → (terminal)
     * cancelled→ (terminal)
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_CLOSED, self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_CLOSED => [self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_FINISHED],
        self::STATUS_FINISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket formats. Only formats that are actually implemented may be
     * selected; the others are intentionally absent so they never appear
     * as a selectable option.
     */
    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';

    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM,
        self::FORMAT_DOUBLE_ELIM,
    ];

    /**
     * organizer_id, slug and status are set server-side only. They are
     * excluded from mass assignment so a client can never hijack ownership
     * or lifecycle state.
     */
    protected $fillable = [
        'name',
        'game_mode',
        'map',
        'entry_fee',
        'prize_pool',
        'team_slots',
        'team_size',
        'rules',
        'starts_at',
        'check_in_starts_at',
        'check_in_ends_at',
        'format',
        'dispute_window_hours',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
        'bracket_size' => 'integer',
        'dispute_window_hours' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function confirmedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Teams that currently occupy a slot: pending (awaiting payment
     * verification) or confirmed. Withdrawn, rejected, no-show and
     * waitlisted teams do not occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
    }

    /**
     * Teams on the waitlist, in deterministic FIFO order.
     */
    public function waitlistedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_WAITLISTED);
    }

    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scoring rule-set versions for this tournament (Phase 06).
     */
    public function scoringRules()
    {
        return $this->hasMany(ScoringRule::class);
    }

    /**
     * Disputes raised against matches in this tournament (Phase 07).
     */
    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * The participant dispute window in hours (default 24). A value of 0
     * disables participant disputes entirely; staff always bypass.
     */
    public function disputeWindowHours(): int
    {
        return (int) ($this->dispute_window_hours ?? 24);
    }

    /**
     * The entry fee in integer minor units (poisha), computed from the
     * server-side `entry_fee` column — never from client input.
     */
    public function entryFeeMinor(): int
    {
        $raw = $this->getRawOriginal('entry_fee');

        if ($raw === null) {
            $raw = $this->entry_fee;
        }

        try {
            return \App\Support\Money::toMinor($raw);
        } catch (\DomainException $e) {
            return 0;
        }
    }

    /**
     * Number of registration slots still available. Counts both pending and
     * confirmed teams so that a team awaiting payment still reserves its slot.
     */
    public function slotsLeft(): int
    {
        return max(0, (int) $this->team_slots - $this->registeredTeams()->count());
    }

    public function isFull(): bool
    {
        return $this->slotsLeft() <= 0;
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the configured start time has already passed.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    /**
     * Whether the tournament is currently accepting new registrations.
     * Status is the primary authority; the start time acts as a hard deadline.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->hasStarted();
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    /**
     * The declared prize pool in integer minor units (poisha), computed from
     * the server-side `prize_pool` column — never from client input and never
     * using floating-point arithmetic.
     */
    public function prizePoolMinor(): int
    {
        $raw = $this->getRawOriginal('prize_pool');

        if ($raw === null) {
            $raw = $this->prize_pool;
        }

        try {
            return \App\Support\Money::toMinor($raw);
        } catch (\DomainException $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Phase 09 — prize distribution + payouts + settlement
    // ------------------------------------------------------------------

    /**
     * Configurable prize tiers (fixed or percentage), ordered by rank.
     */
    public function prizeTiers()
    {
        return $this->hasMany(PrizeTier::class)->orderBy('position');
    }

    /**
     * Prize-distribution workflow records (one terminal record per settled
     * attempt; a retry after failure/cancellation creates a new record).
     */
    public function prizeDistributions()
    {
        return $this->hasMany(PrizeDistribution::class)->orderByDesc('id');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    public function financialSettlement()
    {
        return $this->hasOne(FinancialSettlement::class);
    }

    public function settlementAdjustments()
    {
        return $this->hasMany(SettlementAdjustment::class);
    }

    // ------------------------------------------------------------------
    // Phase 04 — check-in window + bracket eligibility
    // ------------------------------------------------------------------

    /**
     * Check-in is only active when BOTH window timestamps are configured.
     * Tournaments created before this feature (or without a window) simply
     * do not require check-in, preserving the Phase 02 behaviour.
     */
    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null && $this->check_in_ends_at !== null;
    }

    /**
     * Whether the check-in window is currently open.
     */
    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        return now()->between($this->check_in_starts_at, $this->check_in_ends_at);
    }

    /**
     * Whether the check-in window has already closed.
     */
    public function checkInHasClosed(): bool
    {
        return $this->hasCheckIn() && $this->check_in_ends_at->isPast();
    }

    /**
     * The set of teams eligible for competitive bracket generation:
     * confirmed teams that have checked in (when check-in is configured).
     * This is the single source of truth used by BracketService.
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()
            ->where('status', Team::STATUS_CONFIRMED)
            ->when($this->hasCheckIn(), fn ($q) => $q->whereNotNull('checked_in_at'));
    }
}
```

### FILE: app/Models/User.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Sensitive fields (role, wallet_balance) are intentionally excluded from
     * mass assignment. `role` must be set explicitly (see AuthController) and
     * can only ever be 'player' or 'organizer' at registration time.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Moderators are platform staff who can work the dispute/moderation
     * queue and review/resolve disputes. The role is granted only by admins
     * (never self-assigned and never mass-assignable).
     */
    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    /**
     * Platform staff (admins + moderators) — distinct from tournament
     * organizers, who are staff only within their own tournaments.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }

    /**
     * The user's wallet (Phase 08). Created lazily by WalletService.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Prize payouts received by this user (Phase 09).
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_user_id');
    }
}
```

### FILE: app/Models/Team.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    /**
     * Team lifecycle statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_NO_SHOW = 'no_show';

    /**
     * Statuses that occupy a registration slot in a tournament.
     */
    public const SLOT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
    ];

    /**
     * Statuses that hold a competitive identity (Free Fire UID) in a
     * tournament. Waitlisted teams reserve their UID too, so a player cannot
     * appear on two teams (including a waitlisted one) in the same
     * tournament. Withdrawn teams release their UID and are excluded.
     */
    public const COMPETING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_WAITLISTED,
    ];

    /**
     * tournament_id, captain_id, status, seed, checked_in_at, checked_in_by
     * and waitlisted_at are server-controlled. Excluded from mass assignment
     * so a client can never forge participation/check-in/waitlist state.
     */
    protected $fillable = [
        'name',
        'captain_name',
        'phone',
        'game_uid',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'waitlisted_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Prize payouts awarded to this team (Phase 09).
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_team_id');
    }

    public function latestPayment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function isCaptain(User $user): bool
    {
        return $this->captain_id !== null && $this->captain_id === $user->id;
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isWaitlisted(): bool
    {
        return $this->status === self::STATUS_WAITLISTED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * Whether this team currently occupies a slot in its tournament.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::SLOT_STATUSES, true);
    }

    /**
     * Whether a member with the given (case-insensitive, trimmed) Free Fire
     * UID already exists on this team.
     */
    public function hasMemberWithUid(string $uid): bool
    {
        return $this->members()
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [strtoupper(trim($uid))])
            ->exists();
    }

    /**
     * Total roster size: the captain plus all members.
     */
    public function rosterSize(): int
    {
        return 1 + $this->members()->count();
    }

    /**
     * 1-based position on the tournament waitlist, or null when not
     * waitlisted. Deterministic FIFO ordering: waitlisted_at, then id.
     */
    public function waitlistPosition(): ?int
    {
        if (! $this->isWaitlisted()) {
            return null;
        }

        return Team::query()
            ->where('tournament_id', $this->tournament_id)
            ->where('status', self::STATUS_WAITLISTED)
            ->where(function ($q) {
                $q->where('waitlisted_at', '<', $this->waitlisted_at)
                    ->orWhere(function ($q2) {
                        $q2->where('waitlisted_at', $this->waitlisted_at)
                            ->where('id', '<', $this->id);
                    });
            })
            ->count() + 1;
    }
}
```

### FILE: app/Http/Controllers/WalletController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated user's wallet: balance, ledger history, payment history
 * (Phase 08) and their own prize-payout history (Phase 09).
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
    ) {
    }

    public function index()
    {
        $user = auth()->user();
        $wallet = $this->wallets->walletFor($user);

        $ledger = $wallet->ledgerEntries()->with('actor')->limit(100)->get();

        $payments = Payment::query()
            ->where(function ($q) use ($user) {
                $q->where('payer_user_id', $user->id)
                    ->orWhereHas('team', fn ($t) => $t->where('captain_id', $user->id));
            })
            ->with(['tournament', 'team', 'refund'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Only the authenticated user's own payouts are ever shown.
        $payouts = Payout::query()
            ->where('recipient_user_id', $user->id)
            ->with(['tournament', 'team'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('wallet.index', compact('wallet', 'ledger', 'payments', 'payouts'));
    }
}
```

### FILE: routes/web.php
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Payments (Phase 08)
        Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
        Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

        // Wallets + ledger (Phase 08)
        Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
        Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
        Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

        // Prize distribution + payouts + settlement (Phase 09)
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'show'])->name('settlements.show');
        Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
        Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
        Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
        Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
        Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
        Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');
    });
});
```

### FILE: resources/views/wallet/index.blade.php
```blade
@extends('layouts.app')
@section('title', 'My Wallet — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">👛 My Wallet</h1>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color:var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Currency</div>
            <div class="num">{{ $wallet->currency }}</div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🧾 Wallet Transactions</h3>
            @if($ledger->isEmpty())
                <p class="muted">No wallet transactions yet.</p>
            @else
                <table>
                    <tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr>
                    @foreach($ledger as $entry)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ $entry->created_at->format('d M, h:i A') }}</td>
                            <td><span class="pill {{ $entry->isCredit() ? 'confirmed' : 'finished' }}">{{ strtoupper($entry->type) }}</span></td>
                            <td style="{{ $entry->isCredit() ? 'color:var(--green)' : 'color:var(--red)' }}">
                                {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                            </td>
                            <td class="muted" style="font-size:13px">{{ $entry->description }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>💳 Payment History</h3>
            @if($payments->isEmpty())
                <p class="muted">No payments yet.</p>
            @else
                <table>
                    <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>Status</th></tr>
                    @foreach($payments as $payment)
                        <tr>
                            <td>{{ $payment->tournament?->name ?? '—' }}</td>
                            <td>{{ $payment->team?->name ?? '—' }}</td>
                            <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                            <td>
                                <span class="pill {{ $payment->statusPill() }}">{{ strtoupper($payment->status) }}</span>
                                @if($payment->refund)
                                    <span class="muted" style="font-size:12px">(refunded)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>🏆 Prize Payouts</h3>
            @if($payouts->isEmpty())
                <p class="muted">No prize payouts yet.</p>
            @else
                <table>
                    <tr><th>Tournament</th><th>Rank</th><th>Amount</th><th>Status</th><th>Paid</th></tr>
                    @foreach($payouts as $payout)
                        <tr>
                            <td>{{ $payout->tournament?->name ?? '—' }}</td>
                            <td>#{{ $payout->rank }}</td>
                            <td style="color:var(--green)">+৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                            <td><span class="pill {{ $payout->statusPill() }}">{{ $payout->statusLabel() }}</span></td>
                            <td class="muted" style="font-size:12px">{{ $payout->processed_at?->format('d M, h:i A') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
@endsection
```

### FILE: resources/views/admin/dashboard.blade.php
```blade
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Admin Dashboard</h1>

    <div class="card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <span class="muted">Financials:</span>
        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">Payments</a>
        <a href="{{ route('admin.settlements.index') }}" class="btn btn-sm btn-cyan">Settlements</a>
        <a href="{{ route('admin.payouts.index') }}" class="btn btn-sm">Payouts</a>
    </div>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color:var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}" style="display:flex; gap:10px; align-items:end">
            @csrf
            <div style="flex:1; max-width:320px">
                <label>Promote a user to moderator (by email)</label>
                <input type="email" name="email" placeholder="user@example.com" required>
            </div>
            <button class="btn btn-cyan btn-sm">Promote</button>
        </form>
        @if($moderators->isEmpty())
            <p class="muted" style="margin-top:12px">No moderators yet.</p>
        @else
            <table style="margin-top:12px">
                <tr><th>Name</th><th>Email</th><th></th></tr>
                @foreach($moderators as $moderator)
                    <tr>
                        <td>{{ $moderator->name }}</td>
                        <td class="muted">{{ $moderator->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Demote</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card" style="margin-top:18px">
        <h3>💸 Pending Payments</h3>
        @if($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <table>
                <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>TrxID</th><th>Action</th></tr>
                @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->tournament->name }}</td>
                        <td>{{ $p->team->name }}</td>
                        <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                        <td>{{ $p->trx_id }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.payments.verify', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-green btn-sm">Verify</button>
                            </form>
                            <form method="POST" action="{{ route('admin.payments.fail', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-sm">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:13px; margin-top:12px">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </div>
@endsection
```
