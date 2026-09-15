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
