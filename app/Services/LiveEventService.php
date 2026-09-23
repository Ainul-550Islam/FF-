<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Realtime / live-update event log (Phase 12).
 *
 * Records domain events (score submitted, match completed, team checked in,
 * dispute opened, …) as an append-only, monotonic stream and serves them back
 * to clients with server-side visibility enforcement: public types are
 * visible to everyone; anything else requires tournament staff (organizer of
 * that tournament, moderator or admin).
 *
 * Recording is best-effort by design (`recordQuietly`): the live feed must
 * never break the originating business action.
 */
class LiveEventService
{
    /**
     * Record a live event. Throws on failure (callers should prefer
     * recordQuietly inside business flows).
     */
    public function record(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): LiveEvent {
        $event = new LiveEvent();
        $event->tournament_id = $tournament?->id;
        $event->actor_user_id = $actor?->id;
        $event->type = $type;
        $event->payload = $payload;
        $event->save();

        return $event;
    }

    /**
     * Record a live event without ever throwing into the caller.
     */
    public function recordQuietly(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): ?LiveEvent {
        try {
            return $this->record($tournament, $actor, $type, $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Record a user-targeted live event (Phase 14 — account signals such as
     * payment status, session revocation and verification status). These
     * events are never public: they are delivered only to the target user.
     */
    public function recordForUser(
        ?User $target,
        ?User $actor,
        string $type,
        array $payload = [],
        ?Tournament $tournament = null,
    ): LiveEvent {
        $event = new LiveEvent();
        $event->tournament_id = $tournament?->id;
        $event->actor_user_id = $actor?->id;
        $event->target_user_id = $target?->id;
        $event->type = $type;
        $event->payload = $payload;
        $event->save();

        return $event;
    }

    /**
     * Record a user-targeted live event without ever throwing.
     */
    public function recordForUserQuietly(
        ?User $target,
        ?User $actor,
        string $type,
        array $payload = [],
        ?Tournament $tournament = null,
    ): ?LiveEvent {
        try {
            return $this->recordForUser($target, $actor, $type, $payload, $tournament);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Events targeted at a specific user with id > $since, newest first.
     * Only the target user may read them — never anyone else.
     *
     * @return Collection<int, LiveEvent>
     */
    public function sinceForUser(int $since, User $viewer, int $limit = 50): Collection
    {
        return LiveEvent::query()
            ->where('target_user_id', $viewer->id)
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The latest global cursor (max id), or 0 when the log is empty.
     */
    public function latestCursor(): int
    {
        return (int) (LiveEvent::query()->max('id') ?? 0);
    }

    /**
     * Whether a viewer may see the given event.
     */
    public function visibleTo(?User $viewer, LiveEvent $event): bool
    {
        if ($this->isPublic($event->type)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        if ($viewer->isAdmin() || $viewer->isModerator()) {
            return true;
        }

        return $event->tournament_id !== null
            && $event->tournament !== null
            && $event->tournament->organizer_id === $viewer->id;
    }

    /**
     * Events with id > $since for a tournament (optionally unscoped when the
     * tournament is null), newest first, already visibility-filtered.
     *
     * @return Collection<int, LiveEvent>
     */
    public function since(int $since, ?Tournament $tournament, ?User $viewer, int $limit = 50): Collection
    {
        $events = LiveEvent::query()
            ->when($tournament !== null, fn ($q) => $q->where('tournament_id', $tournament->id))
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $events->filter(fn (LiveEvent $event) => $this->visibleTo($viewer, $event))->values();
    }

    /**
     * The earliest missing cursor for a viewer so staff-only events they
     * cannot see do not wedge their cursor (they simply do not receive those
     * ids). Pagination cursor for clients is handled in the controller via
     * the response `revision`.
     */
    public function snapshot(Tournament $tournament, ?User $viewer): array
    {
        return [
            'revision' => $this->latestCursor(),
            'events' => $this->since(0, $tournament, $viewer, 25),
        ];
    }

    /**
     * Whether a type is in the public allowlist.
     */
    public function isPublic(string $type): bool
    {
        return in_array($type, config('live.public_types', []), true);
    }
}
