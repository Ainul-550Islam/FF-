<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved manual correction/reversal to a tournament's financial
 * settlement (Phase 09).
 *
 * A signed integer minor-unit amount (positive credit, negative debit) with a
 * mandatory reason and the acting admin. Adjustments flow into the
 * reconciliation math and are frozen into the FinancialSettlement snapshot.
 *
 * Append-only: rows are never edited after creation, and no adjustments are
 * accepted once a settlement has been finalized.
 */
class SettlementAdjustment extends Model
{
    use HasFactory;

    public const TYPE_CORRECTION = 'correction';
    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [
        self::TYPE_CORRECTION,
        self::TYPE_REVERSAL,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
