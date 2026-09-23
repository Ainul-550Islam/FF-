<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for dispute/moderation actions and result
 * corrections. Records are created through DisputeService only and are not
 * editable by normal users.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class ModerationEvent extends Model
{
    use HasFactory;

    public const EVENT_DISPUTE_OPENED = 'dispute.opened';

    public const EVENT_EVIDENCE_ADDED = 'dispute.evidence_added';

    public const EVENT_EVIDENCE_REMOVED = 'dispute.evidence_removed';

    public const EVENT_STATUS_CHANGED = 'dispute.status_changed';

    public const EVENT_ASSIGNED = 'dispute.assigned';

    public const EVENT_RESOLVED = 'dispute.resolved';

    public const EVENT_REJECTED = 'dispute.rejected';

    public const EVENT_CANCELLED = 'dispute.cancelled';

    public const EVENT_RESULT_CORRECTED = 'result.corrected';

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
