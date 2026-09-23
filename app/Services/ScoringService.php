<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LiveEvent;
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
    public function __construct(
        protected LiveEventService $live,
    ) {}

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
            throw new DomainException('Placement must be between 1 and '.ScoringRule::MAX_PLACEMENT.'.');
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

                // Phase 12 — live event (atomic with the score; best-effort).
                $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_SCORE_SUBMITTED, [
                    'match_no' => (int) $match->match_no,
                    'round' => (int) $match->round,
                    'team' => $team->name,
                    'kills' => $kills,
                    'placement' => $placement,
                ]);

                // Phase 15 — outbound webhook (best-effort).
                app(WebhookDispatcher::class)->dispatchQuietly('match.score_submitted', [
                    'match_id' => $match->id,
                    'match_no' => (int) $match->match_no,
                    'round' => (int) $match->round,
                    'tournament_id' => $match->tournament_id,
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'kills' => $kills,
                    'placement' => $placement,
                    'points' => (int) $score->points,
                ]);

                // Phase 16 — standings and the match view derived from this
                // score are stale.
                $cache = app(CacheInvalidationService::class);
                $cache->invalidateLeaderboard($match->tournament_id);
                $cache->invalidateMatch($match->id);

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
            throw new DomainException('Placement must be between 1 and '.ScoringRule::MAX_PLACEMENT.'.');
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
