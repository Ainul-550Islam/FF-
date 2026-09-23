<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for payout lifecycle changes (approved, processing,
 * completed, failed, cancelled) (Phase 09).
 *
 * No passwords, API secrets, card numbers or unnecessary credentials are
 * ever stored. Rows are written only by PayoutService.
 */
class PayoutEvent extends Model
{
    use HasFactory;

    public const EVENT_APPROVED = 'payout.approved';

    public const EVENT_PROCESSING = 'payout.processing';

    public const EVENT_COMPLETED = 'payout.completed';

    public const EVENT_FAILED = 'payout.failed';

    public const EVENT_CANCELLED = 'payout.cancelled';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'metadata' => 'array',
    ];

    public function payout()
    {
        return $this->belongsTo(Payout::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
