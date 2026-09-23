<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed inbound provider event (Phase 15) recorded on the union schema.
 *
 * The raw payload is stored encrypted (`payload_encrypted`, never plaintext);
 * the legacy generation additionally keeps the decrypted `payload` snapshot.
 * `(provider, external_event_id)` is unique so a replayed event is idempotent,
 * and the legacy `event_id` column keeps its own unique guard.
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

    /**
     * Legacy state vocabulary (pre-Phase15 ingress rows).
     */
    public const STATE_RECEIVED = 'received';

    public const STATE_VALIDATED = 'validated';

    public const STATE_PROCESSING = 'processing';

    public const STATE_PROCESSED = 'processed';

    public const STATE_FAILED = 'failed';

    public const STATE_DUPLICATE = 'duplicate';

    protected $fillable = ['provider', 'event_type', 'event_id', 'external_event_id', 'payload', 'payload_encrypted', 'signature', 'signature_status', 'state', 'status', 'attempts', 'last_error', 'received_at', 'processed_at', 'metadata'];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Whether the event has been handled (either vocabulary).
     */
    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_REPLAYED;
    }

    public function canRetry(): bool
    {
        return $this->attempts < 3 && $this->status !== self::STATUS_PROCESSED;
    }
}
