<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 04 — registration eligibility, check-in, waitlist and no-show
 * handling.
 *
 * This service owns the *participation* state machine that sits on top of the
 * Phase 02 tournament lifecycle and the Phase 03 roster rules:
 *
 *   - check-in (window enforcement, idempotency, admin override)
 *   - waitlist FIFO ordering and race-safe promotion
 *   - no-show marking once the check-in window closes
 *
 * Authorization lives in policies; this service answers
 * "is this participation change legal right now?" and performs it.
 */
class TournamentParticipationService
{
    public function __construct(
        protected LiveEventService $live,
    ) {}

    /**
     * Check a team in. Idempotent — checking in twice returns 'already' and
     * does not alter slot accounting or timestamps.
     *
     * @return string 'checked_in' | 'already'
     */
    public function checkIn(Tournament $tournament, Team $team, User $user, bool $override = false): string
    {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if ($team->status !== Team::STATUS_CONFIRMED) {
            throw new DomainException('Only confirmed teams can check in.');
        }

        if (! $tournament->hasCheckIn()) {
            throw new DomainException('Check-in is not configured for this tournament.');
        }

        if ($team->checked_in_at !== null) {
            return 'already';
        }

        if (! $override && ! $tournament->checkInIsOpen()) {
            if ($tournament->checkInHasClosed()) {
                throw new DomainException('The check-in window has closed.');
            }

            throw new DomainException('Check-in has not opened yet.');
        }

        $team->checked_in_at = now();
        $team->checked_in_by = $user->id;
        $team->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, [
            'team' => $team->name,
        ]);

        return 'checked_in';
    }

    /**
     * Promote the next waitlisted team into a free slot.
     *
     * Deterministic (FIFO by waitlisted_at, then id), transaction-safe and
     * idempotent per team: once promoted, a team is no longer waitlisted and
     * can never be selected again. The atomic slot claim guarantees that two
     * simultaneous promotions can never both fill the same final slot.
     */
    public function promoteNext(Tournament $tournament): Team
    {
        return DB::transaction(function () use ($tournament) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->acceptsRegistration()) {
                throw new DomainException('Registration is closed — waitlisted teams can no longer be promoted.');
            }

            // ATOMIC SLOT CLAIM — only succeeds while a slot is actually free.
            $claimed = DB::table('tournaments')
                ->where('id', $fresh->id)
                ->where('status', Tournament::STATUS_OPEN)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
                })
                ->whereRaw(
                    '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                    [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
                )
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                throw new DomainException('No available slot to promote a waitlisted team into.');
            }

            $next = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_WAITLISTED)
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                throw new DomainException('The waitlist is empty.');
            }

            $next->status = Team::STATUS_PENDING;
            $next->waitlisted_at = null;
            $next->save();

            return $next;
        });
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (only once the
     * check-in window has closed), then promote waitlisted teams into the
     * freed slots.
     *
     * @return array{no_shows: int, promoted: int}
     */
    public function markNoShowsAndPromote(Tournament $tournament): array
    {
        $noShows = 0;

        DB::transaction(function () use ($tournament, &$noShows) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->hasCheckIn()) {
                throw new DomainException('Check-in is not configured for this tournament.');
            }

            if (! $fresh->checkInHasClosed()) {
                throw new DomainException('The check-in window has not closed yet.');
            }

            $missing = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_CONFIRMED)
                ->whereNull('checked_in_at')
                ->get();

            $noShows = $missing->count();

            foreach ($missing as $team) {
                $team->status = Team::STATUS_NO_SHOW;
                $team->save();
            }
        });

        // Promote as many waitlisted teams as freed slots allow. Each
        // promotion is its own atomic transaction.
        $promoted = 0;
        while (true) {
            try {
                $this->promoteNext($tournament);
                $promoted++;
            } catch (DomainException $e) {
                break;
            }
        }

        return ['no_shows' => $noShows, 'promoted' => $promoted];
    }
}
