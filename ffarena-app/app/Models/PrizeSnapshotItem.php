<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable rank → team → amount row captured when a prize distribution
 * is calculated (Phase 09).
 *
 * The resolved amount (integer poisha) is frozen here so historical prize
 * calculations never change when the tournament prize pool, tiers, scoring,
 * leaderboard or fees are later edited. Rows are only created by
 * PrizeDistributionService and are never updated or deleted by the app.
 */
class PrizeSnapshotItem extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'amount_minor' => 'integer',
    ];

    public function distribution()
    {
        return $this->belongsTo(PrizeDistribution::class, 'distribution_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
