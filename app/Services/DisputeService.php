<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\ModerationEvent;
use App\Models\Notification;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dispute + evidence + moderation workflow (Phase 07).
 *
 * The single service that controls dispute lifecycle: opening, evidence,
 * assignment, review, resolution (including auditable result corrections via
 * the Phase 06 ScoringService) and the append-only moderation audit trail.
 *
 * Authorization is enforced by policies in the controllers; this service
 * enforces business rules and performs every state change atomically.
 */
class DisputeService
{
    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected NotificationService $notifications,
        protected LiveEventService $live,
    ) {}

    /**
     * Whether the user is staff for the given dispute (platform staff or the
     * owning tournament organizer).
     */
    public function isStaffFor(User $user, Dispute $dispute): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $dispute->tournament->organizer_id === $user->id;
    }

    /**
     * Open a dispute against a completed match.
     *
     * Participants (team captains) must open within the tournament's dispute
     * window; staff may open at any time. Opening moves the match into the
     * `disputed` state (Phase 05), halting bracket advancement.
     */
    public function open(GameMatch $match, ?Team $team, User $opener, string $category, string $description): Dispute
    {
        $category = trim($category);
        $description = trim($description);

        if (! in_array($category, Dispute::CATEGORIES, true)) {
            throw new DomainException('Please choose a valid dispute category.');
        }

        if ($description === '') {
            throw new DomainException('A dispute requires a description.');
        }

        $isStaff = $opener->isAdmin()
            || $opener->isModerator()
            || $match->tournament->organizer_id === $opener->id;

        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $alreadyOpen = Dispute::where('match_id', $match->id)
            ->whereIn('status', Dispute::ACTIONABLE_STATUSES)
            ->exists();

        if ($alreadyOpen) {
            throw new DomainException('This match already has an open dispute.');
        }

        $userTeam = $match->participantTeamFor($opener);

        if ($team !== null) {
            if (! $match->hasParticipant($team)) {
                throw new DomainException('The disputed team is not part of this match.');
            }

            if ($team->tournament_id !== $match->tournament_id) {
                throw new DomainException('The disputed team does not belong to this tournament.');
            }
        }

        if (! $isStaff) {
            // A participant may only open a dispute for their own team.
            if ($userTeam === null) {
                throw new DomainException('Only participating teams can open a dispute.');
            }

            if ($team !== null && $team->id !== $userTeam->id) {
                throw new DomainException('You can only open a dispute for your own team.');
            }

            $team = $userTeam;

            $this->assertWithinWindow($match);
        }

        $dispute = DB::transaction(function () use ($match, $team, $opener, $category, $description) {
            $dispute = new Dispute();
            $dispute->tournament_id = $match->tournament_id;
            $dispute->match_id = $match->id;
            $dispute->team_id = $team?->id;
            $dispute->opened_by = $opener->id;
            $dispute->category = $category;
            $dispute->description = $description;
            $dispute->status = Dispute::STATUS_OPEN;
            $dispute->save();

            // Halt bracket advancement while the result is contested.
            $this->progression->dispute($match);

            $this->recordEvent(
                $opener,
                ModerationEvent::EVENT_DISPUTE_OPENED,
                $dispute,
                $match,
                ['category' => $category, 'team_id' => $team?->id]
            );

            return $dispute;
        });

        // Phase 11 — notify the other captain, the organizer and the staff
        // queue (best-effort; never affects the dispute lifecycle).
        $this->notifyDisputeOpened($dispute, $match);

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($match->tournament, $opener, LiveEvent::TYPE_DISPUTE_OPENED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
            'category' => $category,
        ]);

        return $dispute;
    }

    /**
     * Attach a piece of evidence to an actionable dispute.
     */
    public function addEvidence(
        Dispute $dispute,
        User $submitter,
        string $type,
        ?string $description,
        ?UploadedFile $file
    ): DisputeEvidence {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is closed and no longer accepts evidence.');
        }

        if (! in_array($type, DisputeEvidence::TYPES, true)) {
            throw new DomainException('Invalid evidence type.');
        }

        $description = trim((string) $description);

        if ($type === DisputeEvidence::TYPE_TEXT) {
            if ($description === '') {
                throw new DomainException('A text explanation is required for text evidence.');
            }

            return $this->persistEvidence($dispute, $submitter, $type, null, null, null, 0, $description);
        }

        if ($file === null) {
            throw new DomainException('A file is required for this evidence type.');
        }

        $this->assertSafeFile($type, $file);

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = (string) Str::uuid().'.'.$extension;
        $directory = 'dispute_evidence/'.$dispute->id;

        try {
            $path = $file->storeAs($directory, $filename, 'local');
        } catch (\Throwable $e) {
            throw new DomainException('The evidence file could not be stored.');
        }

        try {
            return $this->persistEvidence(
                $dispute,
                $submitter,
                $type,
                $path,
                $file->getClientOriginalName(),
                $file->getMimeType(),
                $file->getSize(),
                $description
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Assign an actionable dispute to an authorized reviewer (admin or
     * moderator only — never a player).
     */
    public function assign(Dispute $dispute, User $reviewer, User $actor): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is closed and cannot be assigned.');
        }

        if (! $reviewer->isAdmin() && ! $reviewer->isModerator()) {
            throw new DomainException('Only admins and moderators can review disputes.');
        }

        DB::transaction(function () use ($dispute, $reviewer, $actor) {
            $dispute->assigned_to = $reviewer->id;
            $dispute->save();

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_ASSIGNED,
                $dispute,
                $dispute->match,
                ['reviewer_id' => $reviewer->id]
            );
        });
    }

    /**
     * Move an open dispute into under-review (staff).
     */
    public function markUnderReview(Dispute $dispute, User $actor): void
    {
        if ($dispute->status !== Dispute::STATUS_OPEN) {
            throw new DomainException('Only open disputes can move to under review.');
        }

        $this->transition($dispute, Dispute::STATUS_UNDER_REVIEW, $actor);
    }

    /**
     * Resolve a dispute: apply optional result corrections (winner and/or
     * score inputs) and finalize. The match returns to `completed` and the
     * bracket is re-advanced with the confirmed winner.
     *
     * @param  array<int, array{team_id:int, kills?:int|null, placement?:int|null}>  $corrections
     */
    public function resolve(Dispute $dispute, User $actor, Team $winner, string $resolution, array $corrections = []): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new DomainException('A resolution reason is required.');
        }

        $match = $dispute->match;

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('The confirmed winner must be a participating team.');
        }

        DB::transaction(function () use ($dispute, $actor, $winner, $resolution, $corrections, $match) {
            $applied = $this->applyCorrections($dispute, $actor, $corrections);

            $dispute->status = Dispute::STATUS_RESOLVED;
            $dispute->resolution = $resolution;
            $dispute->resolved_by = $actor->id;
            $dispute->resolution_winner_team_id = $winner->id;
            $dispute->resolved_at = now();
            $dispute->save();

            // Move the match back to completed and re-advance the bracket
            // with the (possibly corrected) winner.
            $this->progression->resolve($match, $winner);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_RESOLVED,
                $dispute,
                $match,
                ['winner_team_id' => $winner->id, 'corrections' => $applied]
            );
        });

        // Phase 11 — inform the participants of the final decision.
        $this->notifyDisputeClosed(
            $dispute,
            $match,
            'Dispute resolved',
            'A dispute on your match in '.$match->tournament->name.' was resolved.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
            'status' => Dispute::STATUS_RESOLVED,
        ]);
    }

    /**
     * Reject a dispute: the existing result is upheld. The match returns to
     * `completed` with its original winner untouched.
     */
    public function reject(Dispute $dispute, User $actor, string $resolution): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new DomainException('A rejection reason is required.');
        }

        DB::transaction(function () use ($dispute, $actor, $resolution) {
            $dispute->status = Dispute::STATUS_REJECTED;
            $dispute->resolution = $resolution;
            $dispute->resolved_by = $actor->id;
            $dispute->resolved_at = now();
            $dispute->save();

            $this->restoreMatch($dispute->match);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_REJECTED,
                $dispute,
                $dispute->match,
                []
            );
        });

        // Phase 11 — inform the participants the original result stands.
        $this->notifyDisputeClosed(
            $dispute,
            $dispute->match,
            'Dispute rejected',
            'A dispute on your match in '.$dispute->match->tournament->name.' was rejected; the original result stands.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($dispute->match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $dispute->match->match_no,
            'round' => (int) $dispute->match->round,
            'status' => Dispute::STATUS_REJECTED,
        ]);
    }

    /**
     * Cancel a dispute. Allowed for staff (open or under review) and for the
     * opener while the dispute is still open. The match returns to
     * `completed` with its original winner untouched.
     */
    public function cancel(Dispute $dispute, User $actor): void
    {
        $isStaff = $this->isStaffFor($actor, $dispute);

        if (! $isStaff && $dispute->opened_by !== $actor->id) {
            throw new DomainException('You cannot cancel this dispute.');
        }

        if (! $isStaff && $dispute->status !== Dispute::STATUS_OPEN) {
            throw new DomainException('Only open disputes can be cancelled by their owner.');
        }

        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        DB::transaction(function () use ($dispute, $actor) {
            $dispute->status = Dispute::STATUS_CANCELLED;
            $dispute->resolved_by = $actor->id;
            $dispute->resolved_at = now();
            $dispute->save();

            $this->restoreMatch($dispute->match);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_CANCELLED,
                $dispute,
                $dispute->match,
                []
            );
        });

        // Phase 11 — inform the participants the dispute was cancelled.
        $this->notifyDisputeClosed(
            $dispute,
            $dispute->match,
            'Dispute cancelled',
            'A dispute on your match in '.$dispute->match->tournament->name.' was cancelled.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($dispute->match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $dispute->match->match_no,
            'round' => (int) $dispute->match->round,
            'status' => Dispute::STATUS_CANCELLED,
        ]);
    }

    /**
     * Remove a piece of evidence (privileged moderation action). The file is
     * deleted from private storage and the event is audited.
     */
    public function removeEvidence(DisputeEvidence $evidence, User $actor): void
    {
        DB::transaction(function () use ($evidence, $actor) {
            $path = $evidence->path;
            $dispute = $evidence->dispute;
            $match = $dispute?->match;
            $submittedBy = $evidence->submitted_by;

            $evidence->delete();

            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            if ($dispute !== null) {
                $this->recordEvent(
                    $actor,
                    ModerationEvent::EVENT_EVIDENCE_REMOVED,
                    $dispute,
                    $match,
                    ['evidence_id' => $evidence->id, 'submitted_by' => $submittedBy]
                );
            }
        });
    }

    // ------------------------------------------------------------------
    // Phase 11 — notifications
    // ------------------------------------------------------------------

    /**
     * Notify the other participating captain, the organizer and the staff
     * queue when a dispute is opened.
     */
    protected function notifyDisputeOpened(Dispute $dispute, GameMatch $match): void
    {
        $tournament = $match->tournament;
        $link = NotificationService::link('matches.disputes.show', [$tournament, $match, $dispute]);
        $openerId = $dispute->opened_by;

        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;

            if ($captain !== null && $captain->id !== $openerId) {
                $this->notifications->send(
                    $captain,
                    Notification::TYPE_DISPUTE_OPENED,
                    'Dispute opened on your match',
                    'A dispute has been opened on your match in '.$tournament->name.'.',
                    $link,
                    ['dispute_id' => $dispute->id],
                );
            }
        }

        $organizer = $tournament->organizer;

        if ($organizer !== null && $organizer->id !== $openerId) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_DISPUTE_OPENED,
                'New dispute in your tournament',
                'A dispute was opened in '.$tournament->name.'.',
                $link,
                ['dispute_id' => $dispute->id],
            );
        }

        $staff = User::whereIn('role', ['admin', 'moderator'])->get();

        foreach ($staff as $member) {
            if ($member->id !== $openerId) {
                $this->notifications->send(
                    $member,
                    Notification::TYPE_DISPUTE_OPENED,
                    'Dispute awaiting review',
                    'A new dispute needs review in '.$tournament->name.'.',
                    $link,
                    ['dispute_id' => $dispute->id],
                );
            }
        }
    }

    /**
     * Notify both participating captains (and the opener) that a dispute
     * reached a final decision.
     */
    protected function notifyDisputeClosed(Dispute $dispute, GameMatch $match, string $title, string $body): void
    {
        $tournament = $match->tournament;
        $link = NotificationService::link('matches.disputes.show', [$tournament, $match, $dispute]);

        $recipients = [];

        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;

            if ($captain !== null) {
                $recipients[$captain->id] = $captain;
            }
        }

        $opener = User::find($dispute->opened_by);

        if ($opener !== null) {
            $recipients[$opener->id] = $opener;
        }

        $this->notifications->sendToMany(
            $recipients,
            Notification::TYPE_DISPUTE_RESOLVED,
            $title,
            $body,
            $link,
            ['dispute_id' => $dispute->id, 'status' => $dispute->status],
        );
    }

    /**
     * Append a row to the moderation audit trail.
     */
    public function recordEvent(
        ?User $actor,
        string $event,
        ?Dispute $dispute,
        ?GameMatch $match,
        array $metadata = []
    ): ModerationEvent {
        $record = new ModerationEvent();
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->dispute_id = $dispute?->id;
        $record->match_id = $match?->id;
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Apply result corrections through the scoring engine. Each correction
     * must reference a participating team that has a score in this match.
     *
     * @return int number of corrections applied
     */
    protected function applyCorrections(Dispute $dispute, User $actor, array $corrections): int
    {
        if ($corrections === []) {
            return 0;
        }

        $match = $dispute->match;
        $applied = 0;

        foreach ($corrections as $correction) {
            $teamId = (int) ($correction['team_id'] ?? 0);
            $kills = isset($correction['kills']) ? (int) $correction['kills'] : null;
            $placement = isset($correction['placement']) ? (int) $correction['placement'] : null;

            if ($teamId <= 0) {
                throw new DomainException('A correction must reference a team.');
            }

            $team = Team::find($teamId);

            if ($team === null || ! $match->hasParticipant($team)) {
                throw new DomainException('A correction can only target a participating team.');
            }

            $score = Score::where('match_id', $match->id)->where('team_id', $teamId)->first();

            if ($score === null) {
                throw new DomainException('No score exists for this team in this match.');
            }

            $before = [
                'kills' => (int) $score->kills,
                'placement' => (int) $score->placement,
                'points' => (int) $score->points,
            ];

            $this->scoring->correctScore($score, $kills, $placement);

            $applied++;

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_RESULT_CORRECTED,
                $dispute,
                $match,
                [
                    'team_id' => $teamId,
                    'before' => $before,
                    'after' => [
                        'kills' => (int) $score->kills,
                        'placement' => (int) $score->placement,
                        'points' => (int) $score->points,
                    ],
                    'scoring_rules_id' => (int) $score->scoring_rules_id,
                ]
            );
        }

        return $applied;
    }

    /**
     * Move a dispute between non-terminal states and audit the change.
     */
    protected function transition(Dispute $dispute, string $target, User $actor): void
    {
        if (! $dispute->canTransitionTo($target)) {
            throw new DomainException('Invalid dispute transition.');
        }

        DB::transaction(function () use ($dispute, $target, $actor) {
            $from = $dispute->status;

            $dispute->status = $target;
            $dispute->save();

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_STATUS_CHANGED,
                $dispute,
                $dispute->match,
                ['from' => $from, 'to' => $target]
            );
        });
    }

    /**
     * Return a disputed match to completed without changing its winner
     * (used when a dispute is rejected or cancelled — the original result
     * is upheld).
     */
    protected function restoreMatch(GameMatch $match): void
    {
        if ($match->status === GameMatch::STATUS_DISPUTED) {
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->save();
        }
    }

    /**
     * Enforce the participant dispute window.
     */
    protected function assertWithinWindow(GameMatch $match): void
    {
        $hours = $match->tournament->disputeWindowHours();

        if ($hours <= 0) {
            throw new DomainException('The dispute window for this tournament is closed.');
        }

        $completedAt = $match->completed_at ?? $match->updated_at;

        if ($completedAt !== null && $completedAt->copy()->addHours($hours)->isPast()) {
            throw new DomainException('The dispute window has expired for this match.');
        }
    }

    /**
     * Server-side file safety re-check (defense in depth on top of request
     * validation): extension and MIME must match the declared evidence type.
     */
    protected function assertSafeFile(string $type, UploadedFile $file): void
    {
        if ($file->getSize() > DisputeEvidence::MAX_KB * 1024) {
            throw new DomainException('Evidence files must be smaller than '.DisputeEvidence::MAX_KB.' KB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, DisputeEvidence::ALLOWED_EXTENSIONS[$type] ?? [], true)) {
            throw new DomainException('This file type is not allowed for the selected evidence type.');
        }

        $mime = $file->getMimeType();

        if (! in_array($mime, DisputeEvidence::ALLOWED_MIME_TYPES[$type] ?? [], true)) {
            throw new DomainException('This file type is not allowed for the selected evidence type.');
        }
    }

    /**
     * Create the evidence record and audit it atomically.
     */
    protected function persistEvidence(
        Dispute $dispute,
        User $submitter,
        string $type,
        ?string $path,
        ?string $originalName,
        ?string $mimeType,
        int $size,
        ?string $description
    ): DisputeEvidence {
        return DB::transaction(function () use ($dispute, $submitter, $type, $path, $originalName, $mimeType, $size, $description) {
            $evidence = new DisputeEvidence();
            $evidence->dispute_id = $dispute->id;
            $evidence->submitted_by = $submitter->id;
            $evidence->type = $type;
            $evidence->path = $path;
            $evidence->original_name = $originalName;
            $evidence->mime_type = $mimeType;
            $evidence->size = $size;
            $evidence->description = $description ?: null;
            $evidence->save();

            $this->recordEvent(
                $submitter,
                ModerationEvent::EVENT_EVIDENCE_ADDED,
                $dispute,
                $dispute->match,
                ['evidence_id' => $evidence->id, 'type' => $type]
            );

            return $evidence;
        });
    }
}
