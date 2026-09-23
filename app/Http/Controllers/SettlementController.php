<?php

namespace App\Http\Controllers;

use App\Models\PrizeDistribution;
use App\Models\Tournament;
use App\Services\AuditLogService;
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
        protected AuditLogService $audit,
    ) {}

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

        $this->audit->recordQuietly(auth()->user(), 'settlement.tiers_saved', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['tiers' => count($rows)],
        ]);

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

        $this->audit->recordQuietly(auth()->user(), 'settlement.calculated', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['allocated_minor' => $distribution->total_allocated_minor],
        ]);

        return back()->with('success', 'Prize distribution calculated ('.Money::formatMinor($distribution->total_allocated_minor).' allocated).');
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

        $this->audit->recordQuietly(auth()->user(), 'settlement.approved', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

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

        $this->audit->recordQuietly(auth()->user(), 'settlement.processed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['status' => $distribution->status],
        ]);

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {
            return back()->with('success', 'Prize distribution completed and settlement finalized.');
        }

        return back()->with('error', 'Prize distribution failed: '.($distribution->failure_reason ?? 'unknown error'));
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

        $this->audit->recordQuietly(auth()->user(), 'settlement.cancelled', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

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

        $this->audit->recordQuietly(auth()->user(), 'settlement.adjusted', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['amount_minor' => $minor, 'type' => $data['type']],
        ]);

        return back()->with('success', 'Financial adjustment recorded.');
    }

    /**
     * Legacy `/admin/tournaments/{tournament}/settlement` alias — the same
     * settlement screen as {@see show()}.
     */
    public function showByTournament(Tournament $tournament)
    {
        return $this->show($tournament);
    }
}
