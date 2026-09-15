<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable prize rule for one finishing rank of a tournament
 * (Phase 09).
 *
 * Either a fixed minor-unit amount (BDT poisha) or a percentage (stored in
 * integer basis points) of the declared prize pool. Rules are validated
 * server-side (no negative values, no duplicate positions, allocation never
 * exceeds the pool) and are snapshotted before distribution so later edits
 * never rewrite historical prizes.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class PrizeTier extends Model
{
    use HasFactory;

    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_PERCENTAGE,
    ];

    public const MAX_POSITION = 100;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'amount_minor' => 'integer',
        'percentage_bp' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function typeLabel(): string
    {
        return $this->isFixed() ? 'Fixed' : 'Percentage';
    }
}
