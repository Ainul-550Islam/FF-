<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An A/B experiment (Phase 21).
 *
 * Variant assignment is deterministic (hash of experiment key + visitor
 * identity), bucketed by traffic_allocation percent, so the same visitor
 * always sees the same variant. Product behavior outside the experiment
 * boundary is never changed.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property int $traffic_allocation
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array|null $metadata
 */
class MarketingExperiment extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'key', 'name', 'description', 'status', 'traffic_allocation',
        'starts_at', 'ends_at', 'metadata',
    ];

    protected $casts = [
        'traffic_allocation' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function variants()
    {
        return $this->hasMany(MarketingExperimentVariant::class, 'experiment_id')->orderBy('id');
    }

    /**
     * Whether the experiment is currently assigning variants.
     */
    public function isRunning(): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }
}
