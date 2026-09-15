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
