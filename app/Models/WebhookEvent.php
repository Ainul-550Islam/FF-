<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed inbound provider event (Phase 15).
 *
 * The raw payload is stored encrypted (never plaintext); only safe metadata
 * is stored in the clear. `(provider, external_event_id)` is unique so a
 * replayed event is idempotent.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    public const SIGNATURE_VERIFIED = 'verified';
    public const SIGNATURE_INVALID = 'invalid';
    public const SIGNATURE_MISSING = 'missing';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REPLAYED = 'replayed';

    protected $fillable = [];

    protected $casts = [
        'attempts' => 'integer',
        'metadata' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_REPLAYED;
    }
}
