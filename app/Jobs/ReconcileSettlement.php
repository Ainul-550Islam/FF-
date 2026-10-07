<?php

namespace App\Jobs;

use App\Models\FinancialSettlement;
use App\Models\Notification;
use App\Models\PrizeDistribution;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Finance\AtomicSettlementService;
use App\Services\NotificationService;
use App\Services\ReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * GAP-10 A8 (tracker row 054) — re-check one settlement.
 *
 * Recomputes a settlement from the live payments/payouts and compares it with
 * the stored snapshot. The rule that shapes every line of this job:
 *
 *   a reconciliation job may only *correct derived data*. It must never move
 *   money. There is no path from here to WalletService, PayoutService or a
 *   balance update, and the job never marks a payout complete or failed.
 *
 * Outcomes:
 *
 *  - `balanced`      — snapshot matches the recomputation; only the derived
 *                      reconciliation status is refreshed if it was stale.
 *  - `drift`         — amounts differ. The stored snapshot is immutable
 *                      financial history, so the job records the drift
 *                      (audit + admin notification) and leaves the settlement
 *                      in its current state for a human. It does NOT retry
 *                      money movement and does NOT rewrite history — manual
 *                      corrections go through SettlementAdjustment.
 *  - `unknown`       — the tournament could not be resolved, or the stored
 *                      status is not a value the system recognises. The
 *                      settlement stays pending/review; the job stops
 *                      cleanly (one attempt) instead of guessing.
 *  - `missing_snapshot` — a completed distribution with no snapshot: the
 *                      snapshot is (re)created read-only from the live data,
 *                      which is bookkeeping, not money movement.
 *
 * Retries: `tries = 1`. A retry would re-run the same read-only comparison and
 * cannot produce a different answer for a financial state that needs a human;
 * the command that dispatches this job is scheduled, so the next run picks it
 * up again.
 */
class ReconcileSettlement implements ShouldQueue
{
    use Queueable;

    public const OUTCOME_BALANCED = 'balanced';

    public const OUTCOME_DRIFT = 'drift';

    public const OUTCOME_UNKNOWN = 'unknown';

    public const OUTCOME_MISSING_SNAPSHOT = 'missing_snapshot';

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  int|null  $settlementId  an existing settlement to re-check
     * @param  int|null  $tournamentId  used when no settlement row exists yet
     */
    public function __construct(
        public readonly ?int $settlementId = null,
        public readonly ?int $tournamentId = null,
    ) {
        if ($this->settlementId === null && $this->tournamentId === null) {
            throw new \InvalidArgumentException('ReconcileSettlement requires a settlement id or a tournament id.');
        }
    }

    public function handle(
        AtomicSettlementService $settlements,
        ReconciliationService $reconciliation,
        AuditLogService $audit,
        NotificationService $notifications,
    ): void {
        $settlement = $this->resolveSettlement();

        if ($settlement === null) {
            $this->reportMissingSnapshot($settlements, $reconciliation, $audit, $notifications);

            return;
        }

        $tournament = $settlement->tournament;

        if ($tournament === null) {
            $this->record($audit, $settlement, self::OUTCOME_UNKNOWN, [
                'reason' => 'tournament_missing',
            ]);

            return;
        }

        if ($tournament->prizeDistributions()->count() === 0) {
            // A settlement exists for a tournament with no prize distribution:
            // the source data is in a state this build cannot interpret, so the
            // settlement stays pending/review for a human. Touching nothing is
            // the only safe outcome.
            $this->record($audit, $settlement, self::OUTCOME_UNKNOWN, [
                'reason' => 'prize_distribution_missing',
            ]);

            return;
        }

        $recheck = $settlements->recheck($settlement);

        if ($recheck['drift'] !== []) {
            // Money on record disagrees with the recomputation: a human must
            // look. Never auto-correct amounts, never retry payouts.
            $this->record($audit, $settlement, self::OUTCOME_DRIFT, [
                'drift' => $recheck['drift'],
                'computed_status' => $recheck['computed'],
            ]);

            $this->notifyAdmins(
                $notifications,
                'Settlement reconciliation drift: '.$tournament->name,
                'The stored settlement for '.$tournament->name.' no longer matches the recomputed figures. A human must review before any further payout. Settlement #'.$settlement->id.'.'
            );

            return;
        }

        if (! in_array((string) $recheck['computed'], FinancialSettlement::STATUSES, true)) {
            // An unrecognised reconciliation status means the source data is
            // in a state this build does not understand: leave it exactly as
            // it is for review.
            $this->record($audit, $settlement, self::OUTCOME_UNKNOWN, [
                'computed_status' => $recheck['computed'],
            ]);

            return;
        }

        $this->record($audit, $settlement, self::OUTCOME_BALANCED, [
            'changed' => $recheck['changed'],
            'computed_status' => $recheck['computed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(): array
    {
        return [
            'finance',
            'settlement:'.($this->settlementId ?? 'none'),
            'tournament:'.($this->tournamentId ?? 'none'),
        ];
    }

    protected function resolveSettlement(): ?FinancialSettlement
    {
        if ($this->settlementId !== null) {
            return FinancialSettlement::query()->with('tournament')->find($this->settlementId);
        }

        return FinancialSettlement::query()
            ->with('tournament')
            ->where('tournament_id', $this->tournamentId)
            ->first();
    }

    /**
     * A completed distribution with no snapshot row: the snapshot is derived
     * bookkeeping (gross/net/pool/allocated/reconciliation), so recreating it
     * from the live data is a repair of the record — it neither pays anybody
     * nor changes a balance.
     */
    protected function reportMissingSnapshot(
        AtomicSettlementService $settlements,
        ReconciliationService $reconciliation,
        AuditLogService $audit,
        NotificationService $notifications,
    ): void {
        if ($this->tournamentId === null) {
            return;
        }

        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $completed = $tournament->prizeDistributions()
            ->where('status', PrizeDistribution::STATUS_COMPLETED)
            ->exists();

        if (! $completed) {
            // Nothing was settled, so there is nothing to reconcile. Staying
            // silent is correct: no money has moved.
            return;
        }

        $finalizer = $this->finalizer($tournament);

        if ($finalizer === null) {
            return;
        }

        try {
            $settlement = $reconciliation->finalize($tournament, $finalizer, $tournament->prizeDistributions()->first());
        } catch (Throwable $e) {
            $audit->recordQuietly(null, 'settlement.processed', 'tournament', $tournament->id, [
                'metadata' => [
                    'reconcile' => self::OUTCOME_MISSING_SNAPSHOT,
                    'error' => substr($e::class.': '.$e->getMessage(), 0, 255),
                ],
            ]);

            return;
        }

        $audit->recordQuietly(null, 'settlement.reconciled', 'tournament', $tournament->id, [
            'metadata' => [
                'outcome' => self::OUTCOME_MISSING_SNAPSHOT,
                'settlement_id' => $settlement->id,
            ],
        ]);

        $this->notifyAdmins(
            $notifications,
            'Missing settlement snapshot restored: '.$tournament->name,
            'The prize distribution for '.$tournament->name.' was completed but no settlement snapshot existed. The snapshot was recorded from the live figures (no money moved).'
        );
    }

    /**
     * Whoever finalized the tournament, or an admin as the accountable
     * system actor. Never invents a user: if neither exists, the snapshot is
     * left for a human.
     */
    protected function finalizer(Tournament $tournament): ?User
    {
        $existing = FinancialSettlement::query()->where('tournament_id', $tournament->id)->first();

        if ($existing?->finalizedBy instanceof User) {
            return $existing->finalizedBy;
        }

        $distribution = $tournament->prizeDistributions()->first();

        if ($distribution?->approvedBy instanceof User) {
            return $distribution->approvedBy;
        }

        return User::query()->where('role', 'admin')->orderBy('id')->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function record(AuditLogService $audit, FinancialSettlement $settlement, string $outcome, array $metadata = []): void
    {
        $audit->recordQuietly(null, 'settlement.reconciled', 'financial_settlement', $settlement->id, [
            'tournament_id' => $settlement->tournament_id,
            'metadata' => array_merge(['outcome' => $outcome], $metadata),
        ]);
    }

    protected function notifyAdmins(NotificationService $notifications, string $title, string $body): void
    {
        try {
            $admins = User::query()->where('role', 'admin')->get();

            if ($admins->isNotEmpty()) {
                $notifications->sendToMany($admins, Notification::TYPE_SYSTEM, $title, $body);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
