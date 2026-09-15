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
