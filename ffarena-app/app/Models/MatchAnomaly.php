<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A deterministic, append-only match anomaly signal (Phase 10).
 *
 * Anomalies are statistical observations (anomaly / suspicious /
 * requires_review), never automatic accusations of cheating. They are
 * recorded by AntiCheatService from server-side score analysis and feed the
 * moderation workflow as review input.
 */
class MatchAnomaly extends Model
{
    use HasFactory;

    public const SEVERITY_ANOMALY = 'anomaly';
    public const SEVERITY_SUSPICIOUS = 'suspicious';
    public const SEVERITY_REQUIRES_REVIEW = 'requires_review';

    public const SEVERITIES = [
        self::SEVERITY_ANOMALY,
        self::SEVERITY_SUSPICIOUS,
        self::SEVERITY_REQUIRES_REVIEW,
    ];

    public const KIND_ABNORMAL_KILL_RATIO = 'abnormal_kill_ratio';
    public const KIND_REPEATED_PATTERN = 'repeated_pattern';
    public const KIND_UNEXPECTED_PARTICIPATION = 'unexpected_participation';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
