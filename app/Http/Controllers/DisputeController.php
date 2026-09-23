<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\RiskEvent;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DisputeService;
use App\Services\FraudRiskService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $service,
        protected FraudRiskService $risk,
        protected AuditLogService $audit,
    ) {}

    /**
     * Show the "open a dispute" form.
     */
    public function create(Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('openDispute', $match);

        $user = auth()->user();
        $userTeam = $match->participantTeamFor($user);
        $existing = Dispute::where('match_id', $match->id)
            ->whereIn('status', Dispute::ACTIONABLE_STATUSES)
            ->first();

        $categories = Dispute::CATEGORIES;

        return view('disputes.create', compact('tournament', 'match', 'userTeam', 'existing', 'categories'));
    }

    /**
     * Open a dispute (optionally with a first piece of evidence).
     */
    public function store(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('openDispute', $match);

        $data = $request->validate([
            'category' => 'required|in:'.implode(',', Dispute::CATEGORIES),
            'description' => 'required|string|max:5000',
            'team_id' => 'nullable|integer|exists:teams,id',
            'evidence_type' => 'nullable|in:'.implode(',', DisputeEvidence::TYPES),
            'evidence_description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:'.DisputeEvidence::MAX_KB
                .'|mimetypes:'.$this->allowedMimeTypes(),
        ]);

        $user = $request->user();
        $team = ! empty($data['team_id']) ? Team::find($data['team_id']) : null;

        try {
            $dispute = $this->service->open($match, $team, $user, $data['category'], $data['description']);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if (! empty($data['evidence_type'])) {
            try {
                $this->service->addEvidence(
                    $dispute,
                    $user,
                    $data['evidence_type'],
                    $data['evidence_description'] ?? null,
                    $request->file('evidence_file')
                );
            } catch (DomainException $e) {
                return redirect()
                    ->route('matches.disputes.show', [$tournament, $match, $dispute])
                    ->with('error', 'Dispute opened, but the evidence was not attached: '.$e->getMessage());
            }
        }

        // Phase 10 — repeated-dispute signal (non-blocking observation).
        $disputeCount = Dispute::where('opened_by', $user->id)->count();
        $threshold = (int) config('antifraud.dispute.repeat_threshold', 3);

        if ($disputeCount >= $threshold) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_DISPUTE_REPEAT, RiskEvent::SEVERITY_LOW, 'dispute', [
                'dispute_count' => $disputeCount,
            ], $tournament);
        }

        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute opened. It will be reviewed by a moderator.');
    }

    /**
     * Show a dispute: status, description, evidence, timeline and (for
     * authorized actors) the relevant action forms.
     */
    public function show(Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('view', $dispute);

        $dispute->load(['opener', 'assignee', 'resolver', 'team', 'resolutionWinner', 'evidence.submitter', 'events.actor']);
        $match->load(['team1', 'team2', 'scores.team', 'scores.adjustments']);

        $user = auth()->user();
        $isStaff = $user !== null && $this->service->isStaffFor($user, $dispute);
        $isOpener = $user !== null && $dispute->opened_by === $user->id;
        $reviewers = collect();

        if ($isStaff) {
            $reviewers = User::whereIn('role', ['admin', 'moderator'])->orderBy('name')->get();
        }

        return view('disputes.show', compact('tournament', 'match', 'dispute', 'isStaff', 'isOpener', 'reviewers'));
    }

    /**
     * Attach evidence to an actionable dispute.
     */
    public function addEvidence(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('addEvidence', $dispute);

        $data = $request->validate([
            'type' => 'required|in:'.implode(',', DisputeEvidence::TYPES),
            'description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:'.DisputeEvidence::MAX_KB
                .'|mimetypes:'.$this->allowedMimeTypes(),
        ]);

        try {
            $this->service->addEvidence(
                $dispute,
                $request->user(),
                $data['type'],
                $data['description'] ?? null,
                $request->file('evidence_file')
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Evidence added.');
    }

    /**
     * Stream a piece of evidence from private storage (authorized only).
     */
    public function evidence(Tournament $tournament, GameMatch $match, Dispute $dispute, DisputeEvidence $evidence)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        abort_unless($evidence->dispute_id === $dispute->id, 404);
        $this->authorize('viewEvidence', [$dispute, $evidence]);

        if ($evidence->path === null || ! Storage::disk('local')->exists($evidence->path)) {
            abort(404);
        }

        return Storage::disk('local')->response($evidence->path);
    }

    /**
     * Cancel a dispute (staff, or the opener while it is still open).
     */
    public function cancel(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('cancel', $dispute);

        try {
            $this->service->cancel($dispute, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'dispute.cancelled', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute cancelled. The original result stands.');
    }

    /**
     * Move an open dispute to under review (staff).
     */
    public function review(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('review', $dispute);

        try {
            $this->service->markUnderReview($dispute, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'dispute.under_review', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute is now under review.');
    }

    /**
     * Assign a reviewer to the dispute (staff).
     */
    public function assign(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('assign', $dispute);

        $data = $request->validate([
            'reviewer_id' => 'required|integer|exists:users,id',
        ]);

        $reviewer = User::findOrFail($data['reviewer_id']);

        try {
            $this->service->assign($dispute, $reviewer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'dispute.assigned', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['reviewer_id' => $reviewer->id],
        ]);

        return back()->with('success', 'Dispute assigned to '.$reviewer->name.'.');
    }

    /**
     * Resolve a dispute (staff): confirm/correct the winner, optionally
     * correct score inputs through the scoring engine, and finalize.
     */
    public function resolve(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('resolve', $dispute);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
            'resolution' => 'required|string|max:5000',
            'corrections' => 'nullable|array',
            'corrections.*.team_id' => 'required_with:corrections|integer|exists:teams,id',
            'corrections.*.kills' => 'nullable|integer|min:0',
            'corrections.*.placement' => 'nullable|integer|min:1|max:'.ScoringRule::MAX_PLACEMENT,
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);

        // The confirmed winner must be a participant — never trusted blindly.
        abort_unless($match->hasParticipant($winner), 403, 'The confirmed winner must be a participating team.');

        try {
            $this->service->resolve(
                $dispute,
                $request->user(),
                $winner,
                $data['resolution'],
                $data['corrections'] ?? []
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'dispute.resolved', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute resolved and result finalized.');
    }

    /**
     * Reject a dispute (staff): the existing result is upheld.
     */
    public function reject(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('reject', $dispute);

        $data = $request->validate([
            'resolution' => 'required|string|max:5000',
        ]);

        try {
            $this->service->reject($dispute, $request->user(), $data['resolution']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'dispute.rejected', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Dispute rejected. The original result stands.');
    }

    /**
     * Remove a piece of evidence (privileged moderation action).
     */
    public function removeEvidence(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute, DisputeEvidence $evidence)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        abort_unless($evidence->dispute_id === $dispute->id, 404);
        $this->authorize('removeEvidence', [$dispute, $evidence]);

        $this->service->removeEvidence($evidence, $request->user());

        $this->audit->recordQuietly($request->user(), 'dispute.evidence_removed', 'dispute', $dispute->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['evidence_id' => $evidence->id],
        ]);

        return back()->with('success', 'Evidence removed.');
    }

    /**
     * The MIME whitelist for evidence uploads (used by request validation).
     */
    protected function allowedMimeTypes(): string
    {
        return implode(',', array_merge(
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_IMAGE],
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_VIDEO],
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_DOCUMENT],
        ));
    }
}
