<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    /**
     * Team lifecycle statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_NO_SHOW = 'no_show';

    /**
     * Statuses that occupy a registration slot in a tournament.
     */
    public const SLOT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
    ];

    /**
     * Statuses that hold a competitive identity (Free Fire UID) in a
     * tournament. Waitlisted teams reserve their UID too, so a player cannot
     * appear on two teams (including a waitlisted one) in the same
     * tournament. Withdrawn teams release their UID and are excluded.
     */
    public const COMPETING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_WAITLISTED,
    ];

    /**
     * tournament_id, captain_id, status, seed, checked_in_at, checked_in_by
     * and waitlisted_at are server-controlled. Excluded from mass assignment
     * so a client can never forge participation/check-in/waitlist state.
     */
    protected $fillable = [
        'name',
        'captain_name',
        'phone',
        'game_uid',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'waitlisted_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Prize payouts awarded to this team (Phase 09).
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_team_id');
    }

    public function latestPayment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function isCaptain(User $user): bool
    {
        return $this->captain_id !== null && $this->captain_id === $user->id;
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isWaitlisted(): bool
    {
        return $this->status === self::STATUS_WAITLISTED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * Whether this team currently occupies a slot in its tournament.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::SLOT_STATUSES, true);
    }

    /**
     * Whether a member with the given (case-insensitive, trimmed) Free Fire
     * UID already exists on this team.
     */
    public function hasMemberWithUid(string $uid): bool
    {
        return $this->members()
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [strtoupper(trim($uid))])
            ->exists();
    }

    /**
     * Total roster size: the captain plus all members.
     */
    public function rosterSize(): int
    {
        return 1 + $this->members()->count();
    }

    /**
     * 1-based position on the tournament waitlist, or null when not
     * waitlisted. Deterministic FIFO ordering: waitlisted_at, then id.
     */
    public function waitlistPosition(): ?int
    {
        if (! $this->isWaitlisted()) {
            return null;
        }

        return Team::query()
            ->where('tournament_id', $this->tournament_id)
            ->where('status', self::STATUS_WAITLISTED)
            ->where(function ($q) {
                $q->where('waitlisted_at', '<', $this->waitlisted_at)
                    ->orWhere(function ($q2) {
                        $q2->where('waitlisted_at', $this->waitlisted_at)
                            ->where('id', '<', $this->id);
                    });
            })
            ->count() + 1;
    }
}
