<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use App\Services\TournamentParticipationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — real business flows emit live events without mutating their
 * Phase 01–11 state.
 */
class LiveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Live Tournament';
        $t->slug = $o['slug'] ?? ('live-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = $o['starts_at'] ?? now()->subHour();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'ready'): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    public function test_score_submission_emits_live_event_without_changing_score_state(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $score = app(ScoringService::class)->submitScore($match, $teamA, 12, 2);

        $this->assertSame('pending', $score->status);
        $this->assertSame(12, $score->kills);

        $event = LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->first();

        $this->assertNotNull($event);
        $this->assertSame($tournament->id, $event->tournament_id);
        $this->assertSame($teamA->name, $event->payload['team']);
        $this->assertSame(12, $event->payload['kills']);
    }

    public function test_match_completion_emits_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $result = app(MatchProgressionService::class)->complete($match, $teamA);

        $this->assertSame('completed', $result);
        $this->assertSame(GameMatch::STATUS_COMPLETED, $match->fresh()->status);

        $event = LiveEvent::where('type', LiveEvent::TYPE_MATCH_COMPLETED)->first();

        $this->assertNotNull($event);
        $this->assertSame($teamA->name, $event->payload['winner']);
    }

    public function test_dispute_and_resolution_emit_live_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $progression = app(MatchProgressionService::class);
        $progression->complete($match, $teamA);
        $progression->dispute($match);

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_DISPUTED)->exists());

        $progression->resolve($match, $teamA);

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_RESOLVED)->exists());
    }

    public function test_match_start_emits_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(MatchProgressionService::class)->start($match);

        $this->assertSame(GameMatch::STATUS_LIVE, $match->fresh()->status);
        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_STARTED)->exists());
    }

    public function test_check_in_emits_live_event_and_is_idempotent(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live', [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);
        $team = $this->makeTeam($tournament, $captain);

        $service = app(TournamentParticipationService::class);

        $this->assertSame('checked_in', $service->checkIn($tournament, $team, $captain));
        $this->assertSame('already', $service->checkIn($tournament, $team, $captain));

        // One live event, not two (idempotency).
        $this->assertSame(1, LiveEvent::where('type', LiveEvent::TYPE_TEAM_CHECKED_IN)->count());
        $this->assertNotNull($team->fresh()->checked_in_at);
    }

    public function test_team_registration_and_withdrawal_emit_events_via_http(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'open', ['starts_at' => now()->addDay()]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Live Feed Team',
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDLIVE1',
        ])->assertRedirect();

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_TEAM_REGISTERED)->exists());

        $team = Team::where('captain_id', $captain->id)->first();

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))->assertRedirect();

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_TEAM_WITHDRAWN)->exists());
        $this->assertSame(Team::STATUS_WITHDRAWN, $team->fresh()->status);
    }

    public function test_live_events_carry_no_sensitive_payload(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(ScoringService::class)->submitScore($match, $teamA, 12, 2);

        $event = LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->first();

        foreach (['password', 'token', 'secret', 'phone', 'game_uid', 'email', 'screenshot'] as $key) {
            $this->assertArrayNotHasKey($key, $event->payload);
        }
    }

    public function test_failed_score_submission_emits_no_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        try {
            app(ScoringService::class)->submitScore($match, $teamA, -1, 2);
            $this->fail('Expected negative kills to be rejected.');
        } catch (\DomainException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->count());
        $this->assertSame(0, Score::count());
    }
}
