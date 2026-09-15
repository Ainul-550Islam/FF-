<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-side refund of a payment.
 *
 * One refund per payment (enforced by a unique constraint). The refunded
 * amount is validated to equal the paid amount — partial or excessive
 * refunds are rejected by PaymentService.
 */
class Refund extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
