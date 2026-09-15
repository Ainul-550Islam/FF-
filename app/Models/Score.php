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
