<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\GameMatch;
use App\Models\Notification;
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
        protected NotificationService $notifications,
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
            } catch (\App\Exceptions\PayoutReviewRequiredException $e) {
                // Phase 10 — a payout held for fraud review stops the run
                // WITHOUT failing it. The distribution stays `processing`
                // until an admin overrides or clears the hold.
                break;
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

        // Phase 11 — notify the organizer and every paid recipient that the
        // settlement is final.
        $recipients = [];

        foreach ($distribution->payouts()->with('recipient')->get() as $payout) {
            if ($payout->recipient !== null) {
                $recipients[$payout->recipient->id] = $payout->recipient;
            }
        }

        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $recipients[$organizer->id] = $organizer;
        }

        $this->notifications->sendToMany(
            $recipients,
            Notification::TYPE_SETTLEMENT_COMPLETED,
            'Prize settlement completed',
            'Prize settlement for ' . $tournament->name . ' has completed.',
            NotificationService::link('tournaments.show', [$tournament]),
            ['tournament_id' => $tournament->id],
        );

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
