<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — HTTP endpoints for live polling, SSE and the unread badge.
 */
class LiveHttpTest extends TestCase
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
        $t->slug = 'live-' . Str::random(8);
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

    public function test_guest_can_poll_public_live_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->getJson(route('tournaments.live', $tournament));

        $response->assertOk()
            ->assertJsonStructure(['revision', 'since', 'count', 'events'])
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_MATCH_COMPLETED)
            ->assertJsonPath('events.0.payload.winner', 'A');

        // The actor id is never exposed.
        $this->assertArrayNotHasKey('actor_user_id', $response->json('events.0'));
    }

    public function test_guest_live_feed_excludes_staff_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);
        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->getJson(route('tournaments.live', $tournament));

        $response->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_MATCH_COMPLETED);
    }

    public function test_organizer_sees_staff_events_in_their_tournament(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);

        $this->actingAs($organizer)
            ->getJson(route('tournaments.live', $tournament))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_DISPUTE_OPENED);
    }

    public function test_since_cursor_returns_only_newer_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);
        $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);

        $response = $this->getJson(route('tournaments.live', [$tournament, 'since' => $first->id]));

        $response->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_TEAM_CHECKED_IN)
            ->assertJsonPath('revision', $this->service()->latestCursor());
    }

    public function test_unread_endpoint_requires_auth(): void
    {
        $this->getJson(route('notifications.unread'))->assertStatus(401);
    }

    public function test_unread_endpoint_returns_the_badge_count(): void
    {
        $user = $this->makeUser();
        $service = app(NotificationService::class);

        $service->send($user, 'system', 'A', '1');
        $service->send($user, 'system', 'B', '2');

        $this->actingAs($user)
            ->getJson(route('notifications.unread'))
            ->assertOk()
            ->assertJson(['unread' => 2]);
    }

    public function test_sse_stream_responds_with_event_stream(): void
    {
        config(['live.stream.max_duration' => 0]);
        config(['live.stream.heartbeat' => 1]);

        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->get(route('tournaments.stream', $tournament));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: live', $content);
        $this->assertStringContainsString(LiveEvent::TYPE_MATCH_COMPLETED, $content);
    }

    public function test_live_page_renders_the_feed_panel(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('live-feed', false);
    }
}
