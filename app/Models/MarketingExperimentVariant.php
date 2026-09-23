<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An experiment variant (Phase 21).
 *
 * @property int $id
 * @property int $experiment_id
 * @property string $key
 * @property string $name
 * @property int $allocation
 * @property array|null $configuration
 */
class MarketingExperimentVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'experiment_id', 'key', 'name', 'allocation', 'configuration',
    ];

    protected $casts = [
        'allocation' => 'integer',
        'configuration' => 'array',
    ];

    public function experiment()
    {
        return $this->belongsTo(MarketingExperiment::class, 'experiment_id');
    }
}
