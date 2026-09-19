#!/usr/bin/env python3
"""Apply Phase 13 audit hooks to existing controllers. Exact-match, fail-loud."""
import sys

BASE = "/home/user/FF-"

REPLACEMENTS = [
    # ============================= AdminController =============================
    ("app/Http/Controllers/AdminController.php",
     "use App\\Services\\FraudRiskService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\FraudRiskService;"),

    ("app/Http/Controllers/AdminController.php",
     """    public function __construct(
        protected PaymentService $payments,
        protected WalletService $wallets,
        protected FraudRiskService $risk,
    ) {
    }""",
     """    public function __construct(
        protected PaymentService $payments,
        protected WalletService $wallets,
        protected FraudRiskService $risk,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/AdminController.php",
     """        $user->role = 'moderator';
        $user->save();

        return back()->with('success', $user->name . ' is now a moderator.');""",
     """        $previousRole = $user->role;

        $user->role = 'moderator';
        $user->save();

        $this->audit->recordQuietly(auth()->user(), 'role.change', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['role' => $previousRole],
            'after' => ['role' => 'moderator'],
        ]);

        return back()->with('success', $user->name . ' is now a moderator.');"""),

    ("app/Http/Controllers/AdminController.php",
     """        $user->role = 'player';
        $user->save();

        return back()->with('success', $user->name . ' is no longer a moderator.');""",
     """        $previousRole = $user->role;

        $user->role = 'player';
        $user->save();

        $this->audit->recordQuietly(auth()->user(), 'role.change', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['role' => $previousRole],
            'after' => ['role' => 'player'],
        ]);

        return back()->with('success', $user->name . ' is no longer a moderator.');"""),

    ("app/Http/Controllers/AdminController.php",
     """        return back()->with('success', 'Payment verified. Team confirmed.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payment.verified', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
        ]);

        return back()->with('success', 'Payment verified. Team confirmed.');"""),

    ("app/Http/Controllers/AdminController.php",
     """        $this->risk->recordPaymentFailure($payment);

        return back()->with('success', 'Payment marked as failed.');""",
     """        $this->risk->recordPaymentFailure($payment);

        $this->audit->recordQuietly(auth()->user(), 'payment.failed', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payment marked as failed.');"""),

    ("app/Http/Controllers/AdminController.php",
     """        return back()->with('success', 'Payment refunded (৳' . \\App\\Support\\Money::toDecimal($refund->amount_minor) . ' credited to the payer).');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payment.refunded', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
            'metadata' => ['amount_minor' => $refund->amount_minor],
        ]);

        return back()->with('success', 'Payment refunded (৳' . \\App\\Support\\Money::toDecimal($refund->amount_minor) . ' credited to the payer).');"""),

    ("app/Http/Controllers/AdminController.php",
     """        return back()->with('success', 'Wallet credited.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'wallet.credited', 'wallet', $wallet->id, [
            'target_user_id' => $user->id,
            'metadata' => ['amount_minor' => $minor],
        ]);

        return back()->with('success', 'Wallet credited.');"""),

    ("app/Http/Controllers/AdminController.php",
     """        return back()->with('success', 'Wallet debited.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'wallet.debited', 'wallet', $wallet->id, [
            'target_user_id' => $user->id,
            'metadata' => ['amount_minor' => $minor],
        ]);

        return back()->with('success', 'Wallet debited.');"""),

    # ============================ SecurityController ============================
    ("app/Http/Controllers/SecurityController.php",
     "use App\\Services\\AntiCheatService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\AntiCheatService;"),

    ("app/Http/Controllers/SecurityController.php",
     """    public function __construct(
        protected RestrictionService $restrictions,
        protected IdentityVerificationService $identity,
        protected AntiCheatService $antiCheat,
        protected IpIntelligenceService $ipIntel,
    ) {
    }""",
     """    public function __construct(
        protected RestrictionService $restrictions,
        protected IdentityVerificationService $identity,
        protected AntiCheatService $antiCheat,
        protected IpIntelligenceService $ipIntel,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/SecurityController.php",
     """            $this->antiCheat->openIncident(
                $tournament,""",
     """            $incident = $this->antiCheat->openIncident(
                $tournament,"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return redirect()->route('security.incidents.index')->with('success', 'Anti-cheat incident opened.');""",
     """        $this->audit->recordQuietly($request->user(), 'anti_cheat.opened', 'anti_cheat_incident', $incident->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['category' => $data['category'], 'severity' => $data['severity']],
        ]);

        return redirect()->route('security.incidents.index')->with('success', 'Anti-cheat incident opened.');"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return back()->with('success', 'Incident resolved.');""",
     """        $this->audit->recordQuietly($request->user(), 'anti_cheat.resolved', 'anti_cheat_incident', $incident->id, [
            'target_user_id' => $incident->accused_user_id,
            'tournament_id' => $incident->tournament_id,
            'metadata' => ['resolution' => $data['resolution']],
        ]);

        return back()->with('success', 'Incident resolved.');"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return back()->with('success', 'Restriction applied.');""",
     """        $this->audit->recordQuietly($request->user(), 'restriction.applied', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['type' => $data['type']],
        ]);

        return back()->with('success', 'Restriction applied.');"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return back()->with('success', 'Restriction lifted.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'restriction.lifted', 'restriction', $restriction->id, [
            'target_user_id' => $restriction->user_id,
            'metadata' => ['type' => $restriction->type],
        ]);

        return back()->with('success', 'Restriction lifted.');"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return back()->with('success', 'Identity verified (manual review).');""",
     """        $this->audit->recordQuietly($request->user(), 'identity.verified', 'identity_verification', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Identity verified (manual review).');"""),

    ("app/Http/Controllers/SecurityController.php",
     """        return back()->with('success', 'Identity verification rejected.');""",
     """        $this->audit->recordQuietly($request->user(), 'identity.rejected', 'identity_verification', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Identity verification rejected.');"""),

    # ============================= PayoutController =============================
    ("app/Http/Controllers/PayoutController.php",
     "use App\\Services\\PayoutService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\PayoutService;"),

    ("app/Http/Controllers/PayoutController.php",
     """    public function __construct(
        protected PayoutService $payouts,
    ) {
    }""",
     """    public function __construct(
        protected PayoutService $payouts,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout approved.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.approved', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout approved.');"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout processed.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.processed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout processed.');"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout processed with fraud-review override.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.override', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payout processed with fraud-review override.');"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout marked completed.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.completed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reference' => $reference],
        ]);

        return back()->with('success', 'Payout marked completed.');"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout marked failed.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.failed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payout marked failed.');"""),

    ("app/Http/Controllers/PayoutController.php",
     """        return back()->with('success', 'Payout cancelled.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'payout.cancelled', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout cancelled.');"""),

    # =========================== SettlementController ===========================
    ("app/Http/Controllers/SettlementController.php",
     "use App\\Services\\PrizeDistributionService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\PrizeDistributionService;"),

    ("app/Http/Controllers/SettlementController.php",
     """    public function __construct(
        protected PrizeDistributionService $distributions,
        protected ReconciliationService $reconciliation,
    ) {
    }""",
     """    public function __construct(
        protected PrizeDistributionService $distributions,
        protected ReconciliationService $reconciliation,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/SettlementController.php",
     """        return back()->with('success', 'Prize configuration saved.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.tiers_saved', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['tiers' => count($rows)],
        ]);

        return back()->with('success', 'Prize configuration saved.');"""),

    ("app/Http/Controllers/SettlementController.php",
     """        return back()->with('success', 'Prize distribution calculated (' . Money::formatMinor($distribution->total_allocated_minor) . ' allocated).');""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.calculated', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['allocated_minor' => $distribution->total_allocated_minor],
        ]);

        return back()->with('success', 'Prize distribution calculated (' . Money::formatMinor($distribution->total_allocated_minor) . ' allocated).');"""),

    ("app/Http/Controllers/SettlementController.php",
     """        return back()->with('success', 'Prize distribution approved. Payouts created.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.approved', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Prize distribution approved. Payouts created.');"""),

    ("app/Http/Controllers/SettlementController.php",
     """        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.processed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['status' => $distribution->status],
        ]);

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {"""),

    ("app/Http/Controllers/SettlementController.php",
     """        return back()->with('success', 'Prize distribution cancelled.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.cancelled', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Prize distribution cancelled.');"""),

    ("app/Http/Controllers/SettlementController.php",
     """        return back()->with('success', 'Financial adjustment recorded.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'settlement.adjusted', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['amount_minor' => $minor, 'type' => $data['type']],
        ]);

        return back()->with('success', 'Financial adjustment recorded.');"""),

    # ============================ TournamentController ==========================
    ("app/Http/Controllers/TournamentController.php",
     "use App\\Services\\BracketService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\BracketService;"),

    ("app/Http/Controllers/TournamentController.php",
     """    public function __construct(
        protected TournamentLifecycleService $lifecycle,
        protected TournamentParticipationService $participation,
    ) {
    }""",
     """    public function __construct(
        protected TournamentLifecycleService $lifecycle,
        protected TournamentParticipationService $participation,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', 'Tournament published — registration is now open.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.published', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament published — registration is now open.');"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', 'Registration closed.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.registration_closed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Registration closed.');"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', "Bracket generated with {$count} matches. Tournament is LIVE!");""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.started', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['matches' => $count],
        ]);

        return back()->with('success', "Bracket generated with {$count} matches. Tournament is LIVE!");"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', 'Tournament marked as finished. Congratulations to the winners!');""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.completed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament marked as finished. Congratulations to the winners!');"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', 'Tournament cancelled.');""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.cancelled', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament cancelled.');"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', $message);""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.noshows', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => $result,
        ]);

        return back()->with('success', $message);"""),

    ("app/Http/Controllers/TournamentController.php",
     """        return back()->with('success', "{$team->name} promoted from the waitlist.");""",
     """        $this->audit->recordQuietly(auth()->user(), 'tournament.waitlist_promoted', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return back()->with('success', "{$team->name} promoted from the waitlist.");"""),

    # ============================== TeamController ==============================
    ("app/Http/Controllers/TeamController.php",
     "use App\\Services\\FraudRiskService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\FraudRiskService;"),

    ("app/Http/Controllers/TeamController.php",
     """    public function __construct(
        protected RosterService $roster,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
    ) {
    }""",
     """    public function __construct(
        protected RosterService $roster,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/TeamController.php",
     """        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $user, LiveEvent::TYPE_TEAM_REGISTERED, [
            'team' => $team->name,
            'waitlisted' => $waitlisted,
        ]);

        if ($waitlisted) {""",
     """        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $user, LiveEvent::TYPE_TEAM_REGISTERED, [
            'team' => $team->name,
            'waitlisted' => $waitlisted,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($user, 'team.registered', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name, 'waitlisted' => $waitlisted],
        ]);

        if ($waitlisted) {"""),

    ("app/Http/Controllers/TeamController.php",
     """        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $request->user(), LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        return back()->with('success', 'Your team has been withdrawn from the tournament.');""",
     """        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $request->user(), LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($request->user(), 'team.withdrawn', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return back()->with('success', 'Your team has been withdrawn from the tournament.');"""),

    ("app/Http/Controllers/TeamController.php",
     """        return back()->with('success', $member->player_name.' added to the roster.');""",
     """        $this->audit->recordQuietly($request->user(), 'team.member_added', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return back()->with('success', $member->player_name.' added to the roster.');"""),

    ("app/Http/Controllers/TeamController.php",
     """        return back()->with('success', 'Member removed from the roster.');""",
     """        $this->audit->recordQuietly($request->user(), 'team.member_removed', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return back()->with('success', 'Member removed from the roster.');"""),

    ("app/Http/Controllers/TeamController.php",
     """        return back()->with('success', 'Team profile updated.');""",
     """        $this->audit->recordQuietly($request->user(), 'team.updated', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Team profile updated.');"""),

    ("app/Http/Controllers/TeamController.php",
     """        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');""",
     """        $this->audit->recordQuietly($request->user(), 'team.checked_in', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');"""),

    # ============================== MatchController =============================
    ("app/Http/Controllers/MatchController.php",
     "use App\\Services\\MatchProgressionService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\MatchProgressionService;"),

    ("app/Http/Controllers/MatchController.php",
     """    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
        protected AntiCheatService $antiCheat,
    ) {
    }""",
     """    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
        protected AntiCheatService $antiCheat,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/MatchController.php",
     """        return back()->with('success', 'Score adjustment applied.');""",
     """        $this->audit->recordQuietly($request->user(), 'match.score_adjusted', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team_id' => $team->id, 'type' => $data['type'], 'points' => (int) $data['points']],
        ]);

        return back()->with('success', 'Score adjustment applied.');"""),

    ("app/Http/Controllers/MatchController.php",
     """        return back()->with('success', 'Winner confirmed. Bracket advanced.');""",
     """        $this->audit->recordQuietly($request->user(), 'match.winner_set', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['winner_team_id' => $winner->id],
        ]);

        return back()->with('success', 'Winner confirmed. Bracket advanced.');"""),

    ("app/Http/Controllers/MatchController.php",
     """        return back()->with('success', 'Match marked as disputed.');""",
     """        $this->audit->recordQuietly($request->user(), 'match.disputed', 'match', $match->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Match marked as disputed.');"""),

    ("app/Http/Controllers/MatchController.php",
     """        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');""",
     """        $this->audit->recordQuietly($request->user(), 'match.resolved', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['winner_team_id' => $winner->id],
        ]);

        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');"""),

    # ============================= DisputeController ============================
    ("app/Http/Controllers/DisputeController.php",
     "use App\\Services\\DisputeService;",
     "use App\\Services\\AuditLogService;\nuse App\\Services\\DisputeService;"),

    ("app/Http/Controllers/DisputeController.php",
     """    public function __construct(
        protected DisputeService $service,
        protected FraudRiskService $risk,
    ) {
    }""",
     """    public function __construct(
        protected DisputeService $service,
        protected FraudRiskService $risk,
        protected AuditLogService $audit,
    ) {
    }"""),

    ("app/Http/Controllers/DisputeController.php",
     """        return back()->with('success', 'Dispute cancelled. The original result stands.');""",
     """        $this->audit->recordQuietly($request->user(), 'dispute.cancelled', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute cancelled. The original result stands.');"""),

    ("app/Http/Controllers/DisputeController.php",
     """        return back()->with('success', 'Dispute is now under review.');""",
     """        $this->audit->recordQuietly($request->user(), 'dispute.under_review', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute is now under review.');"""),

    ("app/Http/Controllers/DisputeController.php",
     """        return back()->with('success', 'Dispute assigned to ' . $reviewer->name . '.');""",
     """        $this->audit->recordQuietly($request->user(), 'dispute.assigned', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['reviewer_id' => $reviewer->id],
        ]);

        return back()->with('success', 'Dispute assigned to ' . $reviewer->name . '.');"""),

    ("app/Http/Controllers/DisputeController.php",
     """        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute resolved and result finalized.');""",
     """        $this->audit->recordQuietly($request->user(), 'dispute.resolved', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute resolved and result finalized.');"""),

    ("app/Http/Controllers/DisputeController.php",
     """        return back()->with('success', 'Dispute rejected. The original result stands.');""",
     """        $this->audit->recordQuietly($request->user(), 'dispute.rejected', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute rejected. The original result stands.');"""),

    ("app/Http/Controllers/DisputeController.php",
     """        $this->service->removeEvidence($evidence, $request->user());

        return back()->with('success', 'Evidence removed.');""",
     """        $this->service->removeEvidence($evidence, $request->user());

        $this->audit->recordQuietly($request->user(), 'dispute.evidence_removed', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['evidence_id' => $evidence->id],
        ]);

        return back()->with('success', 'Evidence removed.');"""),
]


def main():
    failures = 0
    for path, old, new in REPLACEMENTS:
        full = f"{BASE}/{path}"
        try:
            with open(full, "r", encoding="utf-8") as fh:
                content = fh.read()
        except OSError as e:
            print(f"ERROR reading {path}: {e}")
            failures += 1
            continue

        count = content.count(old)
        if count != 1:
            print(f"FAIL {path}: needle found {count}x (expected 1):\n  {old[:80]!r}...")
            failures += 1
            continue

        content = content.replace(old, new, 1)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        print(f"OK   {path}: {old.strip().splitlines()[0][:60]!r}")

    if failures:
        print(f"\n{failures} replacement(s) FAILED.")
        sys.exit(1)

    print("\nAll replacements applied.")


if __name__ == "__main__":
    main()
