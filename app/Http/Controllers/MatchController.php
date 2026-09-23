<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AntiCheatService;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
        protected AntiCheatService $antiCheat,
        protected AuditLogService $audit,
    ) {}

    public function show(Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $match->load([
            'team1',
            'team2',
            'scores.team',
            'scores.adjustments',
            'scores.scoringRule',
            'nextMatch',
            'loserNextMatch',
            'disputes' => fn ($q) => $q->orderByDesc('created_at'),
            'disputes.opener',
        ]);

        // Whether the current user may open a Phase 07 dispute against this
        // match. Only relevant once the match is completed/disputed.
        $canOpenDispute = false;
        if (auth()->check() && in_array($match->status, [GameMatch::STATUS_COMPLETED, GameMatch::STATUS_DISPUTED], true)) {
            $canOpenDispute = auth()->user()->isAdmin()
                || auth()->user()->isModerator()
                || $tournament->organizer_id === auth()->id()
                || $match->participantTeamFor(auth()->user()) !== null;
        }

        return view('matches.show', compact('tournament', 'match', 'canOpenDispute'));
    }

    /**
     * Publish room details and move a ready/pending match to live.
     */
    public function setRoom(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'room_id' => 'required|string|max:30',
            'room_pass' => 'required|string|max:30',
            'scheduled_at' => 'nullable|date',
        ]);

        $match->room_id = $data['room_id'];
        $match->room_pass = $data['room_pass'];
        $match->scheduled_at = $data['scheduled_at'] ?? now();
        $match->save();

        try {
            $this->progression->start($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Room details published. Match is now live.');
    }

    public function submitScore(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'kills' => 'required|integer|min:0',
            'placement' => 'required|integer|min:1|max:'.ScoringRule::MAX_PLACEMENT,
            'screenshot' => 'nullable|image|max:2048',
        ]);

        $team = Team::find($data['team_id']);

        // The submitted team MUST be an actual participant of this match.
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $this->authorize('submitScore', $team);

        // Phase 10 — fraud/risk gate for score submission.
        try {
            $this->risk->gate($request->user(), 'score_submission', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Scoring is only possible while the match is ready or live.
        abort_unless(
            $match->acceptsScoreSubmission(),
            403,
            'Score submission is not open for this match.'
        );

        // Prevent duplicate/unauthorized score replacement.
        if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
            abort(403, 'A score for this team has already been submitted.');
        }

        // A Free Fire placement is unique within a match.
        if (Score::where('match_id', $match->id)->where('placement', (int) $data['placement'])->exists()) {
            abort(403, 'Another team in this match has already claimed that placement.');
        }

        $path = null;
        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->store('scores', 'public');
        }

        try {
            // The server computes every point — the client's values are only
            // raw inputs (kills + placement).
            $this->scoring->submitScore(
                $match,
                $team,
                (int) $data['kills'],
                (int) $data['placement'],
                $path
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Phase 10 — deterministic anomaly analysis (observation only; never
        // an accusation and never throws into the request path).
        try {
            $this->antiCheat->analyzeScoreSubmission($match, $team, (int) $data['kills'], (int) $data['placement']);
        } catch (\Throwable $e) {
            // Anomaly detection must never break score submission.
        }

        return back()->with('success', 'Score submitted! Awaiting verification.');
    }

    /**
     * Apply an auditable bonus/penalty to a team's score (privileged).
     */
    public function addAdjustment(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'type' => 'required|in:bonus,penalty',
            'points' => 'required|integer|min:1|max:1000',
            'reason' => 'required|string|max:255',
        ]);

        $team = Team::find($data['team_id']);
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $score = Score::where('match_id', $match->id)->where('team_id', $team->id)->first();
        abort_unless($score !== null, 404, 'No score found for this team in this match.');

        try {
            $this->scoring->addAdjustment($score, $data['type'], (int) $data['points'], $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'match.score_adjusted', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team_id' => $team->id, 'type' => $data['type'], 'points' => (int) $data['points']],
        ]);

        return back()->with('success', 'Score adjustment applied.');
    }

    /**
     * Record a winner, complete the match and advance the bracket.
     * Idempotent: completing again with the same winner is a no-op.
     */
    public function setWinner(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);

        // Winner must be one of the actual participating teams.
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $result = $this->progression->complete($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'This match is already completed with that winner.');
        }

        $this->audit->recordQuietly($request->user(), 'match.winner_set', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['winner_team_id' => $winner->id],
        ]);

        return back()->with('success', 'Winner confirmed. Bracket advanced.');
    }

    /**
     * Move a completed match into the disputed state (privileged).
     */
    public function dispute(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        try {
            $this->progression->dispute($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'match.disputed', 'match', $match->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Match marked as disputed.');
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner (privileged).
     */
    public function resolve(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $this->progression->resolve($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'match.resolved', 'match', $match->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['winner_team_id' => $winner->id],
        ]);

        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');
    }
}
