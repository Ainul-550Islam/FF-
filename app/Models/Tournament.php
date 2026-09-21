<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Tournament extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CLOSED = 'closed';

    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';
    public const FORMAT_BRACKET = 'bracket';
    public const FORMAT_RANKED = 'ranked';

    protected $fillable = ['name','slug','status','entry_fee_minor','prize_pool_minor','max_teams','starts_at','ends_at','metadata','organizer_id','game_mode','map','entry_fee','prize_pool','team_slots','team_size','rules'];

    /**
     * The slug is the canonical public identifier for a tournament — web and
     * API URLs both address tournaments by slug.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
    protected $casts = ['entry_fee_minor'=>'integer','prize_pool_minor'=>'integer','max_teams'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','metadata'=>'array'];

    public function payouts(){return $this->hasMany(Payout::class);}
    public function settlements(){return $this->hasMany(FinancialSettlement::class);}
    public function organizer(){return $this->belongsTo(User::class, 'organizer_id');}
    public function teams(){return $this->hasMany(Team::class);}
    public function matches(){return $this->hasMany(GameMatch::class);}
    public function disputes(){return $this->hasMany(Dispute::class);}
    public function scoringRules(){return $this->hasMany(ScoringRule::class);}
    public function prizeTiers(){return $this->hasMany(PrizeTier::class);}
    public function prizeDistributions(){return $this->hasMany(PrizeDistribution::class);}

    /**
     * Teams eligible for the bracket: confirmed entries only (RegistrationService
     * guarantees confirmed teams are the bracket-eligible population).
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Registration window: only open tournaments accept new teams.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * The tournament has begun (live or already finished) — registration is
     * permanently closed from this point on.
     */
    public function hasStarted(): bool
    {
        return in_array($this->status, [self::STATUS_LIVE, self::STATUS_FINISHED], true);
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    /**
     * Entry fee in minor units (taka paisa). Prefers the Phase-04 minor-unit
     * column; falls back to the legacy whole-unit entry_fee column.
     */
    public function entryFeeMinor(): int
    {
        if ($this->entry_fee_minor !== null) {
            return (int) $this->entry_fee_minor;
        }
        return (int) ($this->entry_fee ?? 0) * 100;
    }

    /**
     * Prize pool in minor units. Prefers the Phase-04 minor-unit column;
     * falls back to the legacy whole-unit prize_pool column.
     */
    public function prizePoolMinor(): int
    {
        if ($this->prize_pool_minor !== null) {
            return (int) $this->prize_pool_minor;
        }
        return (int) ($this->prize_pool ?? 0) * 100;
    }

    /**
     * Slot capacity check (mirrors the atomic slot claim in
     * RegistrationService: pending + confirmed teams vs team_slots,
     * falling back to the Phase-04 max_teams column).
     */
    public function isFull(): bool
    {
        $capacity = (int) ($this->team_slots ?? $this->max_teams ?? 0);

        if ($capacity <= 0) {
            return false;
        }

        return $this->teams()
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED])
            ->count() >= $capacity;
    }

    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null;
    }

    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        $now = now();
        if ($now->lt($this->check_in_starts_at)) {
            return false;
        }

        return $this->check_in_ends_at === null || $now->lte($this->check_in_ends_at);
    }

    public function checkInHasClosed(): bool
    {
        return $this->check_in_ends_at !== null && now()->gt($this->check_in_ends_at);
    }
}
