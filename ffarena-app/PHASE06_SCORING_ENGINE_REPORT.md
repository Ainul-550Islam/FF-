# Phase 06 — Free Fire Scoring Engine Report

**Project:** FF Arena (Laravel 12 + SQLite) · **Date:** 2026-09-06
**Scope:** Configurable, versioned, server-authoritative Free Fire scoring engine
with deterministic tie-breakers, layered on the Phase 01–05 tournament/bracket
app without regressing it. Phase 07+ features are explicitly out of scope.

---

## 1. Initial Scoring Audit

Before any change, the Phase 01–05 scoring behaviour was inspected in place
(not from memory):

- `app/Models/Score.php` — `$fillable = ['kills', 'placement', 'points',
  'screenshot_path']`; `match_id` / `team_id` / `status` are server-controlled.
- `database/migrations/2026_09_04_000006_create_scores_table.php` + the
  `2026_09_04_100000_add_unique_constraint_to_scores_table.php` follow-up —
  `kills` (default 0), `placement` (default 1), `points` (default 0), `status`
  (pending/approved/rejected), unique `(match_id, team_id)`.
- `app/Http/Controllers/MatchController.php` — the legacy placement map was
  hardcoded: `[1=>12, 2=>9, 3=>7, 4=>5, 5=>4, 6=>3, 7=>2, 8=>1]`; total =
  `kills + (map[placement] ?? 1)`; validation: `kills` int ≥ 0, `placement`
  int 1..12, team must be a participant, match must be `ready|live`, one score
  per `(match_id, team_id)` (app check + DB unique).
- `app/Http/Controllers/LeaderboardController.php` — aggregated with a SQL
  `GROUP BY team_id` (`SUM(points)`, `SUM(kills)`, `COUNT(*)`), ordered only by
  `SUM(points) DESC` — **no deterministic tie-breaker** (the principal
  determinism gap this phase closes).
- `resources/views/leaderboard/show.blade.php` — rank/team/matches/kills/points
  table, no tie-break display.
- Existing scoring assertions (preserved): 6 kills + placement 1 = **18 points**
  (12 placement + 6 kill) in `AuthorizedWorkflowTest`; negative kills rejected;
  duplicate submission rejected; non-participant team rejected; cross-tournament
  match rejected; captain-only submission in `SecurityAuthorizationTest`.

**Conclusion:** placement/kill points, total calculation and ordering were
hardcoded/scattered; there was no rule configuration, no versioning, no
bonus/penalty, and no deterministic tie-breaker.

## 2. Architecture Decisions

1. **Single scoring domain service** — `App\Services\ScoringService` is the only
   place that computes placement points, kill points, bonuses, penalties,
   totals, tie-break values and standings. Controllers (match submission,
   leaderboard, rule management) delegate to it; no scoring formula is
   duplicated in any controller or view.
2. **Immutable versioned rule snapshots** — rule changes never edit an existing
   `scoring_rules` row; they insert a new `version` row and flip `is_current`.
   Every `Score` stores `scoring_rules_id`, so a result remains reproducible
   after the tournament's rules change.
3. **Server-authoritative calculation** — clients submit only raw inputs
   (`kills`, `placement`). `points`, `placement_points`, `kill_points`,
   `bonus_points`, `penalty_points`, `scoring_rules_id`, and rank are all
   computed server-side and are not mass-assignable.
4. **Auditable adjustments** — bonuses/penalties are rows in
   `score_adjustments` with an explicit `type`, `points` and mandatory `reason`;
   they are allowed only while the match is `ready|live` and are frozen once
   the match is finalized.
5. **Deterministic ordering** — standings sort by the configured tie-breaker
   chain, with a final `team_id` fallback so identical inputs always produce an
   identical ranking (never DB-order dependent).
6. **Integrity via DB constraints** — unique `(match_id, team_id)` and unique
   `(match_id, placement)`, plus FKs, give a race-condition backstop on top of
   the application checks.

## 3. Rule Implementation

- **Placement points** — per-placement integer map (1..12). Any placement
  absent from the map falls back to **1 point** (legacy behaviour).
- **Kill points** — a single configurable integer; `kill_points = kills ×
  points-per-kill`.
- **Bonuses/penalties** — typed, reasoned `score_adjustments` rows summed into
  `bonus_points` / `penalty_points`.
- **Total** — `placement_points + kill_points + bonus_points − penalty_points`,
  floored at 0 (never negative).
- Defaults reproduce the legacy engine exactly: 1st=12, 2nd=9, 3rd=7, 4th=5,
  5th=4, 6th=3, 7th=2, 8th=1, later=1, kill=1 point.

## 4. Tie-Breakers

Configurable ordered chain, restricted to metrics that exist in the data model:

| Key | Meaning | Sort |
|-----|---------|------|
| `points` | total points | descending |
| `placement_points` | placement points only | descending |
| `kill_points` | kill points only | descending |
| `kills` | total kills | descending |
| `best_placement` | best (lowest) placement | ascending |

`points` is always forced to the front of the chain; the final fallback is
ascending `team_id`, so the ranking is fully deterministic.

## 5. Versioning & Historical Protection

- `ScoringService::currentRuleSet()` lazily creates version 1 (legacy default)
  for any tournament without rules — backward compatible.
- `createVersion()` increments the version, deactivates the previous current
  row, and activates the new snapshot in a transaction.
- `activateVersion()` re-activates any historical snapshot.
- A submitted score snapshots the **current** rule at submission time; editing
  or creating rules later never rewrites the score's stored breakdown or total.
- The migration backfills version 1 for every existing tournament and fills the
  new breakdown columns for existing scores **without touching `points`** —
  historical totals are not rewritten.

## 6. Match-State Integration

- `GameMatch::acceptsScoreSubmission()` returns true only for `ready|live`.
- Score submission/adjustment is rejected for `pending`, `completed`,
  `disputed`, `bye` and `cancelled` matches.
- Adjustments are impossible after finalization (the match is no longer
  `ready|live`).
- Standings exclude `bye`, `cancelled` and `disputed` matches; a bye match
  cannot create fake competitive points.
- The Phase 05 state machine (pending/ready/live/completed/disputed/bye/
  cancelled) and the dispute/resolve flow are untouched.

## 7. Leaderboard / Standings

- `LeaderboardController` now calls `ScoringService::standings()` only.
- Standings aggregate `matches_played`, `kills`, `placement_points`,
  `kill_points`, `points`, `best_placement` per team from eligible matches,
  then sort by the current rule's tie-breaker chain plus `team_id`.
- The leaderboard view shows the computed rank and the breakdown, so results
  match the match pages and any future standings API.

## 8. Authorization & Policies

- New `TournamentPolicy::manageScoring()` (organizer or admin only).
- `ScoringRuleController::show/store/activate` authorize `manageScoring`; the
  organizer owns the tournament and the created rule is force-bound to that
  tournament (client `tournament_id`/`version`/`is_current` are ignored).
- `MatchController::addAdjustment()` authorizes `manage` (organizer/admin).
- Participants can never view or change rules, versions, adjustments,
  finalized scores, totals or rankings.
- The existing `submitScore` policy (captain of own team) is unchanged.

## 9. Database Design

New migration `2026_09_04_150000_add_scoring_engine.php`:

- `scoring_rules` — `id`, `tournament_id` (FK cascade), `version`,
  `name`, `kill_points` (default 1), `placement_points` (JSON),
  `tie_breakers` (JSON), `is_current` (default true), timestamps;
  unique `(tournament_id, version)`.
- `score_adjustments` — `id`, `score_id` (FK cascade), `type`
  (`bonus|penalty`), `points`, `reason`, timestamps.
- `scores` additions — `placement_points`, `kill_points`, `bonus_points`,
  `penalty_points` (default 0), `scoring_rules_id` (nullable FK,
  nullOnDelete), unique `(match_id, placement)`.
- Backfill — version 1 per existing tournament + breakdown fill for existing
  scores using the legacy map; `points` is left untouched.

## 10. Files Changed

New:

- `app/Models/ScoringRule.php`
- `app/Models/ScoreAdjustment.php`
- `app/Services/ScoringService.php`
- `app/Http/Controllers/ScoringRuleController.php`
- `resources/views/tournaments/scoring.blade.php`
- `tests/Feature/ScoringEngineTest.php`
- `tests/Feature/ScoringSecurityTest.php`
- `tests/Feature/ScoringMigrationTest.php`
- `database/migrations/2026_09_04_150000_add_scoring_engine.php`

Modified:

- `app/Models/Score.php` (computed columns cast; `scoringRule()`/`adjustments()`)
- `app/Models/Tournament.php` (`scoringRules()` relation)
- `app/Models/GameMatch.php` (`acceptsScoreSubmission()`)
- `app/Http/Controllers/MatchController.php` (engine-backed submission + adjustment)
- `app/Http/Controllers/LeaderboardController.php` (engine-backed standings)
- `app/Policies/TournamentPolicy.php` (`manageScoring()`)
- `routes/web.php` (+3 scoring rule routes, +1 adjustment route)
- `database/seeders/DatabaseSeeder.php` (establishes default rule sets)
- `resources/views/leaderboard/show.blade.php` (engine breakdown)
- `resources/views/matches/show.blade.php` (breakdown, adjustment UI, max placement)
- `resources/views/tournaments/show.blade.php` (Scoring Rules button)

## 11. Test Results

- Phase 05 baseline: **145 tests / 484 assertions** (all still passing).
- Phase 06 adds **55 tests / 144 assertions** across
  `ScoringEngineTest` (calculation, configurable rules, bonuses/penalties,
  versioning, tie-breakers, standings), `ScoringSecurityTest` (authorization,
  input validation, match-state, DB backstops) and `ScoringMigrationTest`
  (schema + unique constraints).
- Final: **200 tests / 628 assertions — 0 failures, 0 skipped, 0 risky**.

Coverage highlights: exact placement/kill/bonus/penalty/total cases; negative
kills rejected; non-integer kills rejected; placement 0 and 13 rejected;
invalid/duplicate/impossible placement rejected; arbitrary client
`points`/`placement_points` ignored; participant & other-organizer forbidden
from rule management; finalized score immutable; adjustment blocked after
finalization; historical version immutable after rule change; version
activation; future score uses current version; finalized match keeps historical
snapshot; tie-break determinism incl. identical-input team_id fallback; bye/
disputed/cancelled scores excluded; duplicate submission & repeated
finalization handled; DB unique backstops.

## 12. Lint / Routes / HTTP / Regression

- `php -l` — **13/13 changed PHP files** report "No syntax errors".
- `php artisan migrate:fresh --seed --force` — **OK**, 16 migrations applied
  (15 existing + the scoring engine), seed completes (default rule sets
  established for seeded tournaments).
- `php artisan route:list` — **47 routes** (43 baseline + 3 scoring rule + 1
  adjustment).
- HTTP smoke tests (live `php artisan serve`): `GET /` → 200,
  `GET /tournaments` → 200, `GET /tournaments/{slug}` → 200,
  `GET /tournaments/{slug}/leaderboard` → 200,
  `GET /organizer/tournaments/{slug}/scoring` as guest → 302 (login redirect).
- Regression: the full pre-existing Phase 01–05 suite (145 tests) passes
  unchanged alongside the new suite — **no regressions**.

## 13. Limitations

- SQLite is single-writer; the transaction + unique-constraint strategy used
  here is correct for SQLite and portable to MySQL/PostgreSQL, but the app does
  not use pessimistic `lockForUpdate()` (SQLite ignores row locks).
- Adjustments are per-score (not per-team-per-tournament); multi-match
  penalties can be expressed as separate adjustment rows.
- The backfill leaves legacy `points` untouched by design (historical totals
  are not rewritten); a legacy score's stored `points` may therefore not equal
  `placement_points + kill_points` if the old value differed — computed values
  going forward are always internally consistent.
- Standings include scores from matches still `ready|live` (same visibility as
  the legacy leaderboard, which had no match-status filter); only
  `bye/cancelled/disputed` are excluded.

## 14. Phase 01–05 Intact (Confirmation)

Explicitly confirmed intact and re-verified by the passing regression suite:
auth & role system, tournament lifecycle (draft→open→live→completed/cancelled),
registration/capacity/captain uniqueness, payment & admin verification,
team roster integrity, check-in/waitlist, no-show handling, bracket
generation (single/double elimination, byes), match state machine
(pending/ready/live/completed/disputed/bye/cancelled), dispute/resolve flow,
winner confirmation & bracket advancement, and leaderboard. No Phase 05 logic
was weakened; the only behavioural change is that scoring now flows through the
engine (identical default output) and the leaderboard gains deterministic
tie-breakers.

---

## 15. Complete File Contents

### FILE: app/Models/ScoringRule.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, versioned scoring rule-set snapshot for a tournament.
 *
 * Once created, a rule-set row is never edited in place — rule changes create
 * a NEW version row and mark it current. Scores reference the snapshot they
 * were computed with (`scores.scoring_rules_id`), so historical results are
 * reproducible even after the tournament's rules change.
 */
class ScoringRule extends Model
{
    use HasFactory;

    protected $table = 'scoring_rules';

    /**
     * The legacy default placement table (Phases 01–05). Placements 9–12
     * yielded the `?? 1` fallback in the old controller, reproduced here as
     * an explicit 1 so the default is self-describing.
     */
    public const DEFAULT_PLACEMENT_POINTS = [
        1 => 12, 2 => 9, 3 => 7, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1,
        9 => 1, 10 => 1, 11 => 1, 12 => 1,
    ];

    public const DEFAULT_KILL_POINTS = 1;

    /**
     * Default deterministic tie-breaker chain (highest first), matching the
     * legacy "order by total points desc" as its primary key and adding
     * deterministic fallbacks so identical inputs always rank identically.
     */
    public const DEFAULT_TIE_BREAKERS = ['points', 'placement_points', 'kill_points', 'kills', 'best_placement'];

    /**
     * Every metric the tie-breaker engine supports. Only metrics that exist
     * in the data model are listed.
     */
    public const TIE_BREAKER_OPTIONS = [
        'points' => 'Total points',
        'placement_points' => 'Placement points',
        'kill_points' => 'Kill points',
        'kills' => 'Total kills',
        'best_placement' => 'Best placement',
    ];

    public const MAX_PLACEMENT = 12;

    /**
     * Rule sets are created exclusively through ScoringService. All fields
     * are server-controlled; nothing is mass-assignable.
     */
    protected $fillable = [];

    protected $casts = [
        'version' => 'integer',
        'kill_points' => 'integer',
        'placement_points' => 'array',
        'tie_breakers' => 'array',
        'is_current' => 'boolean',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function scores()
    {
        return $this->hasMany(Score::class, 'scoring_rules_id');
    }

    /**
     * Placement points for a given placement. Falls back to 1 point for any
     * placement absent from the map — preserving the legacy behaviour.
     */
    public function placementPointsFor(int $placement): int
    {
        $map = $this->placement_points ?? [];

        if (array_key_exists($placement, $map)) {
            return (int) $map[$placement];
        }

        return 1;
    }

    /**
     * The ordered tie-breaker chain (always at least the primary key).
     */
    public function tieBreakers(): array
    {
        $chain = $this->tie_breakers ?? [];

        if (! is_array($chain) || $chain === []) {
            return self::DEFAULT_TIE_BREAKERS;
        }

        // Filter out any unknown metric keys defensively.
        $allowed = array_keys(self::TIE_BREAKER_OPTIONS);
        $chain = array_values(array_unique(array_filter(
            array_map('strval', $chain),
            fn ($key) => in_array($key, $allowed, true)
        )));

        if (! in_array('points', $chain, true)) {
            array_unshift($chain, 'points');
        }

        return $chain;
    }

    public function isCurrent(): bool
    {
        return (bool) $this->is_current;
    }

    public function label(): string
    {
        return $this->name ?: ('Scoring rules v' . $this->version);
    }
}
```

### FILE: app/Models/ScoreAdjustment.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An auditable bonus/penalty adjustment applied to a single score.
 *
 * Each adjustment carries a type (bonus|penalty), a point value and a
 * mandatory reason, so any modification of a competitive result stays
 * reproducible and explainable. Adjustments are only allowed while the
 * match is ready/live; once the match is finalized they are frozen.
 */
class ScoreAdjustment extends Model
{
    use HasFactory;

    protected $table = 'score_adjustments';

    public const TYPE_BONUS = 'bonus';
    public const TYPE_PENALTY = 'penalty';

    public const TYPES = [
        self::TYPE_BONUS,
        self::TYPE_PENALTY,
    ];

    /**
     * All fields are server-controlled. Created exclusively through
     * ScoringService::addAdjustment().
     */
    protected $fillable = [];

    protected $casts = [
        'points' => 'integer',
    ];

    public function score()
    {
        return $this->belongsTo(Score::class);
    }

    public function isBonus(): bool
    {
        return $this->type === self::TYPE_BONUS;
    }

    public function isPenalty(): bool
    {
        return $this->type === self::TYPE_PENALTY;
    }
}
```

### FILE: app/Models/Score.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single team's result within a match.
 *
 * `kills` and `placement` are the raw inputs submitted by an authorized
 * participant. Everything else — `points` (total), `placement_points`,
 * `kill_points`, `bonus_points`, `penalty_points` and `scoring_rules_id` —
 * is computed server-side by ScoringService and can never be supplied by a
 * client.
 */
class Score extends Model
{
    use HasFactory;

    /**
     * Only raw inputs and the proof screenshot are mass-assignable. The
     * computed totals and the rule-snapshot reference are server-controlled.
     */
    protected $fillable = [
        'kills',
        'placement',
        'screenshot_path',
    ];

    protected $casts = [
        'kills' => 'integer',
        'placement' => 'integer',
        'points' => 'integer',
        'placement_points' => 'integer',
        'kill_points' => 'integer',
        'bonus_points' => 'integer',
        'penalty_points' => 'integer',
    ];

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The scoring-rule snapshot this score was computed with.
     */
    public function scoringRule()
    {
        return $this->belongsTo(ScoringRule::class, 'scoring_rules_id');
    }

    public function adjustments()
    {
        return $this->hasMany(ScoreAdjustment::class);
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

### FILE: app/Http/Controllers/ScoringRuleController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\ScoringRule;
use App\Models\Tournament;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Organizer/admin scoring-rule configuration (Phase 06).
 *
 * Only the owning organizer or an admin may view or change a tournament's
 * scoring rules. Rule edits always create a new immutable version; they never
 * rewrite historical scores.
 */
class ScoringRuleController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {
    }

    public function show(Tournament $tournament)
    {
        $this->authorize('manageScoring', $tournament);

        $rules = $tournament->scoringRules()->orderByDesc('version')->get();
        $current = $this->scoring->currentRuleSet($tournament);

        return view('tournaments.scoring', compact('tournament', 'rules', 'current'));
    }

    public function store(Request $request, Tournament $tournament)
    {
        $this->authorize('manageScoring', $tournament);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'kill_points' => 'required|integer|min:0|max:1000',
            'placement_points' => 'required|array',
            'placement_points.*' => 'required|integer|min:0|max:1000',
            'tie_breakers' => 'nullable|array',
            'tie_breakers.*' => 'nullable|string|in:points,placement_points,kill_points,kills,best_placement',
        ]);

        try {
            $rule = $this->scoring->createVersion($tournament, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Scoring rules version {$rule->version} created and activated.");
    }

    public function activate(Request $request, Tournament $tournament, ScoringRule $rule)
    {
        $this->authorize('manageScoring', $tournament);

        try {
            $this->scoring->activateVersion($tournament, $rule);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Scoring rules version {$rule->version} is now active.");
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
        ]);

        return view('matches.show', compact('tournament', 'match'));
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

### FILE: app/Http/Controllers/LeaderboardController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\ScoringService;

class LeaderboardController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {
    }

    public function show(Tournament $tournament)
    {
        // Standings are computed exclusively by the scoring engine so the
        // leaderboard, match results and any future standings API all agree.
        $leaderboard = $this->scoring->standings($tournament);

        $tieBreakers = $this->scoring->currentRuleSet($tournament)->tieBreakers();

        return view('leaderboard.show', compact('tournament', 'leaderboard', 'tieBreakers'));
    }
}
```

### FILE: app/Policies/TournamentPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    /**
     * Only organizers and admins may create tournaments.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isOrganizer();
    }

    /**
     * Ownership gate shared by every lifecycle action: only the owning
     * organizer or an admin may act on a tournament.
     */
    public function update(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin() || $tournament->organizer_id === $user->id;
    }

    public function publish(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function closeRegistration(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function start(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function complete(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function cancel(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    /**
     * Managing scoring rules is a privileged tournament operation: only the
     * owning organizer or an admin. Participants can never change rules.
     */
    public function manageScoring(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
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

### FILE: routes/web.php
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
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

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/reject', [AdminController::class, 'rejectPayment'])->name('payments.reject');
    });
});
```

### FILE: database/seeders/DatabaseSeeder.php
```php
<?php

namespace Database\Seeders;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin — role set explicitly (not mass-assignable)
        $admin = new User();
        $admin->name = 'FF Arena Admin';
        $admin->username = 'admin';
        $admin->email = 'admin@ffarena.test';
        $admin->password = Hash::make('password');
        $admin->role = 'admin';
        $admin->save();

        // Organizer
        $organizer = new User();
        $organizer->name = 'Rafsan Esports';
        $organizer->username = 'rafsan';
        $organizer->email = 'organizer@ffarena.test';
        $organizer->password = Hash::make('password');
        $organizer->role = 'organizer';
        $organizer->phone = '01700000000';
        $organizer->save();

        // Demo tournament (open for registration)
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Squad Showdown 32 Teams';
        $tournament->slug = 'squad-showdown-32-teams';
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->rules = "1. No hacks/cheats — instant ban.\n2. Screenshot of result is mandatory.\n3. Room entry within 5 minutes of schedule.";
        $tournament->starts_at = now()->addDays(2);
        $tournament->check_in_starts_at = now()->addDays(2)->subHours(3);
        $tournament->check_in_ends_at = now()->addDays(2)->subHour();
        $tournament->format = 'single_elim';
        $tournament->status = 'open';
        $tournament->save();

        $teamNames = ['BD Titans', 'Dhaka Wolves', 'RapidFire', 'Night Owls', 'Sylhet Strikers', 'Cox Cobras', 'Rajshahi Reapers', 'Chittagong Kings'];
        $seededTeams = [];

        foreach ($teamNames as $i => $name) {
            $team = new Team();
            $team->tournament_id = $tournament->id;
            $team->captain_id = null;
            $team->name = $name;
            $team->captain_name = 'Captain ' . ($i + 1);
            $team->phone = '017' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT);
            $team->game_uid = 'UID' . (900000000 + $i);
            $team->status = 'confirmed';
            $team->seed = $i + 1;
            $team->save();
            $seededTeams[] = $team;
        }

        // Second tournament (finished) with a bracket + results
        $done = new Tournament();
        $done->organizer_id = $organizer->id;
        $done->name = 'Duo Battle Royale';
        $done->slug = 'duo-battle-royale-' . rand(1000, 9999);
        $done->game_mode = 'duo';
        $done->map = 'Purgatory';
        $done->entry_fee = 50;
        $done->prize_pool = 2000;
        $done->team_slots = 8;
        $done->team_size = 2;
        $done->rules = 'Standard duo rules.';
        $done->starts_at = now()->subDays(1);
        $done->format = 'single_elim';
        $done->status = 'finished';
        $done->save();

        $dTeams = [];
        for ($i = 1; $i <= 8; $i++) {
            $t = new Team();
            $t->tournament_id = $done->id;
            $t->captain_id = null;
            $t->name = 'Duo Team ' . $i;
            $t->captain_name = 'Cap ' . $i;
            $t->phone = '017' . str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT);
            $t->game_uid = 'UID' . (800000000 + $i);
            $t->status = 'confirmed';
            $t->seed = $i;
            $t->save();
            $dTeams[] = $t;
        }

        $this->makeMatch($done, 1, 1, $dTeams[0], $dTeams[1], $dTeams[0]);
        $this->makeMatch($done, 1, 2, $dTeams[2], $dTeams[3], $dTeams[2]);
        $this->makeMatch($done, 1, 3, $dTeams[4], $dTeams[5], $dTeams[5]);
        $this->makeMatch($done, 1, 4, $dTeams[6], $dTeams[7], $dTeams[6]);

        $this->makeMatch($done, 2, 1, $dTeams[0], $dTeams[2], $dTeams[0]);
        $this->makeMatch($done, 2, 2, $dTeams[5], $dTeams[6], $dTeams[5]);

        $this->makeMatch($done, 3, 1, $dTeams[0], $dTeams[5], $dTeams[0]);

        // Phase 06 — establish a default scoring rule set for each demo
        // tournament (lazy creation in ScoringService also covers any
        // tournament without rules).
        app(ScoringService::class)->currentRuleSet($tournament);
        app(ScoringService::class)->currentRuleSet($done);
    }

    private function makeMatch(Tournament $tournament, int $round, int $matchNo, Team $t1, Team $t2, Team $winner): void
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = $round;
        $match->match_no = $matchNo;
        $match->team1_id = $t1->id;
        $match->team2_id = $t2->id;
        $match->winner_team_id = $winner->id;
        $match->status = 'completed';
        $match->save();
    }
}
```

### FILE: database/migrations/2026_09_04_150000_add_scoring_engine.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 06 — Free Fire scoring engine.
     *
     * scoring_rules     : immutable, versioned rule-set snapshots per
     *                     tournament (placement points, kill points,
     *                     tie-breaker order).
     * score_adjustments : auditable bonus/penalty records tied to a score.
     * scores            : gains computed breakdown columns + a reference to
     *                     the rule snapshot that produced them.
     *
     * Historical integrity: every score stores its computed placement/kill/
     * bonus/penalty points and the scoring_rules_id snapshot it was computed
     * with, so later rule edits never rewrite historical results.
     */
    public function up(): void
    {
        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('name')->nullable();
            $table->unsignedInteger('kill_points')->default(1);
            $table->json('placement_points');
            $table->json('tie_breakers');
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['tournament_id', 'version'], 'scoring_rules_tournament_version_unique');
            $table->index('tournament_id', 'scoring_rules_tournament_index');
        });

        Schema::create('score_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained('scores')->cascadeOnDelete();
            $table->string('type'); // bonus | penalty
            $table->unsignedInteger('points');
            $table->string('reason');
            $table->timestamps();

            $table->index('score_id', 'score_adjustments_score_index');
        });

        Schema::table('scores', function (Blueprint $table) {
            $table->unsignedInteger('placement_points')->default(0);
            $table->unsignedInteger('kill_points')->default(0);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->unsignedInteger('penalty_points')->default(0);
            $table->foreignId('scoring_rules_id')->nullable()->constrained('scoring_rules')->nullOnDelete();

            // A Free Fire placement is unique within a single match: two
            // teams can never finish in the same position.
            $table->unique(['match_id', 'placement'], 'scores_match_placement_unique');
        });

        $this->backfillLegacyData();
    }

    /**
     * Establish a default scoring version for every existing tournament and
     * backfill the computed breakdown for any existing score rows, so
     * historical results remain reproducible after the upgrade.
     *
     * The legacy behaviour (MatchController, Phases 01–05) was:
     *   placement points = [1=>12, 2=>9, 3=>7, 4=>5, 5=>4, 6=>3, 7=>2, 8=>1],
     *   any other placement => 1 point,
     *   kill points = 1 per kill,
     *   total = kills + placement points.
     */
    protected function backfillLegacyData(): void
    {
        $legacyPlacementPoints = [1 => 12, 2 => 9, 3 => 7, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 1];
        $defaultTieBreakers = ['points', 'placement_points', 'kill_points', 'kills', 'best_placement'];

        $tournaments = DB::table('tournaments')->orderBy('id')->get();

        foreach ($tournaments as $tournament) {
            $ruleId = DB::table('scoring_rules')->insertGetId([
                'tournament_id' => $tournament->id,
                'version' => 1,
                'name' => 'Default Free Fire Rules',
                'kill_points' => 1,
                'placement_points' => json_encode($legacyPlacementPoints),
                'tie_breakers' => json_encode($defaultTieBreakers),
                'is_current' => true,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            $scores = DB::table('scores')
                ->join('matches', 'matches.id', '=', 'scores.match_id')
                ->where('matches.tournament_id', $tournament->id)
                ->select('scores.*')
                ->get();

            foreach ($scores as $score) {
                $placementPoints = $legacyPlacementPoints[$score->placement] ?? 1;
                $killPoints = (int) $score->kills * 1;

                DB::table('scores')->where('id', $score->id)->update([
                    'placement_points' => $placementPoints,
                    'kill_points' => $killPoints,
                    'bonus_points' => 0,
                    'penalty_points' => 0,
                    'scoring_rules_id' => $ruleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropUnique('scores_match_placement_unique');
            $table->dropConstrainedForeignId('scoring_rules_id');
            $table->dropColumn(['placement_points', 'kill_points', 'bonus_points', 'penalty_points']);
        });

        Schema::dropIfExists('score_adjustments');
        Schema::dropIfExists('scoring_rules');
    }
};
```

### FILE: resources/views/tournaments/scoring.blade.php
```blade
@extends('layouts.app')
@section('title', 'Scoring Rules — ' . $tournament->name)
@section('content')
    @php
        $placementMap = $current->placement_points ?? [];
        $currentChain = $current->tieBreakers();
        // Pad the chain to 5 selectable slots for the form.
        $slots = array_merge(array_values($currentChain), array_fill(0, max(0, 5 - count($currentChain)), null));
        $slots = array_slice($slots, 0, 5);
    @endphp

    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back to tournament</a>
        <h1 style="margin-top:6px">🎯 Scoring Rules</h1>
        <p class="muted">{{ $tournament->name }}</p>
    </div>

    <div class="card">
        <h3>Current Rules
            <span class="pill live">v{{ $current->version }}</span>
            @if($current->isCurrent())
                <span class="pill confirmed">ACTIVE</span>
            @endif
        </h3>
        <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:14px">
            <div>Kill points <br><strong class="tag">{{ $current->kill_points }} / kill</strong></div>
            <div>Rule set name <br><strong>{{ $current->label() }}</strong></div>
        </div>

        <div style="display:flex; gap:24px; flex-wrap:wrap">
            <div>
                <div class="muted" style="font-size:12px; font-weight:700; margin-bottom:6px">PLACEMENT POINTS</div>
                <table style="max-width:320px">
                    <tr><th>Placement</th><th>Points</th></tr>
                    @for($p = 1; $p <= 12; $p++)
                        <tr>
                            <td>#{{ $p }}</td>
                            <td><strong>{{ $current->placementPointsFor($p) }}</strong></td>
                        </tr>
                    @endfor
                </table>
            </div>
            <div>
                <div class="muted" style="font-size:12px; font-weight:700; margin-bottom:6px">TIE-BREAKER ORDER</div>
                <ol style="padding-left:18px; color:var(--txt)">
                    @foreach($currentChain as $key)
                        <li style="margin-bottom:4px">{{ \App\Models\ScoringRule::TIE_BREAKER_OPTIONS[$key] ?? $key }}</li>
                    @endforeach
                </ol>
                <p class="muted" style="font-size:12px; margin-top:10px; max-width:340px">
                    Historical scores keep the exact rule version they were
                    computed with — changing rules only affects <em>future</em> matches.
                </p>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>🆕 Create a New Version</h3>
        <p class="muted" style="font-size:13px; margin-bottom:6px">
            Saving creates an immutable new version and activates it. Existing results are never rewritten.
        </p>
        <form method="POST" action="{{ route('tournaments.scoring.store', $tournament) }}">
            @csrf
            <div class="grid cols-2">
                <div>
                    <label>Rule set name (optional)</label>
                    <input type="text" name="name" value="{{ $current->name }}" placeholder="e.g. Finals rules">
                </div>
                <div>
                    <label>Points per kill</label>
                    <input type="number" name="kill_points" value="{{ $current->kill_points }}" min="0" max="1000" required>
                </div>
            </div>

            <label>Placement points (1st → 12th)</label>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap:8px">
                @for($p = 1; $p <= 12; $p++)
                    <div>
                        <span class="muted" style="font-size:11px">#{{ $p }}</span>
                        <input type="number" name="placement_points[{{ $p }}]"
                               value="{{ $current->placementPointsFor($p) }}" min="0" max="1000" required>
                    </div>
                @endfor
            </div>

            <label>Tie-breaker order (first → last)</label>
            <p class="muted" style="font-size:12px; margin-bottom:6px">
                Total points is always the primary sort. Leave a slot as "—" to stop the chain early.
            </p>
            @for($i = 0; $i < 5; $i++)
                <select name="tie_breakers[]" style="margin-bottom:6px">
                    <option value="">— none —</option>
                    @foreach(\App\Models\ScoringRule::TIE_BREAKER_OPTIONS as $key => $label)
                        <option value="{{ $key }}" @selected(($slots[$i] ?? null) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            @endfor

            <button class="btn btn-primary" style="margin-top:16px">Create & Activate Version</button>
        </form>
    </div>

    <div class="card">
        <h3>📚 Version History</h3>
        @if($rules->isEmpty())
            <p class="muted">No rule versions yet.</p>
        @else
            <table>
                <tr><th>Version</th><th>Name</th><th>Kill Pts</th><th>Created</th><th>Status</th><th></th></tr>
                @foreach($rules as $rule)
                    <tr>
                        <td><strong>v{{ $rule->version }}</strong></td>
                        <td>{{ $rule->label() }}</td>
                        <td>{{ $rule->kill_points }}</td>
                        <td class="muted">{{ $rule->created_at->format('d M Y, h:i A') }}</td>
                        <td>
                            @if($rule->isCurrent())
                                <span class="pill live">ACTIVE</span>
                            @else
                                <span class="pill cancelled">HISTORICAL</span>
                            @endif
                        </td>
                        <td>
                            @if(!$rule->isCurrent())
                                <form method="POST" action="{{ route('tournaments.scoring.activate', [$tournament, $rule]) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-cyan">Activate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
```

### FILE: resources/views/leaderboard/show.blade.php
```blade
@extends('layouts.app')
@section('title', 'Leaderboard — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back</a>
        <h1 style="margin-top:6px">🏅 Leaderboard</h1>
        <p class="muted">{{ $tournament->name }}</p>
    </div>

    <div class="card">
        @if($leaderboard->isEmpty())
            <p class="muted">No results yet.</p>
        @else
            <table>
                <tr>
                    <th>Rank</th><th>Team</th><th>Matches</th><th>Kills</th>
                    <th>Place Pts</th><th>Kill Pts</th><th>Total</th>
                </tr>
                @foreach($leaderboard as $row)
                    <tr>
                        <td><strong>#{{ $row->rank }}</strong></td>
                        <td><strong>{{ $row->team->name }}</strong></td>
                        <td>{{ $row->matches_played }}</td>
                        <td>{{ $row->kills }}</td>
                        <td>{{ $row->placement_points }}</td>
                        <td>{{ $row->kill_points }}</td>
                        <td><strong class="tag">{{ $row->points }}</strong></td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:12px; margin-top:10px">
                Deterministic ordering — identical results always rank identically.
            </p>
        @endif
    </div>
@endsection
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
@endsection
```

### FILE: resources/views/tournaments/show.blade.php
```blade
@extends('layouts.app')
@section('title', $tournament->name . ' — FF Arena')
@section('content')
    <div style="padding: 30px 0 10px">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
            <div>
                <h1 style="margin:0">{{ $tournament->name }}</h1>
                <div class="muted" style="margin-top:6px">
                    by {{ $tournament->organizer->name ?? 'Organizer' }} ·
                    {{ strtoupper($tournament->game_mode) }} · {{ $tournament->map }} ·
                    starts {{ optional($tournament->starts_at)->format('d M Y, h:i A') }}
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                @if($tournament->hasCheckIn())
                    @if($tournament->checkInIsOpen())
                        <span class="pill checked">CHECK-IN OPEN</span>
                    @elseif($tournament->checkInHasClosed())
                        <span class="pill no_show">CHECK-IN CLOSED</span>
                    @else
                        <span class="pill pending">CHECK-IN NOT OPEN</span>
                    @endif
                @endif
                <span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span>
            </div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>Prize & Entry</h3>
            <div style="display:flex; gap:24px; font-size:16px">
                <div>Entry fee <br><strong class="tag">৳{{ number_format($tournament->entry_fee) }}</strong></div>
                <div>Prize pool <br><strong class="tag">৳{{ number_format($tournament->prize_pool) }}</strong></div>
                <div>Slots left <br><strong>{{ $tournament->slotsLeft() }}/{{ $tournament->team_slots }}</strong></div>
            </div>
            <div class="muted" style="margin-top:12px; font-size:13px">Players per team: {{ $tournament->team_size }}</div>
            @if($tournament->hasCheckIn())
                <div class="muted" style="margin-top:8px; font-size:13px">
                    Check-in: {{ $tournament->check_in_starts_at->format('d M, h:i A') }} —
                    {{ $tournament->check_in_ends_at->format('d M, h:i A') }}
                </div>
            @endif
        </div>
        <div class="card">
            <h3>Rules</h3>
            <pre style="white-space:pre-wrap; font-family:inherit; font-size:14px; color:var(--muted)">{{ $tournament->rules ?: 'No rules set.' }}</pre>
        </div>
    </div>

    @auth
        @if((auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id) || auth()->user()->isAdmin())
            <div class="card">
                <h3>🎛 Organizer Controls</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap">
                    @if($tournament->status === 'draft')
                        <form method="POST" action="{{ route('tournaments.publish', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm">Publish (open registration)</button>
                        </form>
                    @endif
                    @if($tournament->status === 'open')
                        <form method="POST" action="{{ route('tournaments.close', $tournament) }}">@csrf
                            <button class="btn btn-sm">Close registration</button>
                        </form>
                    @endif
                    @if(in_array($tournament->status, ['closed','open'], true))
                        <form method="POST" action="{{ route('tournaments.bracket', $tournament) }}">@csrf
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket</button>
                        </form>
                    @endif
                    @if($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
                        </form>
                    @endif
                    @if($tournament->hasCheckIn() && $tournament->checkInHasClosed())
                        <form method="POST" action="{{ route('tournaments.noshows', $tournament) }}">@csrf
                            <button class="btn btn-sm" onclick="return confirm('Mark unchecked-in teams as no-show and promote from the waitlist?')">🚫 Mark No-shows</button>
                        </form>
                    @endif
                    @if($tournament->acceptsRegistration() && $waitlist && $waitlist->isNotEmpty())
                        <form method="POST" action="{{ route('tournaments.waitlist.promote', $tournament) }}">@csrf
                            <button class="btn btn-sm btn-cyan">⬆ Promote Next Waitlisted</button>
                        </form>
                    @endif
                    <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-sm">Edit</a>
                    <a href="{{ route('tournaments.scoring.show', $tournament) }}" class="btn btn-sm btn-cyan">Scoring Rules</a>
                    <a href="{{ route('leaderboard.show', $tournament) }}" class="btn btn-sm btn-cyan">Leaderboard</a>
                    @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                        <form method="POST" action="{{ route('tournaments.cancel', $tournament) }}" style="display:inline">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Cancel this tournament?')">Cancel</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @if($myTeam && !$myTeam->isWithdrawn())
            <div class="card">
                <h3>🎽 Your Team</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <span class="pill {{ $myTeam->status }}">{{ strtoupper($myTeam->status) }}</span>
                        @if($myTeam->isWaitlisted())
                            <span class="pill waitlisted">WAITLIST #{{ $myTeam->waitlistPosition() }}</span>
                        @elseif($myTeam->isCheckedIn())
                            <span class="pill checked">CHECKED IN ✓</span>
                        @endif
                        <div class="muted" style="font-size:13px; margin-top:4px">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
                        @if($myTeam->isConfirmed() && $tournament->hasCheckIn() && !$myTeam->isCheckedIn() && $tournament->checkInIsOpen())
                            <form method="POST" action="{{ route('teams.checkin', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-green btn-sm">✅ Check In</button>
                            </form>
                        @endif
                        <a href="{{ route('teams.show', [$tournament, $myTeam]) }}" class="btn btn-sm btn-cyan">Manage Team</a>
                        @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                            <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endauth

    @if($tournament->acceptsRegistration() && !$tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
        </div>
    @elseif($tournament->acceptsRegistration() && $tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>⏳ Tournament full</h3>
            <p class="muted">All slots are taken, but you can still join the waitlist.</p>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Join Waitlist</a>
        </div>
    @endif

    @if($tournament->status === 'live' || $tournament->status === 'finished')
        <div class="card">
            <h2>🏆 Bracket
                <span class="muted" style="font-size:13px; font-weight:400">
                    — {{ $tournament->isDoubleElim() ? 'Double Elimination' : 'Single Elimination' }}
                    @if($tournament->bracket_size) ({{ $tournament->bracket_size }}-team bracket) @endif
                </span>
            </h2>
            @if($tournament->matches->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @elseif($tournament->isDoubleElim())
                @php
                    $sides = [
                        'winners' => ['label' => 'Winners Bracket', 'brackets' => ['winners']],
                        'losers' => ['label' => 'Losers Bracket', 'brackets' => ['losers']],
                        'grand_final' => ['label' => 'Grand Final', 'brackets' => ['grand_final']],
                    ];
                @endphp
                @foreach($sides as $side)
                    @php $sideMatches = $tournament->matches->where('bracket', $side['brackets'][0]); @endphp
                    @if($sideMatches->isNotEmpty())
                        <div style="margin-top:18px">
                            <div class="muted" style="font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.5px">{{ $side['label'] }}</div>
                            <div class="bracket-col">
                                @foreach($sideMatches->groupBy('round')->sortKeys() as $round => $roundMatches)
                                    <div class="bracket-round">
                                        <div class="muted" style="font-size:12px; font-weight:700">
                                            {{ $roundMatches->first()->roundLabel() }}
                                        </div>
                                        @foreach($roundMatches as $m)
                                            @include('matches._bracket_card', ['match' => $m])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @else
                @php $rounds = $tournament->matches->groupBy('round')->sortKeys(); $lastRound = $rounds->keys()->last(); @endphp
                <div class="bracket-col">
                    @foreach($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size:12px; font-weight:700">
                                {{ $round == $lastRound ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach($matches as $m)
                                @include('matches._bracket_card', ['match' => $m])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <h3>👥 Registered Teams</h3>
        @if($tournament->confirmedTeams->isEmpty())
            <p class="muted">No confirmed teams yet.</p>
        @else
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th>@if($tournament->hasCheckIn())<th>Check-in</th>@endif</tr>
                @foreach($tournament->confirmedTeams as $team)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $team->name }}</strong></td>
                        <td class="muted">{{ $team->captain_name }}</td>
                        <td><span class="pill confirmed">CONFIRMED</span></td>
                        @if($tournament->hasCheckIn())
                            <td>
                                @if($team->isCheckedIn())
                                    <span class="pill checked">CHECKED IN</span>
                                @else
                                    <span class="pill pending">NOT CHECKED IN</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @if($waitlist && $waitlist->isNotEmpty())
        <div class="card">
            <h3>⏳ Waitlist</h3>
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th></tr>
                @foreach($waitlist as $wt)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $wt->name }}</strong></td>
                        <td class="muted">{{ $wt->captain_name }}</td>
                        <td><span class="pill waitlisted">WAITLISTED</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
```

### FILE: tests/Feature/ScoringEngineTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — scoring engine tests: calculation, configurable rules,
 * versioning/historical integrity, tie-breakers and leaderboard/standings.
 */
class ScoringEngineTest extends TestCase
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
        $t->name = $o['name'] ?? 'Scoring Tournament';
        $t->slug = $o['slug'] ?? ('scoring-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
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

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'live'): GameMatch
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

    protected function scoring(): ScoringService
    {
        return app(ScoringService::class);
    }

    // ------------------------------------------------------------------
    // Placement + kill scoring (legacy defaults preserved)
    // ------------------------------------------------------------------

    public function test_match_page_renders_with_scoring_breakdown(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->actingAs($org)
            ->get(route('matches.show', [$tournament, $match]))
            ->assertOk()
            ->assertSee('Submitted Scores')
            ->assertSee('Place Pts');
    }

    public function test_placement_one_default_is_twelve_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->assertSame(6, (int) $score->kills);
        $this->assertSame(12, (int) $score->placement_points);
        $this->assertSame(6, (int) $score->kill_points);
        $this->assertSame(18, (int) $score->points);
    }

    public function test_placement_two_default_is_nine_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 2);

        $this->assertSame(9, (int) $score->placement_points);
        $this->assertSame(0, (int) $score->kill_points);
        $this->assertSame(9, (int) $score->points);
    }

    public function test_placement_three_default_is_seven_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 3);

        $this->assertSame(7, (int) $score->placement_points);
    }

    public function test_placement_eight_default_is_one_point(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 8);

        $this->assertSame(1, (int) $score->placement_points);
    }

    public function test_zero_kills_are_valid(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 1);

        $this->assertSame(0, (int) $score->kills);
        $this->assertSame(12, (int) $score->points);
    }

    public function test_multiple_kills_add_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 10, 3);

        $this->assertSame(10, (int) $score->kill_points);
        $this->assertSame(17, (int) $score->points); // 10 + 7
    }

    // ------------------------------------------------------------------
    // Configurable rules
    // ------------------------------------------------------------------

    public function test_kill_points_are_configurable(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->createVersion($tournament, [
            'name' => 'Double kill points',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 5, 1);

        $this->assertSame(10, (int) $score->kill_points); // 5 * 2
        $this->assertSame(22, (int) $score->points);       // 10 + 12
    }

    public function test_placement_points_are_configurable(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $placement = ScoringRule::DEFAULT_PLACEMENT_POINTS;
        $placement[1] = 20;

        $this->scoring()->createVersion($tournament, [
            'name' => 'Big first place',
            'kill_points' => 1,
            'placement_points' => $placement,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 1);

        $this->assertSame(20, (int) $score->placement_points);
        $this->assertSame(20, (int) $score->points);
    }

    // ------------------------------------------------------------------
    // Bonuses & penalties
    // ------------------------------------------------------------------

    public function test_bonus_increases_total(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);
        $this->assertSame(18, (int) $score->points);

        $this->scoring()->addAdjustment($score, 'bonus', 5, 'Booyah bonus');

        $score->refresh();
        $this->assertSame(5, (int) $score->bonus_points);
        $this->assertSame(23, (int) $score->points);
    }

    public function test_penalty_decreases_total(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->scoring()->addAdjustment($score, 'penalty', 3, 'Rules violation');

        $score->refresh();
        $this->assertSame(3, (int) $score->penalty_points);
        $this->assertSame(15, (int) $score->points);
    }

    public function test_total_is_never_negative(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        // Placement 12 worth 0, kills worth 0.
        $placement = array_fill(1, 12, 0);
        $this->scoring()->createVersion($tournament, [
            'name' => 'Zero points',
            'kill_points' => 0,
            'placement_points' => $placement,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 12);
        $this->assertSame(0, (int) $score->points);

        $this->scoring()->addAdjustment($score, 'penalty', 10, 'Penalty exceeding total');

        $score->refresh();
        $this->assertSame(0, (int) $score->points);
        $this->assertSame(10, (int) $score->penalty_points);
    }

    public function test_duplicate_placement_within_match_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 3, 1);

        $this->expectException(\DomainException::class);
        $this->scoring()->submitScore($match, $teamB, 4, 1);
    }

    // ------------------------------------------------------------------
    // Server-authoritative calculation
    // ------------------------------------------------------------------

    public function test_client_supplied_total_is_ignored(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id,
            'kills' => 6,
            'placement' => 1,
            'points' => 9999,          // ignored
            'placement_points' => 999, // ignored
        ])->assertRedirect();

        $score = Score::where('match_id', $match->id)->where('team_id', $teamA->id)->firstOrFail();
        $this->assertSame(18, (int) $score->points);
        $this->assertSame(12, (int) $score->placement_points);
    }

    // ------------------------------------------------------------------
    // Versioning / historical integrity
    // ------------------------------------------------------------------

    public function test_default_rule_set_is_created_lazily(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $rule = $this->scoring()->currentRuleSet($tournament);

        $this->assertSame(1, (int) $rule->version);
        $this->assertTrue($rule->isCurrent());
        $this->assertSame(12, $rule->placementPointsFor(1));
        $this->assertSame(1, (int) $rule->kill_points);
    }

    public function test_new_version_becomes_current_and_old_keeps_history(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $v1 = $this->scoring()->currentRuleSet($tournament);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $this->assertSame(2, (int) $v2->version);
        $this->assertTrue($v2->fresh()->isCurrent());
        $this->assertFalse($v1->fresh()->isCurrent());
    }

    public function test_future_score_uses_current_version(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 5, 1);

        $this->assertSame($v2->id, $score->scoring_rules_id);
        $this->assertSame(10, (int) $score->kill_points);
    }

    public function test_historical_score_keeps_its_snapshot_after_rules_change(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $v1 = $this->scoring()->currentRuleSet($tournament);
        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);
        $this->assertSame(18, (int) $score->points);

        // Change the rules for future matches.
        $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 5,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        // Historical score is untouched and still references v1.
        $score->refresh();
        $this->assertSame($v1->id, $score->scoring_rules_id);
        $this->assertSame(18, (int) $score->points);
        $this->assertSame(6, (int) $score->kill_points);
    }

    public function test_activate_previous_version(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $v1 = $this->scoring()->currentRuleSet($tournament);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2', 'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
        $v3 = $this->scoring()->createVersion($tournament, [
            'name' => 'v3', 'kill_points' => 3,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $this->scoring()->activateVersion($tournament, $v1);

        $this->assertTrue($v1->fresh()->isCurrent());
        $this->assertFalse($v2->fresh()->isCurrent());
        $this->assertFalse($v3->fresh()->isCurrent());
    }

    public function test_versions_increment_sequentially(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->assertSame(1, (int) $this->scoring()->currentRuleSet($tournament)->version);
        $this->assertSame(2, (int) $this->scoring()->createVersion($tournament, [
            'name' => 'a', 'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ])->version);
        $this->assertSame(3, (int) $this->scoring()->createVersion($tournament, [
            'name' => 'b', 'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ])->version);
    }

    // ------------------------------------------------------------------
    // Leaderboard / standings
    // ------------------------------------------------------------------

    public function test_leaderboard_aggregates_multiple_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamB);
        $m2 = $this->makeMatch($tournament, $teamA, $teamC);
        $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 3, 2); // 9 + 3 = 12
        $this->scoring()->submitScore($m2, $teamA, 5, 1); // 12 + 5 = 17

        $rows = $this->scoring()->standings($tournament);

        $rowA = $rows->firstWhere('team_id', $teamA->id);
        $this->assertNotNull($rowA);
        $this->assertSame(2, $rowA->matches_played);
        $this->assertSame(8, $rowA->kills);
        $this->assertSame(29, $rowA->points);
        $this->assertSame(1, $rowA->rank);
    }

    public function test_leaderboard_breaks_ties_by_configured_chain(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        // Kill points = 0, so two teams with the same placement tie on points,
        // placement points and kill points — only total kills differ.
        $this->scoring()->createVersion($tournament, [
            'name' => 'No kill points',
            'kill_points' => 0,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ['points', 'placement_points', 'kill_points', 'kills', 'best_placement'],
        ]);

        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamC);
        $m2 = $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 5, 1); // 12 pts
        $this->scoring()->submitScore($m2, $teamB, 2, 1); // 12 pts

        $rows = $this->scoring()->standings($tournament);

        $this->assertSame(12, $rows[0]->points);
        $this->assertSame(12, $rows[1]->points);
        $this->assertSame($teamA->id, $rows[0]->team_id); // more kills
        $this->assertSame($teamB->id, $rows[1]->team_id);
    }

    public function test_identical_stats_rank_deterministically_by_team_id(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamC);
        $m2 = $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 0, 1);
        $this->scoring()->submitScore($m2, $teamB, 0, 1);

        $rows = $this->scoring()->standings($tournament);

        $this->assertCount(2, $rows);
        // Identical inputs → identical ranking: lower team id first.
        $this->assertSame($teamA->id, $rows[0]->team_id);
        $this->assertSame($teamB->id, $rows[1]->team_id);
    }

    public function test_bye_match_scores_do_not_count(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $byeMatch = $this->makeMatch($tournament, $teamA, null, 'bye');
        $byeMatch->winner_team_id = $teamA->id;
        $byeMatch->save();

        $score = new Score();
        $score->match_id = $byeMatch->id;
        $score->team_id = $teamA->id;
        $score->kills = 5;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = 5;
        $score->points = 17;
        $score->status = 'pending';
        $score->save();

        $this->assertEmpty($this->scoring()->standings($tournament));
    }

    public function test_disputed_match_scores_excluded_until_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 2, 1);
        $this->assertCount(1, $this->scoring()->standings($tournament));

        $progression = app(\App\Services\MatchProgressionService::class);
        $progression->complete($match, $teamA);
        $progression->dispute($match);
        $this->assertEmpty($this->scoring()->standings($tournament));

        $progression->resolve($match, $teamA);
        $this->assertCount(1, $this->scoring()->standings($tournament));
    }

    public function test_cancelled_match_scores_do_not_count(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 2, 1);
        $this->assertCount(1, $this->scoring()->standings($tournament));

        $match->status = 'cancelled';
        $match->save();

        $this->assertEmpty($this->scoring()->standings($tournament));
    }
}
```

### FILE: tests/Feature/ScoringSecurityTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — scoring security, validation and match-state integration.
 */
class ScoringSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Scoring Security Tournament';
        $t->slug = $o['slug'] ?? ('scoring-sec-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
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

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'live'): GameMatch
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

    protected function scoring(): ScoringService
    {
        return app(ScoringService::class);
    }

    // ------------------------------------------------------------------
    // Rule management authorization
    // ------------------------------------------------------------------

    public function test_organizer_can_view_scoring_rules_page(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertOk()
            ->assertSee('Scoring Rules');
    }

    public function test_participant_cannot_view_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        $this->actingAs($player)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertStatus(403);
    }

    public function test_participant_cannot_create_scoring_version(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        $this->actingAs($player)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertStatus(403);

        $this->assertSame(0, $tournament->scoringRules()->count());
    }

    public function test_other_organizer_cannot_manage_another_tournaments_rules(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);

        $this->actingAs($orgB)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertStatus(403);

        $this->actingAs($orgB)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertStatus(403);
    }

    public function test_organizer_can_manage_own_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'name' => 'My rules',
                'kill_points' => 2,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, $tournament->scoringRules()->count());
        $this->assertSame(2, (int) $this->scoring()->currentRuleSet($tournament)->kill_points);
    }

    public function test_admin_can_manage_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org);

        $this->actingAs($admin)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 3,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHas('success');

        $this->assertSame(3, (int) $this->scoring()->currentRuleSet($tournament)->kill_points);
    }

    public function test_client_cannot_inject_rule_version_or_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $otherOrg = $this->makeUser('organizer');
        $other = $this->makeTournament($otherOrg, 'live', ['name' => 'Other']);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
                'version' => 99,           // ignored
                'is_current' => false,     // ignored
                'tournament_id' => $other->id, // ignored
            ])
            ->assertSessionHas('success');

        $rule = $tournament->scoringRules()->first();
        $this->assertSame(1, (int) $rule->version);
        $this->assertSame($tournament->id, $rule->tournament_id);
        $this->assertTrue((bool) $rule->is_current);
    }

    // ------------------------------------------------------------------
    // Rule input validation
    // ------------------------------------------------------------------

    public function test_negative_kill_points_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => -1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHasErrors('kill_points');
    }

    public function test_negative_placement_points_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $placement = ScoringRule::DEFAULT_PLACEMENT_POINTS;
        $placement[1] = -3;

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => $placement,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHasErrors('placement_points.1');
    }

    public function test_invalid_tie_breaker_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ['nonsense'],
            ])
            ->assertSessionHasErrors('tie_breakers.0');
    }

    // ------------------------------------------------------------------
    // Score submission validation
    // ------------------------------------------------------------------

    public function test_negative_kills_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => -5, 'placement' => 1,
        ])->assertSessionHasErrors('kills');
    }

    public function test_non_integer_kills_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 'abc', 'placement' => 1,
        ])->assertSessionHasErrors('kills');
    }

    public function test_placement_zero_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 0, 'placement' => 0,
        ])->assertSessionHasErrors('placement');
    }

    public function test_placement_above_max_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 0, 'placement' => 13,
        ])->assertSessionHasErrors('placement');
    }

    public function test_duplicate_score_submission_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 2,
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 9, 'placement' => 1,
        ])->assertStatus(403);

        $this->assertSame(1, Score::where('match_id', $match->id)->where('team_id', $teamA->id)->count());
    }

    public function test_duplicate_placement_blocks_second_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamB->id, 'kills' => 4, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_database_unique_constraint_backstop(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->expectException(\Illuminate\Database\QueryException::class);
        // Bypass the service and hit the DB unique constraint directly.
        \Illuminate\Support\Facades\DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $teamA->id,
            'kills' => 2,
            'placement' => 2,
            'points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // ------------------------------------------------------------------
    // Match-state integration
    // ------------------------------------------------------------------

    public function test_score_submission_blocked_when_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'completed');
        $match->winner_team_id = $teamA->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_score_submission_blocked_when_pending(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'pending');

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_score_submission_blocked_when_bye(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, null, 'bye');
        $match->winner_team_id = $teamA->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_adjustment_blocked_after_match_finalized(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 3, 1);
        $this->assertSame(15, (int) $score->points);

        app(\App\Services\MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'late bonus',
        ])->assertSessionHas('error');

        $score->refresh();
        $this->assertSame(15, (int) $score->points);
        $this->assertSame(0, (int) $score->bonus_points);
    }

    public function test_player_cannot_add_adjustment(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $player);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->actingAs($player)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'hack',
        ])->assertStatus(403);
    }

    public function test_adjustment_requires_reason_and_valid_type(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'swiss', 'points' => 5, 'reason' => 'x',
        ])->assertSessionHasErrors('type');
    }

    public function test_cross_tournament_adjustment_forbidden(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, null);
        $teamB = $this->makeTeam($tournamentA, null);
        $matchA = $this->makeMatch($tournamentA, $teamA, $teamB);

        $this->scoring()->submitScore($matchA, $teamA, 1, 1);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournamentB, $matchA]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'x',
        ])->assertStatus(404);
    }
}
```

### FILE: tests/Feature/ScoringMigrationTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — schema/backfill verification for the scoring engine migration.
 */
class ScoringMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Schema Tournament';
        $t->slug = 'schema-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 1000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'live';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.rand(100000, 999999);
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    public function test_scoring_rules_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scoring_rules'));
        foreach (['id', 'tournament_id', 'version', 'name', 'kill_points', 'placement_points', 'tie_breakers', 'is_current', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('scoring_rules', $column), "Missing scoring_rules.$column");
        }
    }

    public function test_score_adjustments_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('score_adjustments'));
        foreach (['id', 'score_id', 'type', 'points', 'reason', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('score_adjustments', $column), "Missing score_adjustments.$column");
        }
    }

    public function test_scores_table_has_computed_columns(): void
    {
        foreach (['placement_points', 'kill_points', 'bonus_points', 'penalty_points', 'scoring_rules_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('scores', $column), "Missing scores.$column");
        }
    }

    public function test_unique_match_placement_constraint_enforced(): void
    {
        $org = User::factory()->create();
        $org->role = 'organizer';
        $org->save();
        $tournament = $this->makeTournament($org);

        $team1 = $this->makeTeam($tournament);
        $team2 = $this->makeTeam($tournament);

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team1->id;
        $match->team2_id = $team2->id;
        $match->status = 'live';
        $match->save();

        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 1,
            'placement' => 1,
            'points' => 13,
            'placement_points' => 12,
            'kill_points' => 1,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team2->id,
            'kills' => 2,
            'placement' => 1,
            'points' => 11,
            'placement_points' => 9,
            'kill_points' => 2,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_unique_match_team_constraint_still_enforced(): void
    {
        $org = User::factory()->create();
        $org->role = 'organizer';
        $org->save();
        $tournament = $this->makeTournament($org);

        $team1 = $this->makeTeam($tournament);
        $team2 = $this->makeTeam($tournament);

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team1->id;
        $match->team2_id = $team2->id;
        $match->status = 'live';
        $match->save();

        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 1,
            'placement' => 1,
            'points' => 13,
            'placement_points' => 12,
            'kill_points' => 1,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 9,
            'placement' => 2,
            'points' => 18,
            'placement_points' => 9,
            'kill_points' => 9,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
```
