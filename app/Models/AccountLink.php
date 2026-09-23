<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A defensive account-similarity link between two accounts (Phase 10).
 *
 * Pairs are stored in canonical order (lower user id first) with a unique
 * constraint, so the same pair can never be recorded twice. Links carry a
 * confidence (weak/moderate/strong) and the reason categories, never raw
 * sensitive matching data.
 */
class AccountLink extends Model
{
    use HasFactory;

    public const STRENGTH_WEAK = 'weak';

    public const STRENGTH_MODERATE = 'moderate';

    public const STRENGTH_STRONG = 'strong';

    public const STRENGTHS = [
        self::STRENGTH_WEAK,
        self::STRENGTH_MODERATE,
        self::STRENGTH_STRONG,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'reasons' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function linkedUser()
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
