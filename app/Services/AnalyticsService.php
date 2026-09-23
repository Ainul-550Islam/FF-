<?php

namespace App\Services;

use App\Models\AntiCheatIncident;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\FinancialSettlement;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\Refund;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Score;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational analytics (Phase 13).
 *
 * A thin, read-only aggregation layer over server-authoritative data. It
 * never mutates any domain state and never returns raw device/IP/fraud
 * signals — only role-appropriate aggregates. Callers are responsible for
 * authorization; this service performs no authorization of its own.
 *
 * Money is always reported in integer minor units (BDT poisha).
 */
class AnalyticsService
{
    /**
     * Apply an optional date range to a query against a timestamp column.
     * Dates are validated by the caller; invalid values are ignored.
     */
    protected function inRange(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', $from.' 00:00:00');
        }

        if ($to !== null && $to !== '') {
            $query->where($column, '<=', $to.' 23:59:59');
        }

        return $query;
    }

    /**
     * Platform overview: the headline numbers behind the admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function platformOverview(?string $from = null, ?string $to = null): array
    {
        return [
            'users' => User::count(),
            'tournaments' => [
                'total' => Tournament::count(),
                'created' => $this->inRange(Tournament::query(), 'created_at', $from, $to)->count(),
                'open' => Tournament::where('status', Tournament::STATUS_OPEN)->count(),
                'live' => Tournament::where('status', Tournament::STATUS_LIVE)->count(),
                'finished' => Tournament::where('status', Tournament::STATUS_FINISHED)->count(),
                'cancelled' => Tournament::where('status', Tournament::STATUS_CANCELLED)->count(),
            ],
            'teams' => Team::count(),
            'matches' => GameMatch::count(),
            'disputes' => Dispute::count(),
            'open_disputes' => Dispute::whereIn('status', Dispute::ACTIONABLE_STATUSES)->count(),
            'tickets' => SupportTicket::count(),
            'open_tickets' => SupportTicket::whereIn('status', SupportTicket::OPEN_STATUSES)->count(),
        ];
    }

    /**
     * Per-tournament operational metrics (registrations, check-in, no-shows,
     * matches, completion). Scoped by the caller's authorization.
     *
     * @return array<string, mixed>
     */
    public function tournamentMetrics(Tournament $tournament): array
    {
        $teams = $tournament->teams();

        $confirmed = (clone $teams)->where('status', Team::STATUS_CONFIRMED)->count();
        $waitlisted = (clone $teams)->where('status', Team::STATUS_WAITLISTED)->count();
        $withdrawn = (clone $teams)->where('status', Team::STATUS_WITHDRAWN)->count();
        $noShow = (clone $teams)->where('status', Team::STATUS_NO_SHOW)->count();
        $checkedIn = (clone $teams)->whereNotNull('checked_in_at')->count();

        $matches = $tournament->matches();
        $totalMatches = (clone $matches)->count();
        $completedMatches = (clone $matches)->where('status', GameMatch::STATUS_COMPLETED)->count();
        $disputedMatches = (clone $matches)->where('status', GameMatch::STATUS_DISPUTED)->count();
        $liveMatches = (clone $matches)->where('status', GameMatch::STATUS_LIVE)->count();

        // Check-in rate over teams that occupied a slot (confirmed + no-show).
        $slotTeams = $confirmed + $noShow;
        $checkInRate = $slotTeams > 0 ? round($checkedIn / $slotTeams * 100, 1) : 0.0;
        $noShowRate = $slotTeams > 0 ? round($noShow / $slotTeams * 100, 1) : 0.0;
        $matchCompletionRate = $totalMatches > 0 ? round($completedMatches / $totalMatches * 100, 1) : 0.0;

        return [
            'teams' => [
                'total' => (clone $teams)->count(),
                'confirmed' => $confirmed,
                'waitlisted' => $waitlisted,
                'withdrawn' => $withdrawn,
                'no_show' => $noShow,
                'checked_in' => $checkedIn,
            ],
            'rates' => [
                'check_in' => $checkInRate,
                'no_show' => $noShowRate,
                'match_completion' => $matchCompletionRate,
            ],
            'matches' => [
                'total' => $totalMatches,
                'completed' => $completedMatches,
                'live' => $liveMatches,
                'disputed' => $disputedMatches,
            ],
        ];
    }

    /**
     * Platform-wide match metrics.
     *
     * @return array<string, mixed>
     */
    public function matchMetrics(?string $from = null, ?string $to = null): array
    {
        $scheduled = $this->inRange(GameMatch::query(), 'created_at', $from, $to)->count();
        $completed = $this->inRange(GameMatch::where('status', GameMatch::STATUS_COMPLETED), 'created_at', $from, $to)->count();
        $live = GameMatch::where('status', GameMatch::STATUS_LIVE)->count();
        $disputed = $this->inRange(GameMatch::where('status', GameMatch::STATUS_DISPUTED), 'created_at', $from, $to)->count();
        $cancelled = $this->inRange(GameMatch::where('status', GameMatch::STATUS_CANCELLED), 'created_at', $from, $to)->count();
        $unresolved = $this->inRange(GameMatch::whereIn('status', [
            GameMatch::STATUS_DISPUTED,
            GameMatch::STATUS_LIVE,
            GameMatch::STATUS_PENDING,
            GameMatch::STATUS_READY,
        ]), 'created_at', $from, $to)->count();

        $avgCompletion = $this->averageSeconds(
            'matches',
            'created_at',
            'completed_at',
            $from,
            $to,
        );

        return [
            'scheduled' => $scheduled,
            'completed' => $completed,
            'live' => $live,
            'disputed' => $disputed,
            'cancelled' => $cancelled,
            'unresolved' => $unresolved,
            'avg_completion_seconds' => $avgCompletion,
            'scoring' => [
                'scores_submitted' => $this->inRange(Score::query(), 'created_at', $from, $to)->count(),
                'avg_kills' => round($this->inRange(Score::query(), 'created_at', $from, $to)->avg('kills') ?? 0, 1),
                'total_points' => (int) $this->inRange(Score::query(), 'created_at', $from, $to)->sum('points'),
            ],
        ];
    }

    /**
     * Aggregate platform player metrics. Avoids per-user profiling; returns
     * counts only.
     *
     * @return array<string, mixed>
     */
    public function playerMetrics(?string $from = null, ?string $to = null): array
    {
        $captains = Team::query()->whereNotNull('captain_id');

        $repeat = (clone $captains)
            ->select('captain_id')
            ->selectRaw('COUNT(DISTINCT tournament_id) AS tournament_count')
            ->groupBy('captain_id')
            ->get()
            ->filter(fn ($row) => $row->tournament_count > 1)
            ->count();

        return [
            'total_users' => User::count(),
            'new_users' => $this->inRange(User::query(), 'created_at', $from, $to)->count(),
            'organizers' => User::where('role', 'organizer')->count(),
            'participants' => (clone $captains)->distinct()->count('captain_id'),
            'repeat_participants' => $repeat,
        ];
    }

    /**
     * Admin-only financial aggregates. Never mutates financial state.
     *
     * @return array<string, mixed>
     */
    public function financialMetrics(?string $from = null, ?string $to = null): array
    {
        $successPayments = Payment::whereIn('status', Payment::SUCCESS_STATUSES);
        $failedPayments = Payment::where('status', Payment::STATUS_FAILED);

        $settlementCounts = FinancialSettlement::query()
            ->select('reconciliation_status', DB::raw('COUNT(*) AS total'))
            ->groupBy('reconciliation_status')
            ->pluck('total', 'reconciliation_status')
            ->toArray();

        return [
            'payments' => [
                'volume_minor' => (int) $this->inRange($successPayments, 'created_at', $from, $to)->sum('amount_minor'),
                'successful' => (int) $this->inRange(Payment::whereIn('status', Payment::SUCCESS_STATUSES), 'created_at', $from, $to)->count(),
                'failed' => (int) $this->inRange($failedPayments, 'created_at', $from, $to)->count(),
                'refunded_minor' => (int) $this->inRange(Refund::query(), 'created_at', $from, $to)->sum('amount_minor'),
            ],
            'wallets' => [
                'balance_minor' => (int) Wallet::sum('balance_minor'),
                'credits_minor' => (int) $this->inRange(LedgerEntry::where('direction', 'credit'), 'created_at', $from, $to)->sum('amount_minor'),
                'debits_minor' => (int) $this->inRange(LedgerEntry::where('direction', 'debit'), 'created_at', $from, $to)->sum('amount_minor'),
            ],
            'prizes' => [
                'pool_minor' => (int) $this->inRange(PrizeDistribution::query(), 'created_at', $from, $to)->sum('pool_minor'),
                'allocated_minor' => (int) $this->inRange(PrizeDistribution::query(), 'created_at', $from, $to)->sum('total_allocated_minor'),
            ],
            'payouts' => [
                'completed_minor' => (int) $this->inRange(Payout::where('status', Payout::STATUS_COMPLETED), 'created_at', $from, $to)->sum('amount_minor'),
                'completed' => (int) $this->inRange(Payout::where('status', Payout::STATUS_COMPLETED), 'created_at', $from, $to)->count(),
                'pending' => Payout::where('status', Payout::STATUS_PENDING)->count(),
                'pending_minor' => (int) Payout::where('status', Payout::STATUS_PENDING)->sum('amount_minor'),
            ],
            'settlements' => [
                'balanced' => $settlementCounts[FinancialSettlement::STATUS_BALANCED] ?? 0,
                'underfunded' => $settlementCounts[FinancialSettlement::STATUS_UNDERFUNDED] ?? 0,
                'overallocated' => $settlementCounts[FinancialSettlement::STATUS_OVERALLOCATED] ?? 0,
                'mismatch' => $settlementCounts[FinancialSettlement::STATUS_MISMATCH] ?? 0,
                'exceptions' => FinancialSettlement::where('reconciliation_status', '!=', FinancialSettlement::STATUS_BALANCED)->count(),
            ],
        ];
    }

    /**
     * Admin-only security aggregates. Never returns raw device/IP/fraud data.
     *
     * @return array<string, mixed>
     */
    public function securityMetrics(): array
    {
        $levelCounts = RiskProfile::query()
            ->select('risk_level', DB::raw('COUNT(*) AS total'))
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level')
            ->toArray();

        $incidentCounts = AntiCheatIncident::query()
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'accounts_under_review' => RiskProfile::where('manual_review_required', true)->count(),
            'risk_levels' => [
                'low' => $levelCounts[RiskProfile::LEVEL_LOW] ?? 0,
                'medium' => $levelCounts[RiskProfile::LEVEL_MEDIUM] ?? 0,
                'high' => $levelCounts[RiskProfile::LEVEL_HIGH] ?? 0,
                'critical' => $levelCounts[RiskProfile::LEVEL_CRITICAL] ?? 0,
            ],
            'restrictions' => [
                'active' => Restriction::where('status', Restriction::STATUS_ACTIVE)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
                'lifted' => Restriction::where('status', Restriction::STATUS_LIFTED)->count(),
            ],
            'anti_cheat' => [
                'flagged' => $incidentCounts[AntiCheatIncident::STATUS_FLAGGED] ?? 0,
                'under_review' => $incidentCounts[AntiCheatIncident::STATUS_UNDER_REVIEW] ?? 0,
                'confirmed' => $incidentCounts[AntiCheatIncident::STATUS_CONFIRMED] ?? 0,
                'restricted' => $incidentCounts[AntiCheatIncident::STATUS_RESTRICTED] ?? 0,
                'cleared' => $incidentCounts[AntiCheatIncident::STATUS_CLEARED] ?? 0,
            ],
            'ban_evasion_reviews' => RiskEvent::where('type', RiskEvent::TYPE_BAN_EVASION)->count(),
            'identity_reviews' => IdentityVerification::whereIn('status', [
                IdentityVerification::STATUS_PENDING,
                IdentityVerification::STATUS_REVIEW_REQUIRED,
            ])->count(),
        ];
    }

    /**
     * Staff dispute metrics. No internal moderation detail beyond counts.
     *
     * @return array<string, mixed>
     */
    public function disputeMetrics(?string $from = null, ?string $to = null): array
    {
        $categoryCounts = Dispute::query()
            ->select('category', DB::raw('COUNT(*) AS total'))
            ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW])
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        $workload = Dispute::query()
            ->select('assigned_to', DB::raw('COUNT(*) AS total'))
            ->whereIn('status', Dispute::ACTIONABLE_STATUSES)
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->with('assignee:id,name,username')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'staff' => $row->assignee?->name ?? 'Unknown',
                'open' => (int) $row->total,
            ])
            ->toArray();

        return [
            'open' => $this->inRange(Dispute::whereIn('status', Dispute::ACTIONABLE_STATUSES), 'created_at', $from, $to)->count(),
            'resolved' => $this->inRange(Dispute::where('status', Dispute::STATUS_RESOLVED), 'created_at', $from, $to)->count(),
            'rejected' => $this->inRange(Dispute::where('status', Dispute::STATUS_REJECTED), 'created_at', $from, $to)->count(),
            'cancelled' => $this->inRange(Dispute::where('status', Dispute::STATUS_CANCELLED), 'created_at', $from, $to)->count(),
            'avg_resolution_seconds' => $this->averageSeconds('disputes', 'created_at', 'resolved_at', $from, $to),
            'by_category_open' => $categoryCounts,
            'evidence_volume' => DisputeEvidence::count(),
            'moderator_workload' => $workload,
        ];
    }

    /**
     * Staff support metrics.
     *
     * @return array<string, mixed>
     */
    public function supportMetrics(?string $from = null, ?string $to = null): array
    {
        $categoryCounts = $this->inRange(SupportTicket::query(), 'created_at', $from, $to)
            ->select('category', DB::raw('COUNT(*) AS total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        $priorityCounts = SupportTicket::whereIn('status', SupportTicket::OPEN_STATUSES)
            ->select('priority', DB::raw('COUNT(*) AS total'))
            ->groupBy('priority')
            ->pluck('total', 'priority')
            ->toArray();

        $workload = SupportTicket::query()
            ->select('assigned_to', DB::raw('COUNT(*) AS total'))
            ->whereIn('status', SupportTicket::OPEN_STATUSES)
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->with('assignee:id,name,username')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'staff' => $row->assignee?->name ?? 'Unknown',
                'open' => (int) $row->total,
            ])
            ->toArray();

        return [
            'open' => SupportTicket::whereIn('status', SupportTicket::OPEN_STATUSES)->count(),
            'pending' => SupportTicket::where('status', SupportTicket::STATUS_PENDING)->count(),
            'resolved' => $this->inRange(SupportTicket::where('status', SupportTicket::STATUS_RESOLVED), 'created_at', $from, $to)->count(),
            'closed' => $this->inRange(SupportTicket::where('status', SupportTicket::STATUS_CLOSED), 'created_at', $from, $to)->count(),
            'reopened' => SupportTicket::where('reopened_count', '>', 0)->count(),
            'avg_resolution_seconds' => $this->averageSeconds('support_tickets', 'created_at', 'resolved_at', $from, $to),
            'by_category' => $categoryCounts,
            'by_priority_open' => $priorityCounts,
            'staff_workload' => $workload,
        ];
    }

    /**
     * Average wall-clock seconds between two timestamp columns for rows where
     * the second column is set, within an optional range on the first column.
     * Bounded to recent rows so memory stays flat for large tables.
     */
    protected function averageSeconds(string $table, string $startColumn, string $endColumn, ?string $from, ?string $to): ?float
    {
        $query = DB::table($table)
            ->whereNotNull($endColumn)
            ->select($startColumn, $endColumn)
            ->limit(5000)
            ->latest('id');

        if ($from !== null && $from !== '') {
            $query->where($startColumn, '>=', $from.' 00:00:00');
        }

        if ($to !== null && $to !== '') {
            $query->where($startColumn, '<=', $to.' 23:59:59');
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = 0.0;
        $count = 0;

        foreach ($rows as $row) {
            $start = strtotime((string) $row->{$startColumn});
            $end = strtotime((string) $row->{$endColumn});

            if ($start === false || $end === false || $end < $start) {
                continue;
            }

            $total += ($end - $start);
            $count++;
        }

        return $count > 0 ? round($total / $count, 1) : null;
    }
}
