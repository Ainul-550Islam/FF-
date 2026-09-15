<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    /**
     * The database table is named "matches" (game_matches would collide with
     * common Eloquent naming, and the migration creates the `matches` table).
     */
    protected $table = 'matches';

    /**
     * Match lifecycle statuses.
     *
     * pending   → waiting for participants (placeholder)
     * ready     → both participants assigned, waiting to start
     * live      → in progress (room published)
     * completed → finished with a winner
     * disputed  → completed result is contested
     * bye       → auto-advanced (single participant, no play)
     * cancelled → abandoned
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_LIVE = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_BYE = 'bye';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid match state transitions. `bye`, `cancelled` and `completed` are
     * terminal except for the explicit dispute flow (completed → disputed).
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_READY, self::STATUS_LIVE, self::STATUS_BYE, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_LIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_COMPLETED, self::STATUS_DISPUTED],
        self::STATUS_COMPLETED => [self::STATUS_DISPUTED],
        self::STATUS_DISPUTED => [self::STATUS_COMPLETED, self::STATUS_LIVE],
        self::STATUS_BYE => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket sides.
     */
    public const BRACKET_WINNERS = 'winners';
    public const BRACKET_LOSERS = 'losers';
    public const BRACKET_GRAND_FINAL = 'grand_final';

    /**
     * All bracket-structure and lifecycle fields are server-controlled.
     * Excluded from mass assignment so a client can never forge a winner,
     * participants, or bracket links.
     */
    protected $fillable = [
        'room_id',
        'room_pass',
        'scheduled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'round' => 'integer',
        'match_no' => 'integer',
        'next_slot' => 'integer',
        'loser_slot' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team1()
    {
        return $this->belongsTo(Team::class, 'team1_id');
    }

    public function team2()
    {
        return $this->belongsTo(Team::class, 'team2_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function nextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'next_match_id');
    }

    public function loserNextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'loser_next_match_id');
    }

    public function scores()
    {
        return $this->hasMany(Score::class, 'match_id');
    }

    public function disputes()
    {
        return $this->hasMany(Dispute::class, 'match_id');
    }

    /**
     * The participating teams (1 or 2), loaded from the DB.
     *
     * @return \Illuminate\Support\Collection<int, Team>
     */
    public function participantTeams()
    {
        return Team::whereIn('id', array_filter([$this->team1_id, $this->team2_id]))->get();
    }

    /**
     * The participating team captained by the given user, or null when the
     * user is not the captain of either participant.
     */
    public function participantTeamFor(User $user): ?Team
    {
        return $this->participantTeams()->firstWhere('captain_id', $user->id);
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function hasParticipant(Team $team): bool
    {
        return $this->team1_id === $team->id || $this->team2_id === $team->id;
    }

    /**
     * Whether both participant slots are filled.
     */
    public function hasBothTeams(): bool
    {
        return $this->team1_id !== null && $this->team2_id !== null;
    }

    /**
     * The team id currently occupying the given slot (1 = team1, 2 = team2),
     * or null when the slot is empty.
     */
    public function teamIdInSlot(?int $slot): ?int
    {
        return match ($slot) {
            1 => $this->team1_id,
            2 => $this->team2_id,
            default => null,
        };
    }

    /**
     * Whether the given slot is already occupied.
     */
    public function hasTeamInSlot(?int $slot): bool
    {
        return $this->teamIdInSlot($slot) !== null;
    }

    /**
     * Assign a team to a slot (1 = team1, 2 = team2).
     */
    public function setTeamSlot(int $slot, int $teamId): void
    {
        if ($slot === 1) {
            $this->team1_id = $teamId;
        } elseif ($slot === 2) {
            $this->team2_id = $teamId;
        }
    }

    /**
     * The losing participant, or null for a bye/undecided match.
     */
    public function loserTeamId(): ?int
    {
        if ($this->winner_team_id === null || ! $this->hasBothTeams()) {
            return null;
        }

        return $this->winner_team_id === $this->team1_id ? $this->team2_id : $this->team1_id;
    }

    public function isBye(): bool
    {
        return $this->status === self::STATUS_BYE;
    }

    /**
     * Whether score submission/adjustment is open for this match (ready or
     * live only). Completed, disputed, bye, cancelled and pending matches
     * are closed.
     */
    public function acceptsScoreSubmission(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_LIVE], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Human label for the bracket side (used in views).
     */
    public function bracketLabel(): string
    {
        return match ($this->bracket) {
            self::BRACKET_LOSERS => 'Losers Bracket',
            self::BRACKET_GRAND_FINAL => 'Grand Final',
            default => 'Winners Bracket',
        };
    }

    /**
     * Human label for this match's round within its bracket side.
     */
    public function roundLabel(): string
    {
        if ($this->bracket === self::BRACKET_GRAND_FINAL) {
            return '🏁 Grand Final';
        }

        if ($this->bracket === self::BRACKET_LOSERS) {
            return 'Losers Round ' . $this->round;
        }

        return 'Round ' . $this->round;
    }

    /**
     * Status pill class name for the Blade views.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'finished',
            self::STATUS_LIVE => 'live',
            self::STATUS_READY => 'ready',
            self::STATUS_BYE => 'bye',
            self::STATUS_DISPUTED => 'disputed',
            default => 'draft',
        };
    }
}
