# Phase 07 — Dispute + Evidence + Moderation System Report

**Project:** FF Arena (Laravel 12 + SQLite) · **Date:** 2026-09-06
**Scope:** A production-grade, controlled dispute → evidence → review →
resolution workflow (with auditable result correction) layered on the existing
Phase 05 `disputed` match state and the Phase 06 scoring engine, without
regressing Phases 01–06. Phase 08+ features are out of scope.

---

## 1. Existing Dispute Audit

Before any change, the Phase 05 foundation was inspected in place:

- `GameMatch` — `STATUS_DISPUTED = 'disputed'`; transitions
  `completed → disputed` and `disputed → completed|live`.
- `MatchProgressionService::dispute()` — organizer/admin-only move of a
  *completed* match into `disputed` (no participant path; no evidence; no
  reason; no audit).
- `MatchProgressionService::resolve($match, $winner)` — moves `disputed →
  completed` with a (possibly corrected) winner and re-advances the bracket,
  replacing a stale winner in downstream slots.
- `GameMatchPolicy::manage/dispute/resolve` — all privileged (admin or owning
  organizer). **Players could never dispute or resolve.**
- `MatchController::dispute/resolve` — thin privileged wrappers around the
  service (kept unchanged for Phase 05 compatibility).
- Scores — during `disputed` state the Phase 06 `ScoringService::standings()`
  already excludes the match; scores are otherwise untouched during dispute.
- No dispute entity, no evidence, no moderation queue, no audit trail, no
  `completed_at` timestamp, no dispute window, no `moderator` role existed.

**Conclusion:** the `disputed` state was only a bracket-halting flag with
privileged resolve; Phase 07 turns it into a full controlled workflow.

## 2. Architecture

- **`Dispute`** — the domain entity: tournament + match + optional team +
  opener, category/description, status, optional assignee, resolution + resolver
  + resolved_at + resolution winner.
- **`DisputeEvidence`** — immutable evidence (image/video/document file on the
  private `local` disk, or a `text` explanation), submitted by an authorized
  actor.
- **`ModerationEvent`** — append-only audit trail (actor, event, dispute/match,
  structured metadata).
- **`DisputeService`** — single workflow service: open, add/remove evidence,
  assign, review, resolve (with corrections), reject, cancel, plus audit
  recording. Every state change is transactional.
- **`DisputePolicy` / `GameMatchPolicy::openDispute`** — authorization layer.
- **`DisputeController` / `ModerationController`** — thin controllers; all
  nested resources are validated against their parents (never trusting route
  model binding alone).
- **`ScoringService::correctScore()`** — the only sanctioned result-correction
  path, reusing the Phase 06 engine and preserving each score's rule snapshot.
- **Role model** — a `moderator` role granted only by admins (never
  self-assigned, never mass-assignable); `User::isStaff()` = admin|moderator.

## 3. Database Changes

Migration `2026_09_04_160000_add_dispute_moderation.php`:

- `disputes` — id; tournament_id (FK cascade); match_id (FK cascade);
  team_id (nullable FK nullOnDelete); opened_by (nullable FK nullOnDelete);
  category; description (text); status (default `open`);
  assigned_to (nullable FK nullOnDelete); resolution (text nullable);
  resolved_by (nullable FK nullOnDelete);
  resolution_winner_team_id (nullable FK nullOnDelete);
  resolved_at (nullable); timestamps; indexes on tournament/match/status/assigned.
- `dispute_evidence` — id; dispute_id (FK cascade); submitted_by (nullable FK
  nullOnDelete); type; path (nullable); original_name; mime_type; size;
  description; timestamps; index on dispute_id.
- `moderation_events` — id; actor_id (nullable FK nullOnDelete); event;
  dispute_id (nullable FK nullOnDelete); match_id (nullable FK nullOnDelete);
  metadata (json); timestamps; indexes on dispute/match/event.
- `matches.completed_at` — nullable timestamp (dispute-window anchor).
- `tournaments.dispute_window_hours` — unsigned int default 24.

Cascades: deleting a tournament removes its disputes/evidence (via FK cascade
through matches/disputes). Deleting a dispute removes its evidence. Historical
rows are never silently destroyed by the workflow itself.

## 4. Dispute Workflow

1. A completed match can be disputed by (a) a participating team captain, or
   (b) staff (admin/moderator/organizer).
2. Opening moves the match `completed → disputed` (halting bracket
   advancement) and records `dispute.opened`.
3. Staff may move `open → under_review`, assign a reviewer (admin/moderator),
   and finally `resolve` (uphold or correct) or `reject` or `cancel`.
4. Resolve/reject/cancel return the match to `completed`; resolve optionally
   re-advances the bracket with a corrected winner.
5. All transitions are validated by `Dispute::TRANSITIONS`; terminal states
   (`resolved`, `rejected`, `cancelled`) are immutable.

## 5. Evidence System

- Types: `image`, `video`, `document` (file) and `text` (explanation only).
- Each record: dispute_id, submitted_by, type, private-disk path, original
  name, MIME, size, description, timestamps.
- Evidence is immutable: there is no replace/update route. Removal is a
  privileged staff action that deletes the file + row and is audited.

## 6. Secure Storage

- Files are stored on the **private `local` disk** (`storage/app/private`)
  under `dispute_evidence/{dispute_id}/{uuid}.{ext}` — never in the public
  disk, never web-served directly.
- Generated UUID filenames; the client's original filename is stored only as
  display metadata.
- Double validation: request-level (`file`, `max:10240`, `mimetypes:…`) plus a
  service-level re-check of extension and MIME against per-type whitelists.
- Rejected: executables, wrong MIME, wrong extension, oversized files.

## 7. Access Control

- Evidence viewing (`matches.disputes.evidence.show`) streams from private
  storage only after policy authorization; guests are redirected to login;
  unrelated users get 403; cross-tournament addressing returns 404.
- View gate: staff, the opener, or a participating team captain.

## 8. State Machine

`open → under_review | resolved | rejected | cancelled` and
`under_review → resolved | rejected | cancelled`. Terminal states have no
outgoing transitions. Staff perform review/resolve/reject; staff or the opener
(while `open`) may cancel. Every transition verifies current state, actor
authorization and match/tournament context, and is audited.

## 9. Moderation Queue

`GET /moderation` — staff only. Admins/moderators see the global queue;
organizers are scoped to their own tournaments. Filters: status + tournament.
A compact table lists dispute, tournament, match, team, category, status,
reviewer, and a Review link.

## 10. Reviewer Assignment

`POST …/assign` (staff). Targets must be admins or moderators — assigning a
player is rejected by the service. Participants can never assign (policy 403),
so self-assignment is impossible.

## 11. Resolution Workflow

`POST …/resolve` (staff) requires a confirmed winner (must be a participant)
and a resolution reason. Optionally carries score corrections. Reject/cancel
uphold the original result and return the match to `completed` unchanged.

## 12. Result Correction

Corrections are applied through `ScoringService::correctScore()`, which:
rejects non-disputed/completed matches, negative kills, impossible
placements, and duplicate placements; recalculates placement/kill/bonus/
penalty/total from the score's **own** scoring-rule snapshot (version
preserved); and ignores any client-supplied totals. Each correction writes a
`result.corrected` audit event with before/after values and the rule id.

## 13. ScoringService Integration

`ScoringService::correctScore(Score $score, ?int $kills, ?int $placement)`
added; it delegates to the existing `recompute()` so no second formula exists.
Leaderboard/standings are untouched and continue to consume the engine.

## 14. Audit Trail

`moderation_events` records: `dispute.opened`, `dispute.evidence_added`,
`dispute.evidence_removed`, `dispute.status_changed`, `dispute.assigned`,
`dispute.resolved`, `dispute.rejected`, `dispute.cancelled`,
`result.corrected` — each with actor and structured metadata. No secrets or
tokens are stored; rows are only written by DisputeService.

## 15. Authorization / Security

- `GameMatchPolicy::openDispute` — staff or participating captain.
- `DisputePolicy` — `view`, `addEvidence`, `viewEvidence`, `manage`,
  `review`, `assign`, `resolve`, `reject`, `cancel`, `removeEvidence`.
- `User::isModerator()/isStaff()` added; `role` remains non-mass-assignable;
  moderator grant/revoke sit behind the `admin` middleware.
- Cross-tournament protection: every dispute/evidence route 404-guards the
  match↔tournament and dispute↔match (and evidence↔dispute) relationships.
- Client-supplied `status`/`assigned_to`/`opened_by`/`resolved_by` are ignored
  on open (models are not mass-assignable).

## 16. Routes

New routes (14): dispute create/store/show, evidence store/show/remove,
cancel, review, assign, resolve, reject, `moderation.index`, and admin
`users.moderate` / `users.unmoderate`. Total route count: **61** (47 baseline).

## 17. Blade UI

New: `disputes/create.blade.php` (open-dispute form), `disputes/show.blade.php`
(dispute detail + evidence + timeline + staff/participant actions),
`moderation/index.blade.php` (queue with filters). Updated:
`matches/show.blade.php` (disputes list + Open Dispute), `layouts/app.blade.php`
(Moderation nav), `admin/dashboard.blade.php` (moderator management),
`tournaments/create|edit.blade.php` (dispute window). Existing FF Arena style
and pill classes reused.

## 18. Files Created

- `app/Models/Dispute.php`
- `app/Models/DisputeEvidence.php`
- `app/Models/ModerationEvent.php`
- `app/Services/DisputeService.php`
- `app/Policies/DisputePolicy.php`
- `app/Http/Controllers/DisputeController.php`
- `app/Http/Controllers/ModerationController.php`
- `database/migrations/2026_09_04_160000_add_dispute_moderation.php`
- `resources/views/disputes/create.blade.php`
- `resources/views/disputes/show.blade.php`
- `resources/views/moderation/index.blade.php`
- `tests/Feature/DisputeSystemTest.php`
- `tests/Feature/DisputeSecurityTest.php`

## 19. Files Modified

- `app/Models/User.php` (moderator/staff helpers)
- `app/Models/GameMatch.php` (disputes relation, participant helpers, completed_at, isDisputed)
- `app/Models/Tournament.php` (disputes relation, dispute_window_hours)
- `app/Services/MatchProgressionService.php` (completed_at on complete/resolve)
- `app/Services/ScoringService.php` (correctScore)
- `app/Policies/GameMatchPolicy.php` (openDispute)
- `app/Http/Controllers/MatchController.php` (load disputes + canOpenDispute)
- `app/Http/Controllers/AdminController.php` (moderator grant/revoke)
- `app/Http/Controllers/TournamentController.php` (dispute_window_hours)
- `routes/web.php` (dispute + moderation + admin role routes)
- `resources/views/matches/show.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/tournaments/create.blade.php`
- `resources/views/tournaments/edit.blade.php`

## 20. Tests Added

`DisputeSystemTest` (31 tests) — creation (participant/organizer/admin,
unrelated player/other captain blocked, cross-tournament blocked, duplicate
blocked), dispute window (expired, staff bypass, zero-window), evidence (text,
image to private disk, document, blocked after resolution), state machine
(valid + invalid transitions, participant forbidden), assignment (player
rejected, moderator accepted, self-assign blocked), resolution (reason
required, winner corrected + bracket slot reopened, reject restores, opener
cancels), result correction (recalculates + preserves rule version, ignores
client points, rejects non-participant team, cross-tournament blocked), audit
trail, moderation queue (staff-only, organizer scoping), dispute show page.

`DisputeSecurityTest` (19 tests) — file validation (bad extension, bad MIME,
oversized), private storage (guest redirect, unrelated user 403,
cross-tournament 404, wrong-dispute 404), evidence immutability (participant
blocked, staff remove audited), mass-assignment injection ignored, client
winner rejected, role escalation (player blocked, admin promote/demote, admin
undemotable), state-transition bypass, cancelled-then-resolve rejected,
description/category required.

## 21. Exact Test Result

- **Full suite: 250 passed (831 assertions) — 0 failures, 0 skipped, 0 risky.**
- Phase 07: **50 passed (203 assertions)**.
- Phase 01–06 regression: **200 passed (628 assertions)** — unchanged.

## 22. Migration Result

`php artisan migrate:fresh --seed --force` — **OK**; 17 migrations applied
(16 existing + `2026_09_04_160000_add_dispute_moderation`), seed completes.

## 23. PHP Lint Result

`php -l` on **20 changed/new PHP files** — all "No syntax errors".

## 24. Route Count

**61 routes** (47 baseline + 14 new).

## 25. HTTP Smoke Result

Live `php artisan serve`: `GET /` → 200 · `GET /tournaments` → 200 ·
tournament show → 200 · leaderboard → 200 · `GET /moderation` as guest → 302
(login redirect). Authenticated flows (participant dispute page, staff queue,
evidence access, resolution) are covered authoritatively by the feature tests.

## 26. Regression Result

All Phase 01–06 tests (200 / 628) pass unchanged alongside the new suite — no
regressions. The legacy `matches.dispute` / `matches.resolve` quick actions
remain fully functional for Phase 05 compatibility.

## 27. Remaining Limitations

- Participant disputes are bound to the team **captain** account (the only
  user-linked participation in the data model; TeamMember rows are name/UID
  only, so a member without an account cannot be authenticated as a
  participant).
- The legacy organizer quick-dispute/resolve routes (Phase 05) do not create
  or close `Dispute` entities; the Phase 07 workflow is the canonical path.
- SQLite is single-writer; concurrency protection relies on transactions +
  application checks (duplicate open-dispute guard), which is correct for
  SQLite and portable to MySQL/PostgreSQL.
- Notification hooks are deliberately absent (Phase 11) — `DisputeService`
  events are structured so notifications can subscribe later.
- Moderator demotion resets the user to `player` (no prior-role memory).

---

## 28. Complete File Contents

### FILE: app/Models/Dispute.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A contest of a match result, opened by a participant or staff member and
 * processed through a controlled moderation state machine.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class Dispute extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Valid dispute state transitions. `resolved`, `rejected` and `cancelled`
     * are terminal.
     *
     * open         → under_review, resolved, rejected, cancelled
     * under_review → resolved, rejected, cancelled
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_UNDER_REVIEW, self::STATUS_RESOLVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_RESOLVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_RESOLVED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The categories a participant/staff member may pick when opening a
     * dispute. Used by the form and validated server-side.
     */
    public const CATEGORIES = [
        'wrong_winner',
        'wrong_score',
        'rule_violation',
        'technical_issue',
        'other',
    ];

    /**
     * Statuses that are still actionable (evidence may be added, staff may
     * act). Once resolved/rejected/cancelled a dispute is frozen.
     */
    public const ACTIONABLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
    ];

    protected $fillable = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolutionWinner()
    {
        return $this->belongsTo(Team::class, 'resolution_winner_team_id');
    }

    public function evidence()
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function events()
    {
        return $this->hasMany(ModerationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, self::ACTIONABLE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActionable();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Open',
        };
    }

    public function categoryLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->category));
    }

    /**
     * Status pill class name reusing the shared layout palette.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'confirmed',
            self::STATUS_REJECTED => 'finished',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_UNDER_REVIEW => 'live',
            default => 'pending',
        };
    }
}
```

### FILE: app/Models/DisputeEvidence.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable piece of evidence attached to a dispute.
 *
 * File evidence is stored on the private `local` disk (never publicly
 * served) and streamed only through an authorized controller. `text`
 * evidence has no file — the explanation lives in `description`.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class DisputeEvidence extends Model
{
    use HasFactory;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_TEXT = 'text';

    public const TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
        self::TYPE_TEXT,
    ];

    /**
     * File types that require an uploaded file (everything except text).
     */
    public const FILE_TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
    ];

    /**
     * Whitelisted extensions per evidence type. Used to generate the stored
     * filename and to re-verify uploads server-side.
     */
    public const ALLOWED_EXTENSIONS = [
        self::TYPE_IMAGE => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        self::TYPE_VIDEO => ['mp4', 'webm', 'mov'],
        self::TYPE_DOCUMENT => ['pdf'],
    ];

    /**
     * Whitelisted MIME types per evidence type (server-side re-check).
     */
    public const ALLOWED_MIME_TYPES = [
        self::TYPE_IMAGE => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        self::TYPE_VIDEO => ['video/mp4', 'video/webm', 'video/quicktime'],
        self::TYPE_DOCUMENT => ['application/pdf'],
    ];

    /**
     * Maximum evidence file size in kilobytes.
     */
    public const MAX_KB = 10240;

    protected $fillable = [];

    protected $casts = [
        'size' => 'integer',
    ];

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function typeLabel(): string
    {
        return ucfirst($this->type);
    }
}
```

### FILE: app/Models/ModerationEvent.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for dispute/moderation actions and result
 * corrections. Records are created through DisputeService only and are not
 * editable by normal users.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class ModerationEvent extends Model
{
    use HasFactory;

    public const EVENT_DISPUTE_OPENED = 'dispute.opened';
    public const EVENT_EVIDENCE_ADDED = 'dispute.evidence_added';
    public const EVENT_EVIDENCE_REMOVED = 'dispute.evidence_removed';
    public const EVENT_STATUS_CHANGED = 'dispute.status_changed';
    public const EVENT_ASSIGNED = 'dispute.assigned';
    public const EVENT_RESOLVED = 'dispute.resolved';
    public const EVENT_REJECTED = 'dispute.rejected';
    public const EVENT_CANCELLED = 'dispute.cancelled';
    public const EVENT_RESULT_CORRECTED = 'result.corrected';

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
```

### FILE: app/Services/DisputeService.php
```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\ModerationEvent;
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
    ) {
    }

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

        return DB::transaction(function () use ($match, $team, $opener, $category, $description) {
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
        $filename = (string) Str::uuid() . '.' . $extension;
        $directory = 'dispute_evidence/' . $dispute->id;

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
     * @param array<int, array{team_id:int, kills?:int|null, placement?:int|null}> $corrections
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
            throw new DomainException('Evidence files must be smaller than ' . DisputeEvidence::MAX_KB . ' KB.');
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
```

### FILE: app/Policies/DisputePolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;

class DisputePolicy
{
    /**
     * Staff for a dispute: platform staff (admin/moderator) or the owning
     * tournament organizer.
     */
    public function isStaff(User $user, Dispute $dispute): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $dispute->tournament->organizer_id === $user->id;
    }

    /**
     * A participant in the dispute: the opener or the captain of a
     * participating team.
     */
    public function isParticipant(User $user, Dispute $dispute): bool
    {
        if ($dispute->opened_by === $user->id) {
            return true;
        }

        return $dispute->match->participantTeamFor($user) !== null;
    }

    /**
     * Viewing a dispute: staff or an involved participant.
     */
    public function view(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute) || $this->isParticipant($user, $dispute);
    }

    /**
     * Adding evidence: anyone who can view the dispute (the service further
     * rejects submissions once the dispute is terminal).
     */
    public function addEvidence(User $user, Dispute $dispute): bool
    {
        return $this->view($user, $dispute);
    }

    /**
     * Viewing evidence: same gate as viewing the dispute, plus the evidence
     * must belong to it.
     */
    public function viewEvidence(User $user, Dispute $dispute, DisputeEvidence $evidence): bool
    {
        return $evidence->dispute_id === $dispute->id && $this->view($user, $dispute);
    }

    /**
     * All privileged moderation actions (review, assign, resolve, reject,
     * staff cancel, evidence removal) are staff-only.
     */
    public function manage(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute);
    }

    public function review(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function assign(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function resolve(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function reject(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function removeEvidence(User $user, Dispute $dispute, DisputeEvidence $evidence): bool
    {
        return $evidence->dispute_id === $dispute->id && $this->manage($user, $dispute);
    }

    /**
     * Cancelling: staff, or the opener of their own still-open dispute.
     */
    public function cancel(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute) || $dispute->opened_by === $user->id;
    }
}
```

### FILE: app/Http/Controllers/DisputeController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\DisputeService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $service,
    ) {
    }

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
            'category' => 'required|in:' . implode(',', Dispute::CATEGORIES),
            'description' => 'required|string|max:5000',
            'team_id' => 'nullable|integer|exists:teams,id',
            'evidence_type' => 'nullable|in:' . implode(',', DisputeEvidence::TYPES),
            'evidence_description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:' . DisputeEvidence::MAX_KB
                . '|mimetypes:' . $this->allowedMimeTypes(),
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
                    ->with('error', 'Dispute opened, but the evidence was not attached: ' . $e->getMessage());
            }
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
            'type' => 'required|in:' . implode(',', DisputeEvidence::TYPES),
            'description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:' . DisputeEvidence::MAX_KB
                . '|mimetypes:' . $this->allowedMimeTypes(),
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

        return back()->with('success', 'Dispute assigned to ' . $reviewer->name . '.');
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
            'corrections.*.placement' => 'nullable|integer|min:1|max:' . \App\Models\ScoringRule::MAX_PLACEMENT,
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
```

### FILE: app/Http/Controllers/ModerationController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Tournament;
use Illuminate\Http\Request;

/**
 * Moderation queue (Phase 07).
 *
 * Admins and moderators see the global queue; organizers see only their own
 * tournaments' disputes. Players can never access the queue.
 */
class ModerationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator() || $user->isOrganizer()),
            403,
            'Staff access only.'
        );

        $disputes = Dispute::query()
            ->with(['match.tournament', 'opener', 'assignee', 'team'])
            ->orderByDesc('created_at');

        // Organizers are scoped to their own tournaments.
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $disputes->whereHas('tournament', fn ($q) => $q->where('organizer_id', $user->id));
        }

        $status = $request->query('status');
        if ($status !== null && in_array($status, Dispute::STATUSES, true)) {
            $disputes->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $disputes->where('tournament_id', $tournamentId);
        }

        $disputes = $disputes->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name', 'organizer_id']);
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $tournaments = $tournaments->where('organizer_id', $user->id);
        }

        return view('moderation.index', compact('disputes', 'tournaments', 'status', 'tournamentId'));
    }
}
```

### FILE: database/migrations/2026_09_04_160000_add_dispute_moderation.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 07 — dispute + evidence + moderation + audit trail.
     *
     * disputes          : a participant/staff-opened contest of a match result,
     *                     with a controlled state machine.
     * dispute_evidence  : immutable evidence records attached to a dispute.
     *                     Files are stored on the private `local` disk and
     *                     served only through an authorized controller.
     * moderation_events : append-only audit trail for dispute/moderation
     *                     actions and result corrections.
     *
     * matches           : gains `completed_at` (dispute-window anchor).
     * tournaments       : gains `dispute_window_hours` (participant dispute
     *                     window; 0 disables participant disputes; staff
     *                     always bypass).
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->text('description');
            $table->string('status')->default('open'); // open|under_review|resolved|rejected|cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolution_winner_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tournament_id', 'disputes_tournament_index');
            $table->index('match_id', 'disputes_match_index');
            $table->index('status', 'disputes_status_index');
            $table->index('assigned_to', 'disputes_assigned_index');
        });

        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type'); // image|video|document|text
            $table->string('path')->nullable(); // private disk path (null for text)
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('dispute_id', 'dispute_evidence_dispute_index');
        });

        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->foreignId('dispute_id')->nullable()->constrained('disputes')->nullOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('dispute_id', 'moderation_events_dispute_index');
            $table->index('match_id', 'moderation_events_match_index');
            $table->index('event', 'moderation_events_event_index');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('scheduled_at');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedInteger('dispute_window_hours')->default(24)->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('dispute_window_hours');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });

        Schema::dropIfExists('moderation_events');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('disputes');
    }
};
```

### FILE: resources/views/disputes/create.blade.php
```blade
@extends('layouts.app')
@section('title', 'Open Dispute — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('matches.show', [$tournament, $match]) }}" class="muted" style="font-size:13px">← Back to match</a>
        <h1 style="margin-top:6px">🚩 Open a Dispute</h1>
        <p class="muted">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} —
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </div>

    @if($existing)
        <div class="flash error">
            This match already has an open dispute.
            <a href="{{ route('matches.disputes.show', [$tournament, $match, $existing]) }}" style="color:var(--red); text-decoration:underline">View it →</a>
        </div>
    @endif

    <div class="card" style="max-width: 720px">
        <form method="POST" action="{{ route('matches.disputes.store', [$tournament, $match]) }}" enctype="multipart/form-data">
            @csrf
            <label>Category</label>
            <select name="category" required>
                <option value="">Select a reason…</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected(old('category') === $category)>
                        {{ ucwords(str_replace('_', ' ', $category)) }}
                    </option>
                @endforeach
            </select>

            <label>Description</label>
            <textarea name="description" rows="5" maxlength="5000" placeholder="Explain what happened and why you believe the result is wrong." required>{{ old('description') }}</textarea>

            @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->id() === $tournament->organizer_id)
                <label>Disputed team (optional — staff only)</label>
                <select name="team_id">
                    <option value="">— none (staff dispute) —</option>
                    @if($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                    @if($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                </select>
            @elseif($userTeam)
                <p class="muted" style="margin-top:12px; font-size:13px">
                    Disputing on behalf of: <strong class="tag">{{ $userTeam->name }}</strong>
                </p>
                <input type="hidden" name="team_id" value="{{ $userTeam->id }}">
            @endif

            <hr style="border-color:var(--line); margin:18px 0">
            <h3>📎 Evidence (optional)</h3>

            <label>Evidence type</label>
            <select name="evidence_type">
                <option value="">— none —</option>
                <option value="image" @selected(old('evidence_type') === 'image')>Screenshot / image</option>
                <option value="video" @selected(old('evidence_type') === 'video')>Video clip</option>
                <option value="document" @selected(old('evidence_type') === 'document')>Document (PDF)</option>
                <option value="text" @selected(old('evidence_type') === 'text')>Text explanation</option>
            </select>

            <label>Evidence description</label>
            <input type="text" name="evidence_description" maxlength="2000" placeholder="What does this evidence show?" value="{{ old('evidence_description') }}">

            <label>File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
            <input type="file" name="evidence_file">

            <button class="btn btn-primary" style="margin-top:18px">Open Dispute</button>
        </form>
    </div>
@endsection
```

### FILE: resources/views/disputes/show.blade.php
```blade
@extends('layouts.app')
@section('title', 'Dispute — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('matches.show', [$tournament, $match]) }}" class="muted" style="font-size:13px">← Back to match</a>
        <h1 style="margin-top:6px">
            🚩 Dispute #{{ $dispute->id }}
            <span class="pill {{ $dispute->statusPill() }}">{{ strtoupper($dispute->status) }}</span>
        </h1>
        <p class="muted">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} ·
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>Details</h3>
            <table>
                <tr><th>Category</th><td>{{ $dispute->categoryLabel() }}</td></tr>
                <tr><th>Opened by</th><td>{{ $dispute->opener?->name ?? 'System' }}</td></tr>
                <tr><th>Team</th><td>{{ $dispute->team?->name ?? '—' }}</td></tr>
                <tr><th>Opened</th><td>{{ $dispute->created_at->format('d M Y, h:i A') }}</td></tr>
                @if($dispute->assignee)
                    <tr><th>Reviewer</th><td>{{ $dispute->assignee->name }}</td></tr>
                @endif
                @if($dispute->resolved_at)
                    <tr><th>Resolved</th><td>{{ $dispute->resolved_at->format('d M Y, h:i A') }} by {{ $dispute->resolver?->name ?? '—' }}</td></tr>
                @endif
                @if($dispute->resolutionWinner)
                    <tr><th>Confirmed winner</th><td><strong class="tag">{{ $dispute->resolutionWinner->name }}</strong></td></tr>
                @endif
            </table>
            <h3 style="margin-top:16px">Description</h3>
            <p style="white-space:pre-wrap">{{ $dispute->description }}</p>
            @if($dispute->resolution)
                <h3 style="margin-top:16px">Resolution</h3>
                <p style="white-space:pre-wrap; color:var(--green)">{{ $dispute->resolution }}</p>
            @endif
        </div>

        <div class="card">
            <h3>📎 Evidence ({{ $dispute->evidence->count() }})</h3>
            @if($dispute->evidence->isEmpty())
                <p class="muted">No evidence yet.</p>
            @else
                <table>
                    <tr><th>Type</th><th>By</th><th>Description</th><th></th></tr>
                    @foreach($dispute->evidence as $evidence)
                        <tr>
                            <td><span class="pill pending">{{ $evidence->typeLabel() }}</span></td>
                            <td>{{ $evidence->submitter?->name ?? 'System' }}</td>
                            <td class="muted" style="font-size:13px">{{ $evidence->description }}</td>
                            <td>
                                @if($evidence->path)
                                    <a href="{{ route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]) }}" class="btn btn-sm" target="_blank">View</a>
                                @else
                                    <span class="muted">text</span>
                                @endif
                                @if($isStaff)
                                    <form method="POST" action="{{ route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]) }}" style="display:inline">
                                        @csrf
                                        <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)"
                                            onclick="return confirm('Remove this evidence permanently? This is audited.')">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if($dispute->isActionable() && (auth()->check()))
                <hr style="border-color:var(--line); margin:16px 0">
                <h3>Submit Evidence</h3>
                <form method="POST" action="{{ route('matches.disputes.evidence.store', [$tournament, $match, $dispute]) }}" enctype="multipart/form-data">
                    @csrf
                    <label>Type</label>
                    <select name="type" required>
                        <option value="image">Screenshot / image</option>
                        <option value="video">Video clip</option>
                        <option value="document">Document (PDF)</option>
                        <option value="text">Text explanation</option>
                    </select>
                    <label>Description</label>
                    <input type="text" name="description" maxlength="2000" placeholder="What does this evidence show?">
                    <label>File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
                    <input type="file" name="evidence_file">
                    <button class="btn btn-primary btn-sm" style="margin-top:12px">Add Evidence</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card">
        <h3>🕓 Timeline</h3>
        @if($dispute->events->isEmpty())
            <p class="muted">No events recorded.</p>
        @else
            <table>
                <tr><th>When</th><th>Actor</th><th>Event</th><th>Details</th></tr>
                @foreach($dispute->events as $event)
                    <tr>
                        <td class="muted" style="font-size:12px">{{ $event->created_at->format('d M, h:i A') }}</td>
                        <td>{{ $event->actor?->name ?? 'System' }}</td>
                        <td><span class="tag">{{ $event->event }}</span></td>
                        <td class="muted" style="font-size:12px">{{ json_encode($event->metadata) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @auth
        @if($dispute->isActionable())
            <div class="card">
                @if($isStaff)
                    <h3>🛡 Moderation</h3>
                    <div class="grid cols-2">
                        @if($dispute->status === \App\Models\Dispute::STATUS_OPEN)
                            <form method="POST" action="{{ route('matches.disputes.review', [$tournament, $match, $dispute]) }}">
                                @csrf
                                <button class="btn btn-cyan btn-sm">Mark Under Review</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('matches.disputes.assign', [$tournament, $match, $dispute]) }}">
                            @csrf
                            <div style="display:flex; gap:8px; align-items:end">
                                <div style="flex:1">
                                    <label>Assign reviewer</label>
                                    <select name="reviewer_id" required>
                                        <option value="">— select —</option>
                                        @foreach($reviewers as $reviewer)
                                            <option value="{{ $reviewer->id }}" @selected($dispute->assigned_to === $reviewer->id)>{{ $reviewer->name }} ({{ $reviewer->role }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="btn btn-sm">Assign</button>
                            </div>
                        </form>
                    </div>

                    <hr style="border-color:var(--line); margin:16px 0">
                    <h3>⚖ Resolve</h3>
                    <form method="POST" action="{{ route('matches.disputes.resolve', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <label>Confirmed winner</label>
                        <select name="winner_team_id" required>
                            @if($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                            @if($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                        </select>
                        <label>Resolution reason (required, audited)</label>
                        <textarea name="resolution" rows="3" maxlength="5000" placeholder="Explain the decision." required></textarea>

                        @if($match->scores->isNotEmpty())
                            <label>Score corrections (optional — recalculated by the scoring engine)</label>
                            @foreach($match->scores as $score)
                                <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px">
                                    <span class="muted" style="min-width:140px; font-size:13px">{{ $score->team->name }}</span>
                                    <input type="hidden" name="corrections[{{ $loop->index }}][team_id]" value="{{ $score->team_id }}">
                                    <input type="number" name="corrections[{{ $loop->index }}][kills]" value="{{ $score->kills }}" min="0" placeholder="Kills" style="max-width:90px">
                                    <input type="number" name="corrections[{{ $loop->index }}][placement]" value="{{ $score->placement }}" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" placeholder="Place" style="max-width:90px">
                                </div>
                            @endforeach
                            <p class="muted" style="font-size:12px">Changes are recalculated with the score's original rule version; totals are never trusted from the client.</p>
                        @endif

                        <div style="display:flex; gap:10px; margin-top:14px">
                            <button class="btn btn-green btn-sm">Resolve Dispute</button>
                        </div>
                    </form>

                    <hr style="border-color:var(--line); margin:16px 0">
                    <div style="display:flex; gap:10px">
                        <form method="POST" action="{{ route('matches.disputes.reject', [$tournament, $match, $dispute]) }}" style="flex:1">
                            @csrf
                            <label>Rejection reason (required)</label>
                            <div style="display:flex; gap:8px">
                                <input type="text" name="resolution" maxlength="5000" placeholder="Why the result stands" required>
                                <button class="btn btn-sm" style="border-color:var(--amber); color:var(--amber)">Reject</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}" style="align-self:end">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--muted); color:var(--muted)">Cancel</button>
                        </form>
                    </div>
                @elseif($isOpener && $dispute->status === \App\Models\Dispute::STATUS_OPEN)
                    <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <button class="btn btn-sm" style="border-color:var(--muted); color:var(--muted)">Cancel my dispute</button>
                    </form>
                @endif
            </div>
        @endif
    @endauth
@endsection
```

### FILE: resources/views/moderation/index.blade.php
```blade
@extends('layouts.app')
@section('title', 'Moderation Queue — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Dispute Moderation Queue</h1>

    <div class="card">
        <form method="GET" action="{{ route('moderation.index') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
            <div style="min-width:180px">
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach(\App\Models\Dispute::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:220px">
                <label>Tournament</label>
                <select name="tournament_id">
                    <option value="">All tournaments</option>
                    @foreach($tournaments as $t)
                        <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-cyan">Filter</button>
        </form>
    </div>

    <div class="card">
        @if($disputes->isEmpty())
            <p class="muted">No disputes match your filters.</p>
        @else
            <table>
                <tr>
                    <th>#</th><th>Tournament</th><th>Match</th><th>Team</th><th>Category</th>
                    <th>Status</th><th>Reviewer</th><th>Opened</th><th></th>
                </tr>
                @foreach($disputes as $dispute)
                    <tr>
                        <td><strong>#{{ $dispute->id }}</strong></td>
                        <td>{{ $dispute->match?->tournament?->name ?? '—' }}</td>
                        <td>M#{{ $dispute->match_id }}</td>
                        <td>{{ $dispute->team?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $dispute->categoryLabel() }}</td>
                        <td><span class="pill {{ $dispute->statusPill() }}">{{ $dispute->statusLabel() }}</span></td>
                        <td>{{ $dispute->assignee?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:12px">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                        <td>
                            @if($dispute->match && $dispute->match->tournament)
                                <a href="{{ route('matches.disputes.show', [$dispute->match->tournament, $dispute->match, $dispute]) }}" class="btn btn-sm">Review</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $disputes->links() }}</div>
        @endif
    </div>
@endsection
```

### FILE: tests/Feature/DisputeSystemTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\ModerationEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 07 — dispute creation, evidence, state machine, moderation queue,
 * resolution and auditable result correction (happy paths + lifecycle).
 */
class DisputeSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Dispute Tournament';
        $t->slug = $o['slug'] ?? ('dispute-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->dispute_window_hours = $o['dispute_window_hours'] ?? 24;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'ready'): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    protected function completeMatch(GameMatch $match, Team $winner, ?string $completedAt = null): GameMatch
    {
        $match->winner_team_id = $winner->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = $completedAt ?? now();
        $match->save();

        return $match->fresh();
    }

    // ------------------------------------------------------------------
    // Dispute creation
    // ------------------------------------------------------------------

    public function test_participant_captain_can_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'We actually won that game.',
            'team_id' => $teamA->id,
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertNotNull($dispute);
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->status);
        $this->assertSame($captainA->id, $dispute->opened_by);
        $this->assertSame($teamA->id, $dispute->team_id);
        $this->assertSame('disputed', $match->fresh()->status);
    }

    public function test_captain_team_is_inferred_when_team_id_omitted(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'Please review.',
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertSame($teamA->id, $dispute->team_id);
    }

    public function test_organizer_can_open_dispute_without_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'technical_issue',
            'description' => 'Room crashed mid game.',
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertNotNull($dispute);
        $this->assertNull($dispute->team_id);
        $this->assertSame($org->id, $dispute->opened_by);
    }

    public function test_admin_can_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($admin)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'rule_violation',
            'description' => 'Admin review required.',
        ])->assertRedirect();

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    public function test_unrelated_player_cannot_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $random = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($random)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'not my match',
        ])->assertStatus(403);

        $this->assertSame(0, Dispute::count());
    }

    public function test_other_team_captain_cannot_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainC = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $teamC = $this->makeTeam($tournament, $captainC);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainC)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'I am not in this match.',
        ])->assertStatus(403);

        $this->assertSame(0, Dispute::count());
    }

    public function test_captain_cannot_dispute_for_another_team(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'trying to dispute as the other team',
            'team_id' => $teamB->id,
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());
    }

    public function test_cross_tournament_dispute_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, $captainA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournamentB, $matchA]), [
            'category' => 'other',
            'description' => 'cross tournament',
        ])->assertStatus(404);

        $this->assertSame(0, Dispute::count());
    }

    public function test_duplicate_open_dispute_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'first',
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'second',
        ])->assertSessionHas('error');

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    // ------------------------------------------------------------------
    // Dispute window
    // ------------------------------------------------------------------

    public function test_expired_window_blocks_participant_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB, now()->subHours(25));

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'too late',
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());
    }

    public function test_staff_bypasses_expired_dispute_window(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB, now()->subHours(25));

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'staff override',
        ])->assertRedirect();

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    public function test_zero_window_disables_participant_disputes(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live', ['dispute_window_hours' => 0]);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'window disabled',
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());

        // Staff can still open.
        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'staff',
        ])->assertRedirect();
        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    // ------------------------------------------------------------------
    // Evidence
    // ------------------------------------------------------------------

    public function test_participant_can_add_text_evidence(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text',
            'description' => 'The room ID was wrong.',
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertNotNull($evidence);
        $this->assertNull($evidence->path);
        $this->assertSame('text', $evidence->type);
    }

    public function test_staff_can_add_image_evidence_to_private_disk(): void
    {
        Storage::fake('local');

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'Screenshot of the result',
            'evidence_file' => UploadedFile::fake()->create('result.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertNotNull($evidence);
        $this->assertNotNull($evidence->path);
        $this->assertSame('image', $evidence->type);
        Storage::disk('local')->assertExists($evidence->path);
    }

    public function test_staff_can_add_document_evidence(): void
    {
        Storage::fake('local');

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'document',
            'description' => 'Rules PDF',
            'evidence_file' => UploadedFile::fake()->create('rules.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertSame('document', $evidence->type);
        Storage::disk('local')->assertExists($evidence->path);
    }

    public function test_evidence_blocked_after_resolution(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Result stands.',
        ])->assertRedirect();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text',
            'description' => 'late evidence',
        ])->assertSessionHas('error');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    // ------------------------------------------------------------------
    // State machine
    // ------------------------------------------------------------------

    public function test_valid_transition_open_to_review_to_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertRedirect();
        $this->assertSame(Dispute::STATUS_UNDER_REVIEW, $dispute->fresh()->status);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Upheld.',
        ])->assertRedirect();

        $this->assertSame(Dispute::STATUS_RESOLVED, $dispute->fresh()->status);
    }

    public function test_participant_cannot_resolve_reject_review_or_assign(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.reject', [$tournament, $match, $dispute]), [
            'resolution' => 'nope',
        ])->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id, 'resolution' => 'nope',
        ])->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $moderator->id,
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->assigned_to);
    }

    public function test_participant_cannot_self_assign_as_reviewer(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $captainA->id,
        ])->assertStatus(403);

        $this->assertNull($dispute->fresh()->assigned_to);
    }

    public function test_assignment_requires_moderator_or_admin(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        // Assign to a normal player → rejected by the service.
        $this->actingAs($org)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $player->id,
        ])->assertSessionHas('error');
        $this->assertNull($dispute->fresh()->assigned_to);

        // Assign to a moderator → accepted.
        $this->actingAs($org)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $moderator->id,
        ])->assertRedirect();
        $this->assertSame($moderator->id, $dispute->fresh()->assigned_to);
    }

    // ------------------------------------------------------------------
    // Resolution / rejection / cancellation
    // ------------------------------------------------------------------

    public function test_resolution_requires_a_reason(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => '',
        ])->assertSessionHasErrors('resolution');
    }

    public function test_resolve_corrects_winner_and_reopens_bracket_slot(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'winner wrong',
        ])->assertRedirect();
        $dispute = Dispute::first();
        $this->assertSame('disputed', $match->fresh()->status);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Team B actually won.',
        ])->assertRedirect();

        $dispute = $dispute->fresh();
        $this->assertSame(Dispute::STATUS_RESOLVED, $dispute->status);
        $this->assertSame($teamB->id, $dispute->resolution_winner_team_id);

        $match = $match->fresh();
        $this->assertSame('completed', $match->status);
        $this->assertSame($teamB->id, $match->winner_team_id);
        $this->assertNotNull($match->completed_at);
    }

    public function test_reject_restores_match_with_original_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'contest',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.reject', [$tournament, $match, $dispute]), [
            'resolution' => 'Evidence insufficient — result stands.',
        ])->assertRedirect();

        $this->assertSame(Dispute::STATUS_REJECTED, $dispute->fresh()->status);
        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    public function test_opener_can_cancel_own_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.cancel', [$tournament, $match, $dispute]))->assertRedirect();

        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);
        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Result correction (ScoringService integration)
    // ------------------------------------------------------------------

    public function test_result_correction_recalculates_and_preserves_rule_version(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1); // 12 + 6 = 18
        $ruleId = $score->scoring_rules_id;

        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'kills wrong', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'Corrected kills and placement.',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 3, 'placement' => 2],
            ],
        ])->assertRedirect();

        $score = $score->fresh();
        $this->assertSame(3, (int) $score->kills);
        $this->assertSame(2, (int) $score->placement);
        $this->assertSame(9, (int) $score->placement_points);
        $this->assertSame(3, (int) $score->kill_points);
        $this->assertSame(12, (int) $score->points);
        $this->assertSame($ruleId, $score->scoring_rules_id);
    }

    public function test_correction_ignores_client_supplied_points(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'recalc',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 1, 'placement' => 1, 'points' => 9999],
            ],
        ])->assertRedirect();

        $score = $score->fresh();
        $this->assertSame(13, (int) $score->points); // 12 + 1 — 9999 ignored
    }

    public function test_correction_rejects_non_participant_team(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $outsider = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'attempted outsider correction',
            'corrections' => [
                ['team_id' => $outsider->id, 'kills' => 99, 'placement' => 1],
            ],
        ])->assertSessionHas('error');

        $score = $score->fresh();
        $this->assertSame(6, (int) $score->kills);
        $this->assertSame(1, (int) $score->placement);
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    public function test_cross_tournament_resolution_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournamentA, $matchA]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournamentB, $matchA, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'cross tournament',
        ])->assertStatus(404);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_moderation_events_are_recorded_with_correct_actor(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text', 'description' => 'explanation',
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertRedirect();
        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id, 'resolution' => 'done',
        ])->assertRedirect();

        $events = ModerationEvent::where('dispute_id', $dispute->id)->get();

        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_DISPUTE_OPENED && $e->actor_id === $captainA->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_EVIDENCE_ADDED && $e->actor_id === $captainA->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_STATUS_CHANGED && $e->actor_id === $org->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_RESOLVED && $e->actor_id === $org->id));
    }

    public function test_correction_creates_result_corrected_audit_event(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(ScoringService::class)->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'corrected',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 2, 'placement' => 3],
            ],
        ])->assertRedirect();

        $this->assertTrue(
            ModerationEvent::where('dispute_id', $dispute->id)
                ->where('event', ModerationEvent::EVENT_RESULT_CORRECTED)
                ->exists()
        );
    }

    // ------------------------------------------------------------------
    // Moderation queue
    // ------------------------------------------------------------------

    public function test_moderation_queue_is_staff_only(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();

        $this->actingAs($player)->get(route('moderation.index'))->assertStatus(403);
        $this->actingAs($org)->get(route('moderation.index'))->assertOk();
        $this->actingAs($moderator)->get(route('moderation.index'))->assertOk();
    }

    public function test_organizer_queue_is_scoped_to_own_tournaments(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($orgA);
        $tournamentB = $this->makeTournament($orgB, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($orgA)->post(route('matches.disputes.store', [$tournamentA, $matchA]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();

        // orgB's queue must not contain orgA's dispute.
        $response = $this->actingAs($orgB)->get(route('moderation.index'));
        $response->assertOk();
        $response->assertDontSee('wrong_winner');
        $this->assertSame(0, $response->viewData('disputes')->total());
    }

    public function test_dispute_show_page_is_accessible_to_participant_and_staff(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->get(route('matches.disputes.show', [$tournament, $match, $dispute]))
            ->assertOk()->assertSee('Wrong Winner');
        $this->actingAs($org)->get(route('matches.disputes.show', [$tournament, $match, $dispute]))
            ->assertOk()->assertSee('Moderation');
    }
}
```

### FILE: tests/Feature/DisputeSecurityTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 07 — dispute/evidence security: file validation, private storage,
 * IDOR/cross-tournament access, mass-assignment, role escalation, evidence
 * immutability and state-transition bypass.
 */
class DisputeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Security Tournament';
        $t->slug = $o['slug'] ?? ('security-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = GameMatch::STATUS_COMPLETED;
        $m->winner_team_id = $t1->id;
        $m->completed_at = now();
        $m->save();

        return $m;
    }

    protected function openDispute(User $opener, Tournament $tournament, GameMatch $match): Dispute
    {
        $this->actingAs($opener)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'base dispute',
        ])->assertRedirect();

        return Dispute::where('match_id', $match->id)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // File validation
    // ------------------------------------------------------------------

    public function test_evidence_invalid_extension_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'evil',
            'evidence_file' => UploadedFile::fake()->create('evil.exe', 100),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    public function test_evidence_invalid_mime_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        // A file claiming a PNG extension but with an executable MIME.
        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'fake',
            'evidence_file' => UploadedFile::fake()->create('fake.png', 100, 'application/x-msdownload'),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    public function test_evidence_oversized_file_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'huge',
            'evidence_file' => UploadedFile::fake()->create('huge.png', DisputeEvidence::MAX_KB + 1000, 'image/png'),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    // ------------------------------------------------------------------
    // Private storage + access control
    // ------------------------------------------------------------------

    public function test_private_evidence_is_not_publicly_accessible(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        // Log out the organizer so the request below is truly a guest.
        auth()->logout();

        // Guests are redirected to login (route is behind `auth`).
        $this->get(route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]))
            ->assertRedirect(route('login'));
    }

    public function test_unrelated_user_cannot_view_evidence(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        $this->actingAs($stranger)
            ->get(route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]))
            ->assertStatus(403);
    }

    public function test_cross_tournament_evidence_access_blocked(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $match = $this->makeMatch($tournamentA, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournamentA, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournamentA, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        // Same evidence id, but addressed through a different tournament's
        // route — must 404, never stream.
        $this->actingAs($org)
            ->get(route('matches.disputes.evidence.show', [$tournamentB, $match, $dispute, $evidence]))
            ->assertStatus(404);
    }

    public function test_evidence_must_belong_to_dispute(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $teamC = $this->makeTeam($tournament);
        $matchA = $this->makeMatch($tournament, $teamA, $teamB);
        $matchB = $this->makeMatch($tournament, $teamB, $teamC);
        $disputeA = $this->openDispute($org, $tournament, $matchA);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $matchA, $disputeA]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();

        $disputeB = $this->openDispute($org, $tournament, $matchB);

        $this->actingAs($org)
            ->get(route('matches.disputes.evidence.show', [$tournament, $matchB, $disputeB, $evidence]))
            ->assertStatus(404);
    }

    public function test_evidence_is_immutable_for_participants(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text', 'description' => 'explanation',
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();

        // Participants cannot remove evidence (staff only).
        $this->actingAs($captainA)
            ->post(route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]))
            ->assertStatus(403);

        $this->assertDatabaseHas('dispute_evidence', ['id' => $evidence->id]);
    }

    public function test_staff_can_remove_evidence_and_it_is_audited(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();
        $path = $evidence->path;

        $this->actingAs($org)
            ->post(route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]))
            ->assertRedirect();

        $this->assertDatabaseMissing('dispute_evidence', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('moderation_events', [
            'dispute_id' => $dispute->id,
            'event' => 'dispute.evidence_removed',
        ]);
    }

    // ------------------------------------------------------------------
    // Mass assignment / injection
    // ------------------------------------------------------------------

    public function test_client_supplied_status_and_assignment_are_ignored_on_open(): void
    {
        $org = $this->makeUser('organizer');
        $attacker = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'base',
            'status' => 'resolved',
            'assigned_to' => $attacker->id,
            'opened_by' => $attacker->id,
            'resolved_by' => $attacker->id,
        ])->assertRedirect();

        $dispute = Dispute::firstOrFail();
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->status);
        $this->assertSame($org->id, $dispute->opened_by);
        $this->assertNull($dispute->assigned_to);
        $this->assertNull($dispute->resolved_by);
    }

    public function test_resolve_rejects_non_participant_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $outsider = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $outsider->id,
            'resolution' => 'hijack',
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Role escalation
    // ------------------------------------------------------------------

    public function test_player_cannot_promote_moderator(): void
    {
        $player = $this->makeUser('player');
        $target = $this->makeUser('player');

        $this->actingAs($player)->post(route('admin.users.moderate'), [
            'email' => $target->email,
        ])->assertStatus(403);

        $this->assertSame('player', $target->fresh()->role);
    }

    public function test_admin_promotes_and_demotes_moderator(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.users.moderate'), [
            'email' => $target->email,
        ])->assertRedirect();
        $this->assertSame('moderator', $target->fresh()->role);

        // Moderators can now access the queue.
        $this->actingAs($target->fresh())->get(route('moderation.index'))->assertOk();

        $this->actingAs($admin)->post(route('admin.users.unmoderate', $target))->assertRedirect();
        $this->assertSame('player', $target->fresh()->role);
    }

    public function test_admin_cannot_be_demoted(): void
    {
        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');

        $this->actingAs($admin)->post(route('admin.users.unmoderate', $otherAdmin))->assertSessionHas('error');
        $this->assertSame('admin', $otherAdmin->fresh()->role);
    }

    // ------------------------------------------------------------------
    // State-transition bypass
    // ------------------------------------------------------------------

    public function test_participant_cannot_bypass_state_machine(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        // Direct resolve attempt by a participant.
        $this->actingAs($captainA)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'self resolve',
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    public function test_cancelled_dispute_cannot_be_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.cancel', [$tournament, $match, $dispute]))->assertRedirect();
        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);

        // Resolving a cancelled dispute must fail (invalid transition).
        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'too late',
        ])->assertSessionHas('error');

        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);
    }

    public function test_dispute_requires_description_and_category(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => '',
        ])->assertSessionHasErrors('description');

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'bogus_category',
            'description' => 'desc',
        ])->assertSessionHasErrors('category');

        $this->assertSame(0, Dispute::count());
    }
}
```

### FILE: app/Models/User.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Sensitive fields (role, wallet_balance) are intentionally excluded from
     * mass assignment. `role` must be set explicitly (see AuthController) and
     * can only ever be 'player' or 'organizer' at registration time.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Moderators are platform staff who can work the dispute/moderation
     * queue and review/resolve disputes. The role is granted only by admins
     * (never self-assigned and never mass-assignable).
     */
    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    /**
     * Platform staff (admins + moderators) — distinct from tournament
     * organizers, who are staff only within their own tournaments.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }
}
```

### FILE: app/Models/GameMatch.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    /**
     * The database table is named "matches" (game_matches would collide with
     * common Eloquent naming, and the migration creates the `matches` table).
     */
    protected $table = 'matches';

    /**
     * Match lifecycle statuses.
     *
     * pending   → waiting for participants (placeholder)
     * ready     → both participants assigned, waiting to start
     * live      → in progress (room published)
     * completed → finished with a winner
     * disputed  → completed result is contested
     * bye       → auto-advanced (single participant, no play)
     * cancelled → abandoned
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_LIVE = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_BYE = 'bye';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid match state transitions. `bye`, `cancelled` and `completed` are
     * terminal except for the explicit dispute flow (completed → disputed).
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_READY, self::STATUS_LIVE, self::STATUS_BYE, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_LIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_COMPLETED, self::STATUS_DISPUTED],
        self::STATUS_COMPLETED => [self::STATUS_DISPUTED],
        self::STATUS_DISPUTED => [self::STATUS_COMPLETED, self::STATUS_LIVE],
        self::STATUS_BYE => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket sides.
     */
    public const BRACKET_WINNERS = 'winners';
    public const BRACKET_LOSERS = 'losers';
    public const BRACKET_GRAND_FINAL = 'grand_final';

    /**
     * All bracket-structure and lifecycle fields are server-controlled.
     * Excluded from mass assignment so a client can never forge a winner,
     * participants, or bracket links.
     */
    protected $fillable = [
        'room_id',
        'room_pass',
        'scheduled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'round' => 'integer',
        'match_no' => 'integer',
        'next_slot' => 'integer',
        'loser_slot' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team1()
    {
        return $this->belongsTo(Team::class, 'team1_id');
    }

    public function team2()
    {
        return $this->belongsTo(Team::class, 'team2_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function nextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'next_match_id');
    }

    public function loserNextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'loser_next_match_id');
    }

    public function scores()
    {
        return $this->hasMany(Score::class, 'match_id');
    }

    public function disputes()
    {
        return $this->hasMany(Dispute::class, 'match_id');
    }

    /**
     * The participating teams (1 or 2), loaded from the DB.
     *
     * @return \Illuminate\Support\Collection<int, Team>
     */
    public function participantTeams()
    {
        return Team::whereIn('id', array_filter([$this->team1_id, $this->team2_id]))->get();
    }

    /**
     * The participating team captained by the given user, or null when the
     * user is not the captain of either participant.
     */
    public function participantTeamFor(User $user): ?Team
    {
        return $this->participantTeams()->firstWhere('captain_id', $user->id);
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function hasParticipant(Team $team): bool
    {
        return $this->team1_id === $team->id || $this->team2_id === $team->id;
    }

    /**
     * Whether both participant slots are filled.
     */
    public function hasBothTeams(): bool
    {
        return $this->team1_id !== null && $this->team2_id !== null;
    }

    /**
     * The team id currently occupying the given slot (1 = team1, 2 = team2),
     * or null when the slot is empty.
     */
    public function teamIdInSlot(?int $slot): ?int
    {
        return match ($slot) {
            1 => $this->team1_id,
            2 => $this->team2_id,
            default => null,
        };
    }

    /**
     * Whether the given slot is already occupied.
     */
    public function hasTeamInSlot(?int $slot): bool
    {
        return $this->teamIdInSlot($slot) !== null;
    }

    /**
     * Assign a team to a slot (1 = team1, 2 = team2).
     */
    public function setTeamSlot(int $slot, int $teamId): void
    {
        if ($slot === 1) {
            $this->team1_id = $teamId;
        } elseif ($slot === 2) {
            $this->team2_id = $teamId;
        }
    }

    /**
     * The losing participant, or null for a bye/undecided match.
     */
    public function loserTeamId(): ?int
    {
        if ($this->winner_team_id === null || ! $this->hasBothTeams()) {
            return null;
        }

        return $this->winner_team_id === $this->team1_id ? $this->team2_id : $this->team1_id;
    }

    public function isBye(): bool
    {
        return $this->status === self::STATUS_BYE;
    }

    /**
     * Whether score submission/adjustment is open for this match (ready or
     * live only). Completed, disputed, bye, cancelled and pending matches
     * are closed.
     */
    public function acceptsScoreSubmission(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_LIVE], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Human label for the bracket side (used in views).
     */
    public function bracketLabel(): string
    {
        return match ($this->bracket) {
            self::BRACKET_LOSERS => 'Losers Bracket',
            self::BRACKET_GRAND_FINAL => 'Grand Final',
            default => 'Winners Bracket',
        };
    }

    /**
     * Human label for this match's round within its bracket side.
     */
    public function roundLabel(): string
    {
        if ($this->bracket === self::BRACKET_GRAND_FINAL) {
            return '🏁 Grand Final';
        }

        if ($this->bracket === self::BRACKET_LOSERS) {
            return 'Losers Round ' . $this->round;
        }

        return 'Round ' . $this->round;
    }

    /**
     * Status pill class name for the Blade views.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'finished',
            self::STATUS_LIVE => 'live',
            self::STATUS_READY => 'ready',
            self::STATUS_BYE => 'bye',
            self::STATUS_DISPUTED => 'disputed',
            default => 'draft',
        };
    }
}
```

### FILE: app/Models/Tournament.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    /**
     * Lifecycle states. These are the single source of truth for the values
     * stored in the `status` column.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that are visible on public listings. DRAFT (not yet published)
     * and CANCELLED tournaments are hidden.
     */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_LIVE,
        self::STATUS_FINISHED,
    ];

    /**
     * Valid state transitions. A tournament may only move along these edges;
     * it can never jump arbitrarily between states. FINISHED and CANCELLED
     * are terminal.
     *
     * draft    → open, cancelled
     * open     → closed, live, cancelled   (open == published + accepting)
     * closed   → live, cancelled
     * live     → finished
     * finished → (terminal)
     * cancelled→ (terminal)
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_CLOSED, self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_CLOSED => [self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_FINISHED],
        self::STATUS_FINISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket formats. Only formats that are actually implemented may be
     * selected; the others are intentionally absent so they never appear
     * as a selectable option.
     */
    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';

    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM,
        self::FORMAT_DOUBLE_ELIM,
    ];

    /**
     * organizer_id, slug and status are set server-side only. They are
     * excluded from mass assignment so a client can never hijack ownership
     * or lifecycle state.
     */
    protected $fillable = [
        'name',
        'game_mode',
        'map',
        'entry_fee',
        'prize_pool',
        'team_slots',
        'team_size',
        'rules',
        'starts_at',
        'check_in_starts_at',
        'check_in_ends_at',
        'format',
        'dispute_window_hours',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
        'bracket_size' => 'integer',
        'dispute_window_hours' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function confirmedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Teams that currently occupy a slot: pending (awaiting payment
     * verification) or confirmed. Withdrawn, rejected, no-show and
     * waitlisted teams do not occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
    }

    /**
     * Teams on the waitlist, in deterministic FIFO order.
     */
    public function waitlistedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_WAITLISTED);
    }

    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scoring rule-set versions for this tournament (Phase 06).
     */
    public function scoringRules()
    {
        return $this->hasMany(ScoringRule::class);
    }

    /**
     * Disputes raised against matches in this tournament (Phase 07).
     */
    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * The participant dispute window in hours (default 24). A value of 0
     * disables participant disputes entirely; staff always bypass.
     */
    public function disputeWindowHours(): int
    {
        return (int) ($this->dispute_window_hours ?? 24);
    }

    /**
     * Number of registration slots still available. Counts both pending and
     * confirmed teams so that a team awaiting payment still reserves its slot.
     */
    public function slotsLeft(): int
    {
        return max(0, (int) $this->team_slots - $this->registeredTeams()->count());
    }

    public function isFull(): bool
    {
        return $this->slotsLeft() <= 0;
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the configured start time has already passed.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    /**
     * Whether the tournament is currently accepting new registrations.
     * Status is the primary authority; the start time acts as a hard deadline.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->hasStarted();
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    // ------------------------------------------------------------------
    // Phase 04 — check-in window + bracket eligibility
    // ------------------------------------------------------------------

    /**
     * Check-in is only active when BOTH window timestamps are configured.
     * Tournaments created before this feature (or without a window) simply
     * do not require check-in, preserving the Phase 02 behaviour.
     */
    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null && $this->check_in_ends_at !== null;
    }

    /**
     * Whether the check-in window is currently open.
     */
    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        return now()->between($this->check_in_starts_at, $this->check_in_ends_at);
    }

    /**
     * Whether the check-in window has already closed.
     */
    public function checkInHasClosed(): bool
    {
        return $this->hasCheckIn() && $this->check_in_ends_at->isPast();
    }

    /**
     * The set of teams eligible for competitive bracket generation:
     * confirmed teams that have checked in (when check-in is configured).
     * This is the single source of truth used by BracketService.
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()
            ->where('status', Team::STATUS_CONFIRMED)
            ->when($this->hasCheckIn(), fn ($q) => $q->whereNotNull('checked_in_at'));
    }
}
```

### FILE: app/Services/MatchProgressionService.php
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Team;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Match progression (Phase 05).
 *
 * Controls the match state machine and winner advancement. Authorization is
 * handled by policies in the controllers; this service only answers "is this
 * progression legal right now?" and performs it atomically.
 */
class MatchProgressionService
{
    public function __construct(
        protected BracketService $bracket,
    ) {
    }

    /**
     * Record a winner and complete a match, then advance the bracket.
     *
     * @return string 'completed' | 'already'
     */
    public function complete(GameMatch $match, Team $winner): string
    {
        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        if ($match->status === GameMatch::STATUS_COMPLETED) {
            if ($match->winner_team_id === $winner->id) {
                return 'already';
            }

            throw new DomainException('This match is already completed — dispute it before changing the result.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_READY, GameMatch::STATUS_LIVE], true)) {
            throw new DomainException('This match cannot be completed from its current state.');
        }

        DB::transaction(function () use ($match, $winner) {
            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match);
        });

        return 'completed';
    }

    /**
     * Move a completed match into the disputed state. Advancement is halted
     * while disputed.
     */
    public function dispute(GameMatch $match): void
    {
        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $match->status = GameMatch::STATUS_DISPUTED;
        $match->save();
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner. If the
     * winner changed, the stale winner/loser are replaced in the downstream
     * match slots.
     */
    public function resolve(GameMatch $match, Team $winner): void
    {
        if ($match->status !== GameMatch::STATUS_DISPUTED) {
            throw new DomainException('Only disputed matches can be resolved.');
        }

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        DB::transaction(function () use ($match, $winner) {
            $stale = $match->winner_team_id;

            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match, $stale);
        });
    }

    /**
     * Move a ready/pending match into the live state.
     */
    public function start(GameMatch $match): void
    {
        if (! in_array($match->status, [GameMatch::STATUS_PENDING, GameMatch::STATUS_READY], true)) {
            throw new DomainException('This match cannot be started from its current state.');
        }

        $match->status = GameMatch::STATUS_LIVE;
        $match->save();
    }
}
```

### FILE: app/Services/ScoringService.php
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoreAdjustment;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Free Fire scoring engine (Phase 06).
 *
 * Single source of truth for all scoring math: placement points, kill
 * points, bonuses, penalties, totals, versioned rule snapshots and
 * deterministic tie-broken standings. Controllers delegate here and never
 * compute scoring themselves.
 */
class ScoringService
{
    /**
     * The tournament's current (active) scoring rule set, lazily creating the
     * legacy default snapshot when none exists (backward compatibility).
     */
    public function currentRuleSet(Tournament $tournament): ScoringRule
    {
        $rule = $tournament->scoringRules()
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();

        if ($rule !== null) {
            return $rule;
        }

        return $this->createVersion($tournament, [
            'name' => 'Default Free Fire Rules',
            'kill_points' => ScoringRule::DEFAULT_KILL_POINTS,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
    }

    /**
     * Create a new immutable rule-set version and make it the current one.
     * Existing scores keep their own snapshots, so history never changes.
     */
    public function createVersion(Tournament $tournament, array $data): ScoringRule
    {
        $data = $this->normalizeRuleData($data);

        return DB::transaction(function () use ($tournament, $data) {
            $next = (int) ($tournament->scoringRules()->max('version') ?? 0) + 1;

            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $rule = new ScoringRule();
            $rule->tournament_id = $tournament->id;
            $rule->version = $next;
            $rule->name = $data['name'];
            $rule->kill_points = $data['kill_points'];
            $rule->placement_points = $data['placement_points'];
            $rule->tie_breakers = $data['tie_breakers'];
            $rule->is_current = true;
            $rule->save();

            return $rule;
        });
    }

    /**
     * Re-activate an existing rule-set version as current.
     */
    public function activateVersion(Tournament $tournament, ScoringRule $rule): void
    {
        if ($rule->tournament_id !== $tournament->id) {
            throw new DomainException('That rule set does not belong to this tournament.');
        }

        DB::transaction(function () use ($tournament, $rule) {
            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // A raw update always executes, even if the in-memory model still
            // thinks it is current.
            ScoringRule::where('id', $rule->id)->update(['is_current' => true]);
        });
    }

    /**
     * Deterministically compute a score breakdown from a rule snapshot.
     *
     * @return array{placement_points:int, kill_points:int, bonus_points:int, penalty_points:int, total:int}
     */
    public function breakdown(ScoringRule $rule, int $kills, int $placement, iterable $adjustments): array
    {
        $placementPoints = $rule->placementPointsFor($placement);
        $killPoints = $kills * (int) $rule->kill_points;

        $bonus = 0;
        $penalty = 0;

        foreach ($adjustments as $adjustment) {
            if ($adjustment->type === ScoreAdjustment::TYPE_PENALTY) {
                $penalty += (int) $adjustment->points;
            } else {
                $bonus += (int) $adjustment->points;
            }
        }

        $total = $placementPoints + $killPoints + $bonus - $penalty;

        return [
            'placement_points' => $placementPoints,
            'kill_points' => $killPoints,
            'bonus_points' => $bonus,
            'penalty_points' => $penalty,
            'total' => max(0, $total),
        ];
    }

    /**
     * Record a team's score for a match using the current rule snapshot.
     * Transaction-safe; the unique (match_id, team_id) constraint is the
     * race-condition backstop.
     */
    public function submitScore(GameMatch $match, Team $team, int $kills, int $placement, ?string $screenshotPath = null): Score
    {
        if (! $match->acceptsScoreSubmission()) {
            throw new DomainException('Score submission is not open for this match.');
        }

        if (! $match->hasParticipant($team)) {
            throw new DomainException('This team is not part of this match.');
        }

        if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        if ($kills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($match, $team, $kills, $placement, $screenshotPath) {
                if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
                    throw new DomainException('A score for this team has already been submitted.');
                }

                if (Score::where('match_id', $match->id)->where('placement', $placement)->exists()) {
                    throw new DomainException('Another team in this match has already claimed that placement.');
                }

                $rule = $this->currentRuleSet($match->tournament);
                $parts = $this->breakdown($rule, $kills, $placement, []);

                $score = new Score();
                $score->match_id = $match->id;
                $score->team_id = $team->id;
                $score->kills = $kills;
                $score->placement = $placement;
                $score->placement_points = $parts['placement_points'];
                $score->kill_points = $parts['kill_points'];
                $score->bonus_points = 0;
                $score->penalty_points = 0;
                $score->points = $parts['total'];
                $score->scoring_rules_id = $rule->id;
                $score->screenshot_path = $screenshotPath;
                $score->status = 'pending';
                $score->save();

                return $score;
            });
        } catch (QueryException $e) {
            // Unique (match_id, team_id) or (match_id, placement) constraint.
            throw new DomainException('A score already exists for this team or placement.');
        }
    }

    /**
     * Apply an auditable bonus/penalty to a score and recompute its totals.
     * Only possible while the match is not finalized.
     */
    public function addAdjustment(Score $score, string $type, int $points, string $reason): ScoreAdjustment
    {
        if (! in_array($type, ScoreAdjustment::TYPES, true)) {
            throw new DomainException('Adjustment type must be bonus or penalty.');
        }

        if ($points < 1) {
            throw new DomainException('Adjustment points must be a positive integer.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('An adjustment requires a reason.');
        }

        $match = $score->match;

        if ($match === null || ! $match->acceptsScoreSubmission()) {
            throw new DomainException('Scores cannot be adjusted once the match is finalized.');
        }

        return DB::transaction(function () use ($score, $type, $points, $reason) {
            $adjustment = new ScoreAdjustment();
            $adjustment->score_id = $score->id;
            $adjustment->type = $type;
            $adjustment->points = $points;
            $adjustment->reason = $reason;
            $adjustment->save();

            $this->recompute($score);

            return $adjustment;
        });
    }

    /**
     * Correct a score's raw inputs (kills and/or placement) through the
     * scoring engine and recompute every derived value.
     *
     * Phase 07 — this is the ONLY sanctioned path for a moderator/admin to
     * correct a result. It:
     *   - requires the match to be disputed or completed (i.e. inside the
     *     controlled dispute-resolution flow),
     *   - rejects negative kills and impossible placements,
     *   - rejects a placement already claimed by another team in the match,
     *   - recalculates using the score's OWN scoring-rule snapshot, so the
     *     historical scoring version is preserved,
     *   - never accepts a client-supplied total.
     */
    public function correctScore(Score $score, ?int $kills, ?int $placement): Score
    {
        $match = $score->match;

        if ($match === null) {
            throw new DomainException('This score is not attached to a match.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_DISPUTED, GameMatch::STATUS_COMPLETED], true)) {
            throw new DomainException('Scores can only be corrected during dispute resolution.');
        }

        $newKills = $kills === null ? (int) $score->kills : $kills;
        $newPlacement = $placement === null ? (int) $score->placement : $placement;

        if ($newKills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        if ($newPlacement < 1 || $newPlacement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        return DB::transaction(function () use ($score, $match, $newKills, $newPlacement) {
            $taken = Score::query()
                ->where('match_id', $match->id)
                ->where('placement', $newPlacement)
                ->where('id', '!=', $score->id)
                ->exists();

            if ($taken) {
                throw new DomainException('Another team in this match has already claimed that placement.');
            }

            $score->kills = $newKills;
            $score->placement = $newPlacement;
            $score->save();

            $this->recompute($score);

            return $score;
        });
    }

    /**
     * Recompute a score's breakdown and total from its rule snapshot and its
     * current adjustments.
     */
    public function recompute(Score $score): void
    {
        $rule = $score->scoringRule;

        if ($rule === null) {
            // Legacy score without a snapshot — fall back to current rules.
            $rule = $this->currentRuleSet($score->match->tournament);
        }

        $parts = $this->breakdown(
            $rule,
            (int) $score->kills,
            (int) $score->placement,
            $score->adjustments
        );

        $score->placement_points = $parts['placement_points'];
        $score->kill_points = $parts['kill_points'];
        $score->bonus_points = $parts['bonus_points'];
        $score->penalty_points = $parts['penalty_points'];
        $score->points = $parts['total'];
        $score->scoring_rules_id = $rule->id;
        $score->save();
    }

    /**
     * Deterministic tournament standings.
     *
     * Only scores from matches that are not bye, cancelled or disputed are
     * counted (pending matches can never hold scores). Rows are ordered by
     * the current rule set's tie-breaker chain and finally by team id, so
     * identical inputs always produce an identical ranking.
     */
    public function standings(Tournament $tournament): Collection
    {
        $rule = $this->currentRuleSet($tournament);

        $scores = Score::query()
            ->whereHas('match', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereNotIn('status', [
                    GameMatch::STATUS_BYE,
                    GameMatch::STATUS_CANCELLED,
                    GameMatch::STATUS_DISPUTED,
                ]))
            ->with('team')
            ->get();

        $rows = [];

        foreach ($scores as $score) {
            $teamId = $score->team_id;

            if (! isset($rows[$teamId])) {
                $rows[$teamId] = [
                    'team_id' => $teamId,
                    'team' => $score->team,
                    'matches_played' => 0,
                    'kills' => 0,
                    'placement_points' => 0,
                    'kill_points' => 0,
                    'points' => 0,
                    'best_placement' => null,
                ];
            }

            $rows[$teamId]['matches_played']++;
            $rows[$teamId]['kills'] += (int) $score->kills;
            $rows[$teamId]['placement_points'] += (int) $score->placement_points;
            $rows[$teamId]['kill_points'] += (int) $score->kill_points;
            $rows[$teamId]['points'] += (int) $score->points;

            $best = $rows[$teamId]['best_placement'];
            if ($best === null || (int) $score->placement < $best) {
                $rows[$teamId]['best_placement'] = (int) $score->placement;
            }
        }

        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) use ($rule) {
            foreach ($rule->tieBreakers() as $key) {
                $comparison = $this->compareMetric($key, $a, $b);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            // Deterministic final fallback — never database-order dependent.
            return $a['team_id'] <=> $b['team_id'];
        });

        $result = [];
        $rank = 0;

        foreach ($rows as $row) {
            $row['rank'] = ++$rank;
            $result[] = (object) $row;
        }

        return new Collection($result);
    }

    /**
     * Compare two aggregated rows on a single metric.
     *
     * @return int negative when $a sorts before $b
     */
    protected function compareMetric(string $key, array $a, array $b): int
    {
        return match ($key) {
            'points' => (int) $b['points'] <=> (int) $a['points'],
            'placement_points' => (int) $b['placement_points'] <=> (int) $a['placement_points'],
            'kill_points' => (int) $b['kill_points'] <=> (int) $a['kill_points'],
            'kills' => (int) $b['kills'] <=> (int) $a['kills'],
            'best_placement' => ($a['best_placement'] ?? PHP_INT_MAX) <=> ($b['best_placement'] ?? PHP_INT_MAX),
            default => 0,
        };
    }

    /**
     * Validate and normalise rule-set input (server-authoritative — the
     * controller's request validation is a first line, this is the last).
     */
    protected function normalizeRuleData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = 'Scoring rules';
        }

        $killPoints = (int) ($data['kill_points'] ?? ScoringRule::DEFAULT_KILL_POINTS);
        if ($killPoints < 0) {
            throw new DomainException('Kill points cannot be negative.');
        }

        $placementPoints = [];
        $provided = $data['placement_points'] ?? null;

        if (is_array($provided)) {
            foreach ($provided as $placement => $points) {
                $placement = (int) $placement;
                if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
                    continue;
                }
                if ((int) $points < 0) {
                    throw new DomainException('Placement points cannot be negative.');
                }
                $placementPoints[$placement] = (int) $points;
            }
        }

        if ($placementPoints === []) {
            throw new DomainException('A placement point table is required.');
        }

        $tieBreakers = $data['tie_breakers'] ?? ScoringRule::DEFAULT_TIE_BREAKERS;
        $allowed = array_keys(ScoringRule::TIE_BREAKER_OPTIONS);
        $chain = is_array($tieBreakers)
            ? array_values(array_filter(array_map('strval', $tieBreakers), fn ($k) => in_array($k, $allowed, true)))
            : [];

        if ($chain === []) {
            $chain = ScoringRule::DEFAULT_TIE_BREAKERS;
        }

        if (! in_array('points', $chain, true)) {
            array_unshift($chain, 'points');
        }

        return [
            'name' => $name,
            'kill_points' => $killPoints,
            'placement_points' => $placementPoints,
            'tie_breakers' => array_values(array_unique($chain)),
        ];
    }
}
```

### FILE: app/Policies/GameMatchPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\GameMatch;
use App\Models\User;

class GameMatchPolicy
{
    /**
     * Privileged match management (publish room, set winner, dispute, resolve,
     * correct results). Only the owning organizer or an admin may do this.
     * Players can NEVER manage, dispute or resolve matches.
     */
    public function manage(User $user, GameMatch $match): bool
    {
        return $user->isAdmin()
            || $match->tournament->organizer_id === $user->id;
    }

    /**
     * Entering the disputed state is a privileged bracket operation.
     */
    public function dispute(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }

    /**
     * Resolving a dispute (including correcting a winner) is privileged and
     * audited via the same organizer/admin gate.
     */
    public function resolve(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }

    /**
     * Opening a Phase 07 dispute: staff (admin/moderator/organizer) or the
     * captain of a participating team. The dispute window and other business
     * rules are enforced by DisputeService.
     */
    public function openDispute(User $user, GameMatch $match): bool
    {
        if ($user->isAdmin() || $user->isModerator() || $match->tournament->organizer_id === $user->id) {
            return true;
        }

        return $match->participantTeamFor($user) !== null;
    }
}
```

### FILE: app/Http/Controllers/MatchController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
    ) {
    }

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
            'placement' => 'required|integer|min:1|max:' . ScoringRule::MAX_PLACEMENT,
            'screenshot' => 'nullable|image|max:2048',
        ]);

        $team = Team::find($data['team_id']);

        // The submitted team MUST be an actual participant of this match.
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $this->authorize('submitScore', $team);

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

        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');
    }
}
```

### FILE: app/Http/Controllers/AdminController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'tournaments' => Tournament::count(),
            'teams' => Team::count(),
            'verified_payments' => Payment::where('status', 'verified')->count(),
            'revenue' => Payment::where('status', 'verified')->sum('amount'),
            'commission' => Payment::where('status', 'verified')->sum('amount') * 0.08,
        ];

        $pendingPayments = Payment::with(['team', 'tournament'])->where('status', 'pending')->latest()->limit(20)->get();
        $moderators = User::where('role', 'moderator')->orderBy('name')->get();

        return view('admin.dashboard', compact('stats', 'pendingPayments', 'moderators'));
    }

    public function verifyPayment(Payment $payment)
    {
        // Prevent double-processing.
        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment has already been processed.');
        }

        DB::transaction(function () use ($payment) {
            $payment->status = 'verified';
            $payment->save();

            $team = $payment->team;
            if ($team && $team->status === 'pending') {
                $team->status = 'confirmed';
                $team->save();
            }
        });

        return back()->with('success', 'Payment verified. Team confirmed.');
    }

    public function rejectPayment(Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return back()->with('error', 'This payment has already been processed.');
        }

        $payment->status = 'failed';
        $payment->save();

        return back()->with('success', 'Payment rejected.');
    }

    /**
     * Promote a user to moderator (admin only — the route sits behind the
     * `admin` middleware, and `role` is never mass-assignable).
     */
    public function makeModerator(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isAdmin()) {
            return back()->with('error', 'Admins are already staff.');
        }

        if ($user->isModerator()) {
            return back()->with('error', $user->name . ' is already a moderator.');
        }

        $user->role = 'moderator';
        $user->save();

        return back()->with('success', $user->name . ' is now a moderator.');
    }

    /**
     * Demote a moderator back to a regular player (admin only).
     */
    public function removeModerator(User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot demote an admin.');
        }

        if (! $user->isModerator()) {
            return back()->with('error', 'This user is not a moderator.');
        }

        $user->role = 'player';
        $user->save();

        return back()->with('success', $user->name . ' is no longer a moderator.');
    }
}
```

### FILE: app/Http/Controllers/TournamentController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\BracketService;
use App\Services\TournamentLifecycleService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(
        protected TournamentLifecycleService $lifecycle,
        protected TournamentParticipationService $participation,
    ) {
    }

    public function index()
    {
        // Draft and cancelled tournaments are not shown publicly.
        $tournaments = Tournament::with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', Tournament::PUBLIC_STATUSES)
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('tournaments.index', compact('tournaments'));
    }

    public function show(Tournament $tournament)
    {
        $tournament->load([
            'organizer',
            'confirmedTeams',
            'matches' => fn ($q) => $q->orderBy('bracket')->orderBy('round')->orderBy('match_no'),
        ]);

        $myTeam = null;
        if (auth()->check()) {
            $myTeam = $tournament->teams()->where('captain_id', auth()->id())->first();
        }

        // Waitlist is shown to organizers/admin (and positions are shown to
        // the relevant captains via their own team's waitlistPosition()).
        $waitlist = null;
        if (auth()->check() && (auth()->user()->isAdmin() || auth()->user()->isOrganizer())) {
            $waitlist = $tournament->waitlistedTeams()
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->get();
        }

        return view('tournaments.show', compact('tournament', 'myTeam', 'waitlist'));
    }

    public function create()
    {
        $this->authorize('create', Tournament::class);

        return view('tournaments.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Tournament::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date|after:now',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at|before_or_equal:starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
            'dispute_window_hours' => 'nullable|integer|min:0|max:720',
        ]);

        // organizer_id, slug and status are server-controlled — a client can
        // never inject them. New tournaments always start as DRAFT.
        $tournament = new Tournament();
        $tournament->organizer_id = $request->user()->id;
        $tournament->name = $data['name'];
        $tournament->slug = Str::slug($data['name']).'-'.Str::random(6);
        $tournament->game_mode = $data['game_mode'];
        $tournament->map = $data['map'];
        $tournament->entry_fee = $data['entry_fee'];
        $tournament->prize_pool = $data['prize_pool'];
        $tournament->team_slots = $data['team_slots'];
        $tournament->team_size = $data['team_size'];
        $tournament->rules = $data['rules'] ?? null;
        $tournament->starts_at = $data['starts_at'];
        $tournament->check_in_starts_at = $data['check_in_starts_at'] ?? null;
        $tournament->check_in_ends_at = $data['check_in_ends_at'] ?? null;
        $tournament->format = $data['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $tournament->dispute_window_hours = $data['dispute_window_hours'] ?? 24;
        $tournament->status = Tournament::STATUS_DRAFT;
        $tournament->save();

        return redirect()
            ->route('tournaments.show', $tournament)
            ->with('success', 'Tournament created as draft. Publish it to open registration.');
    }

    public function edit(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        return view('tournaments.edit', compact('tournament'));
    }

    public function update(Request $request, Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
            'dispute_window_hours' => 'nullable|integer|min:0|max:720',
        ]);

        // fill() only touches mass-assignable fields, so a client cannot
        // tamper with organizer_id, slug or status through this endpoint.
        $tournament->fill($data)->save();

        return redirect()->route('tournaments.show', $tournament)->with('success', 'Tournament updated.');
    }

    public function publish(Tournament $tournament)
    {
        $this->authorize('publish', $tournament);

        try {
            $this->lifecycle->publish($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament published — registration is now open.');
    }

    public function closeRegistration(Tournament $tournament)
    {
        $this->authorize('closeRegistration', $tournament);

        try {
            $this->lifecycle->closeRegistration($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Registration closed.');
    }

    public function start(Tournament $tournament, BracketService $bracket)
    {
        $this->authorize('start', $tournament);

        try {
            $count = $this->lifecycle->start($tournament, $bracket);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Bracket generated with {$count} matches. Tournament is LIVE!");
    }

    public function complete(Tournament $tournament)
    {
        $this->authorize('complete', $tournament);

        try {
            $this->lifecycle->complete($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament marked as finished. Congratulations to the winners!');
    }

    public function cancel(Tournament $tournament)
    {
        $this->authorize('cancel', $tournament);

        try {
            $this->lifecycle->cancel($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament cancelled.');
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (after the check-in
     * window closes) and promote waitlisted teams into the freed slots.
     */
    public function markNoShows(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $result = $this->participation->markNoShowsAndPromote($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Marked {$result['no_shows']} team(s) as no-show.";

        if ($result['promoted'] > 0) {
            $message .= " Promoted {$result['promoted']} team(s) from the waitlist.";
        }

        return back()->with('success', $message);
    }

    /**
     * Promote the next waitlisted team into a free slot.
     */
    public function promoteWaitlisted(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $team = $this->participation->promoteNext($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$team->name} promoted from the waitlist.");
    }
}
```

### FILE: routes/web.php
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/reject', [AdminController::class, 'rejectPayment'])->name('payments.reject');
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');
    });
});
```

### FILE: resources/views/matches/show.blade.php
```blade
@extends('layouts.app')
@section('title', 'Match — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back to tournament</a>
        <h1 style="margin-top:6px">
            {{ $match->roundLabel() }} — Match #{{ $match->match_no }}
        </h1>
        <div class="muted" style="font-size:13px">{{ $match->bracketLabel() }}</div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>⚔️ Matchup</h3>
            @if($match->isBye())
                @php $byeTeam = $match->team1 ?: $match->team2; @endphp
                <div class="bracket-team win">
                    <strong>{{ $byeTeam->name ?? 'TBD' }}</strong>
                </div>
                <p class="muted" style="text-align:center; margin:10px 0">— bye, automatically advanced —</p>
            @else
                <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
                    <strong>{{ $match->team1?->name ?? 'TBD' }}</strong>
                </div>
                <div style="text-align:center; color:var(--purple); font-weight:800; padding:4px 0">VS</div>
                <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
                    <strong>{{ $match->team2?->name ?? 'TBD' }}</strong>
                </div>
                @if($match->winner)
                    <div class="muted" style="margin-top:12px; font-size:13px">
                        Winner: <strong class="tag">{{ $match->winner->name }}</strong>
                    </div>
                @endif
            @endif
            <div class="muted" style="margin-top:12px; font-size:13px">
                Status: <span class="pill {{ $match->statusPill() }}">{{ strtoupper($match->status) }}</span>
            </div>
            @if($match->nextMatch)
                <div class="muted" style="margin-top:8px; font-size:13px">
                    Next: <a href="{{ route('matches.show', [$tournament, $match->nextMatch]) }}">
                        {{ $match->nextMatch->roundLabel() }} #{{ $match->nextMatch->match_no }}
                        @if($match->next_slot) (slot {{ $match->next_slot }}) @endif
                    </a>
                </div>
            @endif
            @if($match->loserNextMatch)
                <div class="muted" style="margin-top:4px; font-size:13px">
                    Loser drops to: <a href="{{ route('matches.show', [$tournament, $match->loserNextMatch]) }}">
                        {{ $match->loserNextMatch->roundLabel() }} #{{ $match->loserNextMatch->match_no }}
                        @if($match->loser_slot) (slot {{ $match->loser_slot }}) @endif
                    </a>
                </div>
            @endif
        </div>

        <div class="card">
            <h3>🎟 Room Info</h3>
            @if($match->room_id)
                <div style="font-size:15px">
                    Room ID: <strong class="tag">{{ $match->room_id }}</strong><br>
                    Password: <strong class="tag">{{ $match->room_pass }}</strong><br>
                    Time: {{ optional($match->scheduled_at)->format('d M, h:i A') }}
                </div>
            @else
                <p class="muted">Room details not published yet.</p>
            @endif

            @auth
                @if(auth()->user()->isAdmin() || auth()->user()->isOrganizer())
                    <form method="POST" action="{{ route('matches.room', [$tournament, $match]) }}" style="margin-top:12px">
                        @csrf
                        <div class="grid cols-2">
                            <div><label>Room ID</label><input type="text" name="room_id" required></div>
                            <div><label>Password</label><input type="text" name="room_pass" required></div>
                        </div>
                        <button class="btn btn-sm btn-cyan" style="margin-top:10px">Publish Room & Start Match</button>
                    </form>
                @endif
            @endauth
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>📊 Submitted Scores</h3>
            @if($match->scores->isEmpty())
                <p class="muted">No scores submitted yet.</p>
            @else
                <table>
                    <tr>
                        <th>Team</th><th>Kills</th><th>Place</th>
                        <th>Place Pts</th><th>Kill Pts</th><th>Adj</th><th>Total</th><th>Proof</th>
                    </tr>
                    @foreach($match->scores as $score)
                        <tr>
                            <td><strong>{{ $score->team->name }}</strong></td>
                            <td>{{ $score->kills }}</td>
                            <td>#{{ $score->placement }}</td>
                            <td>{{ $score->placement_points }}</td>
                            <td>{{ $score->kill_points }}</td>
                            <td>
                                @if($score->adjustments->isEmpty())
                                    <span class="muted">—</span>
                                @else
                                    @foreach($score->adjustments as $adj)
                                        <span class="pill {{ $adj->isBonus() ? 'confirmed' : 'finished' }}">
                                            {{ $adj->isBonus() ? '+' : '−' }}{{ $adj->points }}
                                        </span>
                                    @endforeach
                                @endif
                            </td>
                            <td><strong class="tag">{{ $score->points }}</strong></td>
                            <td>
                                @if($score->screenshot_path)
                                    <a href="{{ asset('storage/' . $score->screenshot_path) }}" target="_blank" class="btn btn-sm">View</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @if($score->adjustments->isNotEmpty())
                            <tr>
                                <td colspan="8" style="background:var(--panel2); font-size:12px">
                                    <span class="muted">Adjustments:</span>
                                    @foreach($score->adjustments as $adj)
                                        <span class="muted">{{ $adj->isBonus() ? 'Bonus' : 'Penalty' }} {{ $adj->points }}pt — "{{ $adj->reason }}"</span>
                                        @if(!$loop->last) · @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                        @if($score->scoringRule)
                            <tr>
                                <td colspan="8" style="background:var(--panel2); font-size:12px">
                                    <span class="muted">Scoring rules: {{ $score->scoringRule->label() }} (v{{ $score->scoringRule->version }})</span>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>📝 Submit Score</h3>
            @if($match->acceptsScoreSubmission())
                <form method="POST" action="{{ route('matches.score', [$tournament, $match]) }}" enctype="multipart/form-data">
                    @csrf
                    <label>Your team</label>
                    <select name="team_id" required>
                        @if($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                        @if($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                    </select>
                    <div class="grid cols-2">
                        <div><label>Kills</label><input type="number" name="kills" min="0" value="0" required></div>
                        <div><label>Placement</label><input type="number" name="placement" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" value="1" required></div>
                    </div>
                    <label>Screenshot (proof)</label>
                    <input type="file" name="screenshot" accept="image/*">
                    <button class="btn btn-primary btn-sm" style="margin-top:12px">Submit Score</button>
                </form>
            @else
                <p class="muted">Score submission is not open for this match.</p>
            @endif

            @auth
                @if(auth()->user()->isAdmin() || (auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id))
                    <hr style="border-color:var(--line); margin:16px 0">

                    @if($match->acceptsScoreSubmission() && $match->scores->isNotEmpty())
                        <h3>⚖ Score Adjustment (bonus / penalty)</h3>
                        <form method="POST" action="{{ route('matches.adjustment', [$tournament, $match]) }}">
                            @csrf
                            <label>Team</label>
                            <select name="team_id" required>
                                @foreach($match->scores as $score)
                                    <option value="{{ $score->team_id }}">{{ $score->team->name }} ({{ $score->points }} pts)</option>
                                @endforeach
                            </select>
                            <div class="grid cols-2">
                                <div>
                                    <label>Type</label>
                                    <select name="type" required>
                                        <option value="bonus">Bonus (+)</option>
                                        <option value="penalty">Penalty (−)</option>
                                    </select>
                                </div>
                                <div>
                                    <label>Points</label>
                                    <input type="number" name="points" min="1" max="1000" value="1" required>
                                </div>
                            </div>
                            <label>Reason (required, audited)</label>
                            <input type="text" name="reason" placeholder="e.g. Booyah bonus" maxlength="255" required>
                            <button class="btn btn-sm btn-cyan" style="margin-top:10px">Apply Adjustment</button>
                        </form>
                        <hr style="border-color:var(--line); margin:16px 0">
                    @endif

                    @if($match->acceptsScoreSubmission())
                        <h3>✅ Set Winner</h3>
                        <form method="POST" action="{{ route('matches.winner', [$tournament, $match]) }}">
                            @csrf
                            <label>Winner team</label>
                            <select name="winner_team_id" required>
                                @if($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                                @if($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                            </select>
                            <button class="btn btn-green btn-sm" style="margin-top:10px">Confirm Winner (advances bracket)</button>
                        </form>
                    @endif

                    @if($match->status === 'completed')
                        <form method="POST" action="{{ route('matches.dispute', [$tournament, $match]) }}">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red); margin-top:10px"
                                onclick="return confirm('Mark this completed match as disputed? Advancement will be blocked until resolved.')">
                                ⚠ Mark Disputed
                            </button>
                        </form>
                    @endif

                    @if($match->status === 'disputed')
                        <h3>⚖ Resolve Dispute</h3>
                        <p class="muted" style="font-size:13px">Choose the correct winner. The bracket will be re-advanced.</p>
                        <form method="POST" action="{{ route('matches.resolve', [$tournament, $match]) }}">
                            @csrf
                            <label>Winner team</label>
                            <select name="winner_team_id" required>
                                @if($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                @if($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                            </select>
                            <button class="btn btn-green btn-sm" style="margin-top:10px">Resolve Dispute</button>
                        </form>
                    @endif
                @endif
            @endauth
        </div>
    </div>

    <div class="card">
        <h3>🚩 Disputes</h3>
        @if($match->disputes->isEmpty())
            <p class="muted">No disputes for this match.</p>
        @else
            <table>
                <tr><th>#</th><th>Category</th><th>Status</th><th>Opened by</th><th>Opened</th><th></th></tr>
                @foreach($match->disputes as $dispute)
                    <tr>
                        <td><strong>#{{ $dispute->id }}</strong></td>
                        <td>{{ $dispute->categoryLabel() }}</td>
                        <td><span class="pill {{ $dispute->statusPill() }}">{{ $dispute->statusLabel() }}</span></td>
                        <td>{{ $dispute->opener?->name ?? 'System' }}</td>
                        <td class="muted" style="font-size:12px">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                        <td>
                            <a href="{{ route('matches.disputes.show', [$tournament, $match, $dispute]) }}" class="btn btn-sm">View</a>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif

        @auth
            @if($canOpenDispute)
                <a href="{{ route('matches.disputes.create', [$tournament, $match]) }}" class="btn btn-sm" style="border-color:var(--red); color:var(--red); margin-top:12px">🚩 Open Dispute</a>
            @endif
        @endauth
    </div>
@endsection
```

### FILE: resources/views/layouts/app.blade.php
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FF Arena') — Bangladesh Free Fire Tournaments</title>
    <style>
        :root {
            --bg: #0b0e1a;
            --panel: #141a2e;
            --panel2: #1b2340;
            --line: #28335a;
            --txt: #e8ecff;
            --muted: #8a93b8;
            --cyan: #22d3ee;
            --purple: #a855f7;
            --green: #34d399;
            --red: #f87171;
            --amber: #fbbf24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--txt);
            min-height: 100vh;
            background-image: radial-gradient(1200px 600px at 80% -10%, rgba(168,85,247,.14), transparent),
                              radial-gradient(900px 500px at -10% 110%, rgba(34,211,238,.12), transparent);
        }
        a { color: var(--cyan); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        nav {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 0; border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: .5px; }
        .brand span { color: var(--cyan); }
        .nav-links { display: flex; gap: 18px; align-items: center; margin-left: auto; flex-wrap: wrap; }
        .btn {
            display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line);
            background: var(--panel2); color: var(--txt); font-weight: 600; font-size: 14px; cursor: pointer;
            transition: .15s;
        }
        .btn:hover { border-color: var(--cyan); text-decoration: none; }
        .btn-primary { background: linear-gradient(90deg, #7c3aed, #2563eb); border: none; color: #fff; }
        .btn-primary:hover { filter: brightness(1.12); }
        .btn-cyan { background: rgba(34,211,238,.12); border: 1px solid var(--cyan); color: var(--cyan); }
        .btn-green { background: rgba(52,211,153,.12); border: 1px solid var(--green); color: var(--green); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 14px;
            padding: 20px; margin-bottom: 18px;
        }
        .grid { display: grid; gap: 18px; }
        .cols-3 { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        h1 { font-size: 26px; margin-bottom: 8px; }
        h2 { font-size: 20px; margin-bottom: 12px; }
        h3 { font-size: 16px; margin-bottom: 6px; }
        .muted { color: var(--muted); }
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
        }
        .pill.open { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.live { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.closed, .pill.finished, .pill.disputed { background: rgba(248,113,113,.15); color: var(--red); }
        .pill.draft { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.pending { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.ready { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.confirmed, .pill.verified, .pill.checked { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.waitlisted { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed, .pill.no_show, .pill.bye { background: rgba(148,163,184,.15); color: var(--muted); }
        form label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
        input, select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line);
            background: #0d1226; color: var(--txt); font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--cyan); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .flash { padding: 12px 16px; border-radius: 10px; margin: 16px 0; font-weight: 600; }
        .flash.success { background: rgba(52,211,153,.15); color: var(--green); border: 1px solid rgba(52,211,153,.4); }
        .flash.error { background: rgba(248,113,113,.15); color: var(--red); border: 1px solid rgba(248,113,113,.4); }
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .stat .num { font-size: 26px; font-weight: 800; color: var(--cyan); }
        .bracket-col { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; overflow-x: auto; padding-bottom: 10px; }
        .bracket-round { display: flex; flex-direction: column; gap: 14px; min-width: 190px; }
        .bracket-match { background: var(--panel2); border: 1px solid var(--line); border-radius: 10px; padding: 8px; }
        .bracket-team { padding: 7px 10px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
        .bracket-team.win { background: rgba(52,211,153,.12); color: var(--green); font-weight: 700; }
        .bracket-team.bye { color: var(--muted); }
        .divider { height: 1px; background: var(--line); margin: 4px 0; }
        footer { border-top: 1px solid var(--line); margin-top: 50px; padding: 22px 0; color: var(--muted); font-size: 13px; }
        .tag { color: var(--purple); font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <nav>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                    <a href="{{ route('moderation.index') }}" class="btn btn-sm">Moderation</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm">Admin</a>
                @endif
                <span class="muted">{{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-sm">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-sm">Login</a>
                <a href="{{ route('register') }}" class="btn btn-sm btn-primary">Register</a>
            @endauth
        </div>
    </nav>

    @if(session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash error">
            <ul style="list-style:none;padding:0;margin:0">
                @foreach($errors->all() as $e) <li>• {{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @yield('content')

    <footer>
        <div class="container" style="padding:0">
            <strong class="tag">FF Arena</strong> — Bangladesh's Free Fire tournament platform.
            Legit. Smart. Profitable. No hacks, ever. 🤝
        </div>
    </footer>
</div>
</body>
</html>
```

### FILE: resources/views/admin/dashboard.blade.php
```blade
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Admin Dashboard</h1>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color:var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}" style="display:flex; gap:10px; align-items:end">
            @csrf
            <div style="flex:1; max-width:320px">
                <label>Promote a user to moderator (by email)</label>
                <input type="email" name="email" placeholder="user@example.com" required>
            </div>
            <button class="btn btn-cyan btn-sm">Promote</button>
        </form>
        @if($moderators->isEmpty())
            <p class="muted" style="margin-top:12px">No moderators yet.</p>
        @else
            <table style="margin-top:12px">
                <tr><th>Name</th><th>Email</th><th></th></tr>
                @foreach($moderators as $moderator)
                    <tr>
                        <td>{{ $moderator->name }}</td>
                        <td class="muted">{{ $moderator->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Demote</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card" style="margin-top:18px">
        <h3>💸 Pending Payments</h3>
        @if($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <table>
                <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>TrxID</th><th>Action</th></tr>
                @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->tournament->name }}</td>
                        <td>{{ $p->team->name }}</td>
                        <td>৳{{ number_format($p->amount, 2) }}</td>
                        <td>{{ $p->trx_id }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.payments.verify', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-green btn-sm">Verify</button>
                            </form>
                            <form method="POST" action="{{ route('admin.payments.reject', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-sm">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
```

### FILE: resources/views/tournaments/create.blade.php
```blade
@extends('layouts.app')
@section('title', 'Create Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>🎮 Create a Tournament</h2>
        <form method="POST" action="{{ route('tournaments.store') }}">
            @csrf
            <label>Tournament name</label>
            <input type="text" name="name" value="{{ old('name') }}" placeholder="Squad Showdown 32 Teams" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Game mode</label>
                    <select name="game_mode">
                        <option value="squad">Squad</option>
                        <option value="duo">Duo</option>
                        <option value="solo">Solo</option>
                    </select>
                </div>
                <div>
                    <label>Map</label>
                    <select name="map">
                        <option>Bermuda</option>
                        <option>Purgatory</option>
                        <option>Kalahari</option>
                        <option>Alpine</option>
                    </select>
                </div>
                <div>
                    <label>Entry fee (৳ per team)</label>
                    <input type="number" name="entry_fee" value="{{ old('entry_fee', 100) }}" min="0" required>
                </div>
                <div>
                    <label>Prize pool (৳)</label>
                    <input type="number" name="prize_pool" value="{{ old('prize_pool', 5000) }}" min="0" required>
                </div>
                <div>
                    <label>Team slots</label>
                    <select name="team_slots">
                        <option value="8">8</option>
                        <option value="16" selected>16</option>
                        <option value="32">32</option>
                    </select>
                </div>
                <div>
                    <label>Players per team</label>
                    <input type="number" name="team_size" value="{{ old('team_size', 4) }}" min="1" max="6" required>
                </div>
                <div>
                    <label>Bracket format</label>
                    <select name="format">
                        <option value="single_elim" @selected(old('format', 'single_elim') === 'single_elim')>Single Elimination</option>
                        <option value="double_elim" @selected(old('format') === 'double_elim')>Double Elimination</option>
                    </select>
                    <p class="muted" style="font-size:12px; margin-top:4px">Double elimination requires a full power-of-two field (8, 16 or 32 eligible teams).</p>
                </div>
            </div>
            <label>Start date & time</label>
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Check-in opens (optional)</label>
                    <input type="datetime-local" name="check_in_starts_at" value="{{ old('check_in_starts_at') }}">
                </div>
                <div>
                    <label>Check-in closes (optional)</label>
                    <input type="datetime-local" name="check_in_ends_at" value="{{ old('check_in_ends_at') }}">
                </div>
            </div>
            <p class="muted" style="font-size:12px; margin-top:6px">Leave check-in empty to skip check-in (all confirmed teams enter the bracket).</p>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Dispute window (hours)</label>
                    <input type="number" name="dispute_window_hours" value="{{ old('dispute_window_hours', 24) }}" min="0" max="720">
                    <p class="muted" style="font-size:12px; margin-top:4px">How long participants have to dispute a result after a match ends. 0 disables participant disputes.</p>
                </div>
            </div>
            <label>Rules</label>
            <textarea name="rules" rows="4" placeholder="1. No hacks — instant ban.&#10;2. Screenshot mandatory.">{{ old('rules') }}</textarea>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Create Tournament</button>
        </form>
    </div>
@endsection
```

### FILE: resources/views/tournaments/edit.blade.php
```blade
@extends('layouts.app')
@section('title', 'Edit Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>Edit: {{ $tournament->name }}</h2>
        <form method="POST" action="{{ route('tournaments.update', $tournament) }}">
            @csrf
            @method('PUT')
            <label>Tournament name</label>
            <input type="text" name="name" value="{{ $tournament->name }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Game mode</label>
                    <select name="game_mode">
                        @foreach(['squad','duo','solo'] as $m)
                            <option value="{{ $m }}" @selected($tournament->game_mode === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Map</label>
                    <input type="text" name="map" value="{{ $tournament->map }}" required>
                </div>
                <div>
                    <label>Entry fee (৳)</label>
                    <input type="number" name="entry_fee" value="{{ $tournament->entry_fee }}" min="0" required>
                </div>
                <div>
                    <label>Prize pool (৳)</label>
                    <input type="number" name="prize_pool" value="{{ $tournament->prize_pool }}" min="0" required>
                </div>
                <div>
                    <label>Team slots</label>
                    <select name="team_slots">
                        @foreach([8,16,32] as $s)
                            <option value="{{ $s }}" @selected($tournament->team_slots == $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Players per team</label>
                    <input type="number" name="team_size" value="{{ $tournament->team_size }}" min="1" max="6" required>
                </div>
                <div>
                    <label>Bracket format</label>
                    <select name="format">
                        @foreach(\App\Models\Tournament::FORMATS as $f)
                            <option value="{{ $f }}" @selected($tournament->format === $f)>
                                {{ $f === 'double_elim' ? 'Double Elimination' : 'Single Elimination' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label>Start date & time</label>
            <input type="datetime-local" name="starts_at" value="{{ optional($tournament->starts_at)->format('Y-m-d\TH:i') }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Check-in opens (optional)</label>
                    <input type="datetime-local" name="check_in_starts_at" value="{{ optional($tournament->check_in_starts_at)->format('Y-m-d\TH:i') }}">
                </div>
                <div>
                    <label>Check-in closes (optional)</label>
                    <input type="datetime-local" name="check_in_ends_at" value="{{ optional($tournament->check_in_ends_at)->format('Y-m-d\TH:i') }}">
                </div>
            </div>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Dispute window (hours)</label>
                    <input type="number" name="dispute_window_hours" value="{{ $tournament->disputeWindowHours() }}" min="0" max="720">
                    <p class="muted" style="font-size:12px; margin-top:4px">0 disables participant disputes. Staff always bypass the window.</p>
                </div>
            </div>
            <label>Rules</label>
            <textarea name="rules" rows="4">{{ $tournament->rules }}</textarea>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Save Changes</button>
        </form>
    </div>
@endsection
```
