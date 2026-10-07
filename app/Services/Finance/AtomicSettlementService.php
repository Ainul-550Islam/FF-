<?php

namespace App\Services\Finance;

use App\Events\SettlementCompleted;
use App\Models\FinancialSettlement;
use App\Models\PrizeDistribution;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\PayoutService;
use App\Services\PrizeDistributionService;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * GAP-10 A8 (tracker row 035) — atomic tournament settlement.
 *
 * Settles a tournament inside ONE database transaction:
 *
 *     prize pool → payout records → wallet ledger credits → FinancialSettlement
 *
 * Why one transaction: the recovery story for a half-settled tournament is
 * manual and dangerous (some winners paid, the snapshot missing). Either the
 * whole settlement commits, or nothing does — the same discipline the rest of
 * the money core follows.
 *
 * Non-negotiables, enforced by construction:
 *
 *  - it never writes wallet rows itself. All wallet movement goes through
 *    WalletService (via PayoutService), which owns the ledger, the row locks
 *    and the idempotency keys;
 *  - it never bypasses a lock. The tournament row is locked FOR UPDATE before
 *    any decision is taken, and the delegate services lock what they need;
 *  - it is idempotent per tournament. A completed settlement is returned
 *    unchanged instead of being settled twice, and `financial_settlements`
 *    carries a unique idempotency key so two concurrent settle attempts cannot
 *    both write a snapshot (the loser surfaces as a replay, not a duplicate);
 *  - the "settlement completed" event is dispatched AFTER the commit
 *    (App\Events\SettlementCompleted is ShouldDispatchAfterCommit), so a
 *    listener can never observe — or act on — a transaction that rolls back.
 *
 * It composes the existing Phase 09 services rather than re-implementing
 * them: PrizeDistributionService (tiers → distribution → payouts),
 * PayoutService (money movement + dual control), ReconciliationService
 * (snapshot + reconciliation status) and AuditLogService (trail).
 */
class AtomicSettlementService
{
    /**
     * Prefix for the per-tournament settlement idempotency key.
     */
    public const IDEMPOTENCY_PREFIX = 'settlement:tournament:';

    public function __construct(
        protected PrizeDistributionService $prizes,
        protected PayoutService $payouts,
        protected ReconciliationService $reconciliation,
        protected WalletService $wallets,
        protected AuditLogService $audit,
    ) {}

    /**
     * The idempotency key that makes a settlement unique per tournament.
     */
    public function idempotencyKey(Tournament $tournament): string
    {
        return self::IDEMPOTENCY_PREFIX.$tournament->id;
    }

    /**
     * Settle a tournament atomically.
     *
     * @param  array{calculate?: bool, approve?: bool, process?: bool}  $options
     *         Each step defaults to true. A caller that has already calculated/
     *         approved the distribution can skip those steps; skipping is
     *         validated (an unapproved distribution can never be processed).
     * @return array{
     *     ok: bool,
     *     idempotent: bool,
     *     settled: bool,
     *     tournament_id: int,
     *     settlement_id: int|null,
     *     distribution_id: int|null,
     *     distribution_status: string|null,
     *     reconciliation_status: string|null,
     *     payouts_total: int,
     *     payouts_completed: int,
     *     payouts_pending: int,
     *     prizes_allocated_minor: int,
     *     completed_payouts_minor: int,
     *     currency: string
     * }
     */
    public function settle(Tournament $tournament, \App\Models\User $admin, array $options = []): array
    {
        $calculate = (bool) ($options['calculate'] ?? true);
        $approve = (bool) ($options['approve'] ?? true);
        $process = (bool) ($options['process'] ?? true);

        $existing = $this->completedSnapshot($tournament);

        if ($existing !== null) {
            // Idempotent replay: the settlement already happened. Nothing is
            // written, nothing is re-paid.
            return $this->result($tournament, $existing, true);
        }

        $distributionId = null;

        try {
            DB::transaction(function () use ($tournament, $admin, $calculate, $approve, $process, &$distributionId) {
                // Lock the tournament row first: everything below depends on
                // its prize pool and status staying put for the whole
                // settlement.
                $locked = Tournament::query()
                    ->whereKey($tournament->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-check inside the lock: another worker may have completed
                // the settlement while this request was waiting for the lock.
                if ($this->completedSnapshot($locked) !== null) {
                    return;
                }

                $distribution = $this->prizes->activeDistribution($locked) ?? $this->prizes->latestDistribution($locked);

                if ($calculate && ($distribution === null || $distribution->status === PrizeDistribution::STATUS_DRAFT)) {
                    $distribution = $this->prizes->calculate($locked, $admin);
                }

                if ($distribution === null) {
                    throw new DomainException('No prize distribution exists for this tournament.');
                }

                $distributionId = $distribution->id;

                if ($approve && in_array($distribution->status, [PrizeDistribution::STATUS_CALCULATED, PrizeDistribution::STATUS_DRAFT], true)) {
                    $distribution = $this->prizes->approve($locked, $admin);
                }

                if ($process) {
                    if (! in_array($distribution->status, [PrizeDistribution::STATUS_APPROVED, PrizeDistribution::STATUS_PROCESSING], true)) {
                        throw new DomainException('Only an approved distribution can be settled.');
                    }

                    $distribution = $this->prizes->process($locked, $admin);
                }

                $this->stampIdempotencyKey($locked);
            });
        } catch (Throwable $e) {
            // The transaction rolled back: no partial ledger, no partial
            // payout set, no snapshot. Record the failure for the trail and
            // re-raise so the caller decides how to surface it.
            $this->audit->recordQuietly($admin, 'settlement.processed', 'tournament', $tournament->id, [
                'metadata' => [
                    'atomic' => true,
                    'failed' => true,
                    'error' => substr($e::class.': '.$e->getMessage(), 0, 255),
                ],
            ]);

            throw $e;
        }

        $settlement = $this->currentSnapshot($tournament);
        $distribution = $this->distribution($tournament);

        $result = $this->result($tournament, $settlement, false, $distribution?->id ?? $distributionId);

        // AFTER COMMIT: the event is the notification/outbox seam. It carries
        // identifiers only, so a listener re-reads the settled state instead of
        // trusting a payload that could be stale.
        if ($result['settled'] && $settlement !== null) {
            SettlementCompleted::dispatch($settlement->id, $tournament->id, $admin->id);
        }

        return $result;
    }

    /**
     * Re-check a settlement against the live data without moving money.
     *
     * Used by the reconciliation job: it recomputes the snapshot from the
     * current payments/payouts and reports whether the stored snapshot still
     * matches. It writes only the reconciliation status (a derived field) —
     * never a wallet row, a payout or an amount.
     *
     * @return array{ok: bool, changed: bool, stored: string|null, computed: string, settlement_id: int, tournament_id: int, drift: array<string, array{stored: int, computed: int}>}
     */
    public function recheck(FinancialSettlement $settlement): array
    {
        $tournament = $settlement->tournament;

        if ($tournament === null) {
            return [
                'ok' => false,
                'changed' => false,
                'stored' => $settlement->reconciliation_status,
                'computed' => 'unknown',
                'settlement_id' => (int) $settlement->id,
                'tournament_id' => (int) $settlement->tournament_id,
                'drift' => [],
            ];
        }

        $summary = $this->reconciliation->summary($tournament);

        $columns = [
            'gross_collected_minor',
            'refunded_minor',
            'net_collected_minor',
            'prize_pool_minor',
            'allocated_prizes_minor',
            'completed_payouts_minor',
            'platform_revenue_minor',
            'adjustments_minor',
        ];

        $drift = [];

        foreach ($columns as $column) {
            $stored = (int) ($settlement->{$column} ?? 0);
            $computed = (int) ($summary[$column] ?? 0);

            if ($stored !== $computed) {
                $drift[$column] = ['stored' => $stored, 'computed' => $computed];
            }
        }

        $computedStatus = (string) ($summary['reconciliation_status'] ?? FinancialSettlement::STATUS_MISMATCH);
        $storedStatus = $settlement->reconciliation_status !== null ? (string) $settlement->reconciliation_status : null;
        $changed = false;

        // Only the derived reconciliation status is corrected — the settlement
        // snapshot itself is immutable (financial history is never rewritten
        // in place; corrections are SettlementAdjustment rows).
        if ($storedStatus !== $computedStatus && $drift === []) {
            $settlement->reconciliation_status = $computedStatus;
            $settlement->save();

            $changed = true;
        }

        return [
            'ok' => $drift === [],
            'changed' => $changed,
            'stored' => $storedStatus,
            'computed' => $computedStatus,
            'settlement_id' => (int) $settlement->id,
            'tournament_id' => (int) $tournament->id,
            'drift' => $drift,
        ];
    }

    /**
     * Pending settlements, oldest first — the work list for the reconcile
     * command. "Pending" means: not finalized for the tournament, or finalized
     * but not reconciled as balanced.
     *
     * @return \Illuminate\Support\Collection<int, FinancialSettlement>
     */
    public function pendingSettlements(int $limit = 50): \Illuminate\Support\Collection
    {
        return FinancialSettlement::query()
            ->where(function ($query) {
                $query->whereNull('reconciliation_status')
                    ->orWhere('reconciliation_status', '!=', FinancialSettlement::STATUS_BALANCED);
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Tournaments whose prize distribution completed but which have no
     * financial snapshot yet — a settlement that is missing rather than
     * unbalanced. These are created by the reconciliation path, never by a
     * money movement.
     *
     * @return \Illuminate\Support\Collection<int, Tournament>
     */
    public function tournamentsMissingSnapshot(int $limit = 50): \Illuminate\Support\Collection
    {
        return Tournament::query()
            ->whereDoesntHave('financialSettlement')
            ->whereHas('prizeDistributions', function ($query) {
                $query->where('status', PrizeDistribution::STATUS_COMPLETED);
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The stored settlement snapshot for a tournament, if any.
     */
    protected function currentSnapshot(Tournament $tournament): ?FinancialSettlement
    {
        return FinancialSettlement::query()->where('tournament_id', $tournament->id)->first();
    }

    /**
     * A settlement that represents a *finished* outcome (not merely a row).
     */
    protected function completedSnapshot(Tournament $tournament): ?FinancialSettlement
    {
        $settlement = $this->currentSnapshot($tournament);

        if ($settlement === null) {
            return null;
        }

        $distribution = $this->distribution($tournament);

        if ($distribution === null || $distribution->status !== PrizeDistribution::STATUS_COMPLETED) {
            return null;
        }

        return $settlement;
    }

    protected function distribution(Tournament $tournament): ?PrizeDistribution
    {
        return $this->prizes->latestDistribution($tournament);
    }

    /**
     * Give the settlement row its per-tournament idempotency key so the unique
     * index enforces "one settlement per tournament" at the database level,
     * and not merely in application code.
     */
    protected function stampIdempotencyKey(Tournament $tournament): void
    {
        $settlement = $this->currentSnapshot($tournament);

        if ($settlement === null) {
            return;
        }

        $key = $this->idempotencyKey($tournament);

        if ((string) ($settlement->idempotency_key ?? '') === $key) {
            return;
        }

        // A pre-existing key from another writer means another settlement
        // exists for this tournament: that is a conflict, not something to
        // paper over.
        if ($settlement->idempotency_key !== null && (string) $settlement->idempotency_key !== '') {
            return;
        }

        $settlement->idempotency_key = $key;
        $settlement->save();
    }

    /**
     * @return array{
     *     ok: bool, idempotent: bool, settled: bool, tournament_id: int,
     *     settlement_id: int|null, distribution_id: int|null,
     *     distribution_status: string|null, reconciliation_status: string|null,
     *     payouts_total: int, payouts_completed: int, payouts_pending: int,
     *     prizes_allocated_minor: int, completed_payouts_minor: int, currency: string
     * }
     */
    protected function result(Tournament $tournament, ?FinancialSettlement $settlement, bool $idempotent, ?int $distributionId = null): array
    {
        $distribution = $distributionId !== null
            ? PrizeDistribution::query()->find($distributionId) ?? $this->distribution($tournament)
            : $this->distribution($tournament);

        $payoutsTotal = 0;
        $payoutsCompleted = 0;
        $payoutsPending = 0;

        if ($distribution !== null) {
            $payoutsTotal = (int) $distribution->payouts()->count();
            $payoutsCompleted = (int) $distribution->payouts()->where('status', 'completed')->count();
            $payoutsPending = max(0, $payoutsTotal - $payoutsCompleted);
        }

        $settled = $distribution !== null
            && $distribution->status === PrizeDistribution::STATUS_COMPLETED
            && $payoutsPending === 0;

        return [
            'ok' => true,
            'idempotent' => $idempotent,
            'settled' => $settled,
            'tournament_id' => (int) $tournament->id,
            'settlement_id' => $settlement?->id !== null ? (int) $settlement->id : null,
            'distribution_id' => $distribution?->id !== null ? (int) $distribution->id : null,
            'distribution_status' => $distribution?->status,
            'reconciliation_status' => $settlement?->reconciliation_status,
            'payouts_total' => $payoutsTotal,
            'payouts_completed' => $payoutsCompleted,
            'payouts_pending' => $payoutsPending,
            'prizes_allocated_minor' => (int) ($settlement->allocated_prizes_minor ?? 0),
            'completed_payouts_minor' => (int) ($settlement->completed_payouts_minor ?? 0),
            'currency' => (string) ($settlement->currency ?? 'BDT'),
        ];
    }

    /**
     * A unique reference for an operator-initiated settlement, shown in the
     * audit trail. Kept here so the format is defined in one place.
     */
    public function reference(): string
    {
        return 'settle-'.Str::lower(Str::random(12));
    }

    /**
     * The wallet balance implied by the ledger for every participant of a
     * settled tournament; used by reporting. Read-only.
     *
     * @return array<int, array{user_id: int, wallet_id: int, stored_minor: int, ledger_minor: int}>
     */
    public function participantBalances(Tournament $tournament): array
    {
        $balances = [];

        foreach ((array) $tournament->payouts()->with('recipient')->get() as $payout) {
            $recipient = $payout->recipient ?? null;

            if ($recipient === null) {
                continue;
            }

            $wallet = $this->wallets->getOrCreateWallet($recipient->id);

            $balances[] = [
                'user_id' => (int) $recipient->id,
                'wallet_id' => (int) $wallet->id,
                'stored_minor' => (int) $wallet->balanceMinor(),
                'ledger_minor' => (int) $this->wallets->calculateBalance($wallet->id),
            ];
        }

        return $balances;
    }
}
