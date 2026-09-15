<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only live event (Phase 12).
 *
 * The auto-incrementing id is a global monotonic cursor that clients pass
 * back as `since`. Payloads are server-generated and carry only non-sensitive
 * display data — never scores-in-progress of other teams' hidden state, never
 * personal data, never secrets. All fields are excluded from mass assignment.
 */
class LiveEvent extends Model
{
    use HasFactory;

    public const TYPE_SCORE_SUBMITTED = 'match.score_submitted';
    public const TYPE_MATCH_COMPLETED = 'match.completed';
    public const TYPE_MATCH_DISPUTED = 'match.disputed';
    public const TYPE_MATCH_RESOLVED = 'match.resolved';
    public const TYPE_MATCH_STARTED = 'match.started';
    public const TYPE_TEAM_CHECKED_IN = 'team.checked_in';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_CLOSED = 'dispute.closed';

    // Phase 13 — staff-only support signals (never in the public allowlist).
    public const TYPE_SUPPORT_CREATED = 'support.created';
    public const TYPE_SUPPORT_MESSAGE = 'support.message';
    public const TYPE_SUPPORT_STATUS_CHANGED = 'support.status_changed';
    public const TYPE_SUPPORT_ASSIGNED = 'support.assigned';

    // Phase 14 — account-level, user-targeted signals (never public).
    public const TYPE_ACCOUNT_PAYMENT_STATUS = 'account.payment_status';
    public const TYPE_ACCOUNT_SESSION_REVOKED = 'account.session_revoked';
    public const TYPE_ACCOUNT_VERIFICATION_STATUS = 'account.verification_status';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
