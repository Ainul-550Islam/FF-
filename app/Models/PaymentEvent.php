<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only financial audit trail for payment lifecycle changes
 * (created, verified, failed, cancelled, refunded, callback processed).
 *
 * No passwords, API secrets, card numbers or unnecessary credentials are
 * ever stored. Rows are written only by PaymentService.
 */
class PaymentEvent extends Model
{
    use HasFactory;

    public const EVENT_CREATED = 'payment.created';

    public const EVENT_VERIFIED = 'payment.verified';

    public const EVENT_PAID = 'payment.paid';

    public const EVENT_FAILED = 'payment.failed';

    public const EVENT_CANCELLED = 'payment.cancelled';

    public const EVENT_REFUNDED = 'payment.refunded';

    public const EVENT_CALLBACK = 'payment.callback';

    public const EVENT_GATEWAY_CONFIRMED = 'payment.gateway_confirmed';

    public const EVENT_GATEWAY_FAILED = 'payment.gateway_failed';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'metadata' => 'array',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
