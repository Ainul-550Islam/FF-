<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Team;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Match progression (Phase 05).
 *
 * Controls the match state machine and winner advancement. Authorization is
 * handled by policies in the controllers; this service only answers "is this
 * progression legal right now?" and performs it atomically.
 */
class MatchProgressionService
{
    public function __construct(
        protected BracketService $bracket,
        protected LiveEventService $live,
    ) {
    }

    /**
     * Record a winner and complete a match, then advance the bracket.
     *
     * @return string 'completed' | 'already'
     */
    public function complete(GameMatch $match, Team $winner): string
    {
        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        if ($match->status === GameMatch::STATUS_COMPLETED) {
            if ($match->winner_team_id === $winner->id) {
                return 'already';
            }

            throw new DomainException('This match is already completed — dispute it before changing the result.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_READY, GameMatch::STATUS_LIVE], true)) {
            throw new DomainException('This match cannot be completed from its current state.');
        }

        DB::transaction(function () use ($match, $winner) {
            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match);

            // Phase 12 — live event (atomic with the match, best-effort).
            $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, [
                'match_no' => (int) $match->match_no,
                'round' => (int) $match->round,
                'winner' => $winner->name,
            ]);
        });

        return 'completed';
    }

    /**
     * Move a completed match into the disputed state. Advancement is halted
     * while disputed.
     */
    public function dispute(GameMatch $match): void
    {
        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $match->status = GameMatch::STATUS_DISPUTED;
        $match->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_DISPUTED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
        ]);
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner. If the
     * winner changed, the stale winner/loser are replaced in the downstream
     * match slots.
     */
    public function resolve(GameMatch $match, Team $winner): void
    {
        if ($match->status !== GameMatch::STATUS_DISPUTED) {
            throw new DomainException('Only disputed matches can be resolved.');
        }

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        DB::transaction(function () use ($match, $winner) {
            $stale = $match->winner_team_id;

            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match, $stale);

            // Phase 12 — live event (atomic with the match, best-effort).
            $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_RESOLVED, [
                'match_no' => (int) $match->match_no,
                'round' => (int) $match->round,
                'winner' => $winner->name,
            ]);
        });
    }

    /**
     * Move a ready/pending match into the live state.
     */
    public function start(GameMatch $match): void
    {
        if (! in_array($match->status, [GameMatch::STATUS_PENDING, GameMatch::STATUS_READY], true)) {
            throw new DomainException('This match cannot be started from its current state.');
        }

        $match->status = GameMatch::STATUS_LIVE;
        $match->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_STARTED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
        ]);
    }
}
