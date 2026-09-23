<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LiveEventService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — live event log: append-only semantics, visibility enforcement
 * and cursor pagination.
 */
class LiveEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Live Tournament';
        $t->slug = 'live-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->subHour();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function service(): LiveEventService
    {
        return app(LiveEventService::class);
    }

    public function test_record_is_append_only_with_monotonic_ids(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);
        $second = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame($tournament->id, $first->tournament_id);
        $this->assertSame(['team' => 'A'], $first->payload);
    }

    public function test_latest_cursor_tracks_max_id(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->assertSame(0, $this->service()->latestCursor());

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);

        $this->assertSame((int) LiveEvent::max('id'), $this->service()->latestCursor());
    }

    public function test_record_quietly_never_throws(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        // A normal record succeeds.
        $event = $this->service()->recordQuietly($tournament, null, LiveEvent::TYPE_TEAM_REGISTERED, ['team' => 'B']);
        $this->assertNotNull($event);
    }

    public function test_public_types_are_visible_to_guests(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $events = $this->service()->since(0, $tournament, null, 50);

        $this->assertCount(1, $events);
        $this->assertSame(LiveEvent::TYPE_MATCH_COMPLETED, $events->first()->type);
    }

    public function test_staff_only_events_are_hidden_from_guests(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);
        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $guestEvents = $this->service()->since(0, $tournament, null, 50);

        $this->assertCount(1, $guestEvents);
        $this->assertSame(LiveEvent::TYPE_MATCH_COMPLETED, $guestEvents->first()->type);
    }

    public function test_organizer_sees_their_tournaments_staff_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $events = $this->service()->since(0, $tournament, $organizer, 50);

        $this->assertCount(1, $events);
    }

    public function test_other_organizer_cannot_see_staff_events(): void
    {
        $owner = $this->makeUser('organizer');
        $intruder = $this->makeUser('organizer');
        $tournament = $this->makeTournament($owner);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $this->assertCount(0, $this->service()->since(0, $tournament, $intruder, 50));
    }

    public function test_moderator_and_admin_see_all_staff_events(): void
    {
        $owner = $this->makeUser('organizer');
        $tournament = $this->makeTournament($owner);
        $moderator = $this->makeUser('moderator');
        $admin = $this->makeUser('admin');

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $this->assertCount(1, $this->service()->since(0, $tournament, $moderator, 50));
        $this->assertCount(1, $this->service()->since(0, $tournament, $admin, 50));
    }

    public function test_cursor_filters_newer_events_only(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);
        $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);

        $events = $this->service()->since($first->id, $tournament, null, 50);

        $this->assertCount(1, $events);
        $this->assertSame(LiveEvent::TYPE_TEAM_CHECKED_IN, $events->first()->type);
    }

    public function test_snapshot_returns_revision_and_recent_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $snapshot = $this->service()->snapshot($tournament, null);

        $this->assertArrayHasKey('revision', $snapshot);
        $this->assertArrayHasKey('events', $snapshot);
        $this->assertSame($this->service()->latestCursor(), $snapshot['revision']);
        $this->assertCount(1, $snapshot['events']);
    }

    public function test_mass_assignment_is_guarded(): void
    {
        $event = new LiveEvent();

        try {
            $event->fill([
                'type' => 'match.completed',
                'payload' => ['winner' => 'X'],
                'tournament_id' => 1,
            ]);
            $this->fail('LiveEvent accepted mass assignment.');
        } catch (MassAssignmentException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
