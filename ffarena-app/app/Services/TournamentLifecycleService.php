<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side tournament lifecycle state machine.
 *
 * Every status change goes through one of these methods. Each method:
 *   1. asserts the transition is legal from the current state
 *   2. runs any state-specific preconditions
 *   3. mutates the status (atomically, inside a transaction where needed)
 *
 * Authorization ("is this user allowed?") is a separate concern and remains
 * in the controller via policies; this service only answers
 * "is this transition allowed from the current state, and are its
 * preconditions satisfied?".
 */
class TournamentLifecycleService
{
    /**
     * Publish a draft tournament, opening registration.
     */
    public function publish(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_OPEN);

        $errors = $this->publicationErrors($tournament);
        if ($errors !== []) {
            throw new DomainException('Cannot publish: '.implode(' ', $errors));
        }

        $tournament->status = Tournament::STATUS_OPEN;
        $tournament->save();

        app(CacheInvalidationService::class)->invalidateTournament($tournament->id);
    }

    /**
     * Close registration for an open tournament.
     */
    public function closeRegistration(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_CLOSED);

        $tournament->status = Tournament::STATUS_CLOSED;
        $tournament->save();

        app(CacheInvalidationService::class)->invalidateTournament($tournament->id);
    }

    /**
     * Cancel a tournament that has not yet gone live.
     */
    public function cancel(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_CANCELLED);

        $tournament->status = Tournament::STATUS_CANCELLED;
        $tournament->save();

        app(CacheInvalidationService::class)->invalidateTournament($tournament->id);
    }

    /**
     * Start the tournament: generate the bracket and move to LIVE.
     *
     * Idempotent: if the tournament is already live, the existing match count
     * is returned without regenerating (no data loss on a retry). Runs inside
     * a transaction so the bracket and the status change are committed
     * atomically.
     *
     * @return int number of matches in the bracket
     */
    public function start(Tournament $tournament, BracketService $bracket): int
    {
        if ($tournament->status === Tournament::STATUS_LIVE) {
            return $tournament->matches()->count();
        }

        $this->assertTransition($tournament, Tournament::STATUS_LIVE);

        // Never start (and freeze the bracket) while check-in is still open:
        // teams must have checked in before they can be seeded.
        if ($tournament->hasCheckIn() && ! $tournament->checkInHasClosed()) {
            throw new DomainException('The check-in window has not closed yet.');
        }

        return DB::transaction(function () use ($tournament, $bracket) {
            $count = $bracket->generate($tournament);

            if ($count === 0) {
                throw new DomainException($this->generationFailureMessage($tournament));
            }

            $tournament->status = Tournament::STATUS_LIVE;
            $tournament->save();

            app(CacheInvalidationService::class)->invalidateTournament($tournament->id);

            return $count;
        });
    }

    /**
     * Finish a live tournament. Every match must already be completed so we
     * never mark a tournament finished while matches are still undecided.
     */
    public function complete(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_FINISHED);

        if ($tournament->matches()->where('status', '!=', GameMatch::STATUS_COMPLETED)->exists()) {
            throw new DomainException(
                'All matches must be completed before the tournament can be finished.'
            );
        }

        $tournament->status = Tournament::STATUS_FINISHED;
        $tournament->save();

        app(CacheInvalidationService::class)->invalidateTournament($tournament->id);
    }

    /**
     * Assert that the requested transition is legal from the current state.
     */
    public function assertTransition(Tournament $tournament, string $target): void
    {
        if (! $tournament->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                "Cannot move a tournament from '%s' to '%s'.",
                $tournament->status,
                $target
            ));
        }
    }

    /**
     * Human-readable bracket generation failure for the tournament format.
     */
    protected function generationFailureMessage(Tournament $tournament): string
    {
        if ($tournament->isDoubleElim()) {
            return 'Bracket generation failed: double elimination requires 4, 8, 16 or 32 eligible teams (a power of two).';
        }

        return 'Bracket generation failed: at least 2 eligible teams are required.';
    }

    /**
     * Human-readable configuration problems that prevent publication.
     */
    public function publicationErrors(Tournament $tournament): array
    {
        $errors = [];

        if (trim((string) $tournament->name) === '') {
            $errors[] = 'The tournament name is required.';
        }

        if (! in_array($tournament->game_mode, ['squad', 'duo', 'solo'], true)) {
            $errors[] = 'The game mode is invalid.';
        }

        if (trim((string) $tournament->map) === '') {
            $errors[] = 'The map is required.';
        }

        if ($tournament->entry_fee === null || $tournament->entry_fee < 0) {
            $errors[] = 'The entry fee must be a non-negative amount.';
        }

        if ($tournament->prize_pool === null || $tournament->prize_pool < 0) {
            $errors[] = 'The prize pool must be a non-negative amount.';
        }

        if (! in_array((int) $tournament->team_slots, [8, 16, 32], true)) {
            $errors[] = 'Team slots must be 8, 16 or 32.';
        }

        if ((int) $tournament->team_size < 1) {
            $errors[] = 'Team size must be at least 1.';
        }

        if ($tournament->starts_at === null) {
            $errors[] = 'A start time is required.';
        } elseif ($tournament->starts_at->isPast()) {
            $errors[] = 'The start time must be in the future.';
        }

        return $errors;
    }
}
