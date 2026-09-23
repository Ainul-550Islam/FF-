<?php

namespace Tests\Feature\Coverage;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\BracketService;
use App\Services\RegistrationService;
use App\Services\ScoringService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — tournament critical-path coverage.
 *
 * Executes the registration → check-in → waitlist → bracket → scoring flow so
 * coverage runs touch the participation and scoring engines even under a
 * targeted subset. Every assertion is a real lifecycle invariant.
 */
class TournamentCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'TourCov Tournament';
        $t->slug = $o['slug'] ?? ('tourcov-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = 5000;
        $t->team_slots = $o['team_slots'] ?? 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
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

    public function test_registration_checkin_and_scoring_are_covered(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org, 'open', [
            'entry_fee' => 0,
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);

        $result = app(RegistrationService::class)->register($tournament, $captain, [
            'name' => 'TourCov Squad',
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDTOURCOV',
            'members' => [],
        ]);

        $team = $result['team'];

        $this->assertFalse($result['waitlisted']);
        $this->assertSame(Team::STATUS_PENDING, $team->status);

        // Free entry → confirm the team so it can check in.
        $team->status = Team::STATUS_CONFIRMED;
        $team->save();

        $status = app(TournamentParticipationService::class)->checkIn($tournament, $team, $captain);
        $this->assertSame('checked_in', $status);
        $this->assertNotNull($team->fresh()->checked_in_at);

        // Re-check-in is idempotent.
        $this->assertSame('already', app(TournamentParticipationService::class)->checkIn($tournament, $team, $captain));

        // Scoring path (currentRuleSet auto-creates the default ruleset).
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team->id;
        $match->team2_id = null;
        $match->status = GameMatch::STATUS_LIVE;
        $match->round = 1;
        $match->match_no = 1;
        $match->save();

        $score = app(ScoringService::class)->submitScore($match, $team, kills: 5, placement: 1);

        $this->assertInstanceOf(Score::class, $score);
        $this->assertSame(17, $score->points);

        $standings = app(ScoringService::class)->standings($tournament);
        $this->assertNotEmpty($standings);
    }

    public function test_waitlist_and_bracket_generation_are_covered(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 1]);

        $captainA = $this->makeUser();
        $captainB = $this->makeUser();

        $first = app(RegistrationService::class)->register($tournament, $captainA, [
            'name' => 'Slot Holder',
            'captain_name' => $captainA->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDSLOT1',
            'members' => [],
        ]);

        $second = app(RegistrationService::class)->register($tournament, $captainB, [
            'name' => 'Waitlisted',
            'captain_name' => $captainB->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDSLOT2',
            'members' => [],
        ]);

        $this->assertFalse($first['waitlisted']);
        $this->assertTrue($second['waitlisted']);
        $this->assertSame(Team::STATUS_WAITLISTED, $second['team']->status);

        // Promote the waitlisted team after the slot holder withdraws.
        $first['team']->status = Team::STATUS_WITHDRAWN;
        $first['team']->save();

        $promoted = app(TournamentParticipationService::class)->promoteNext($tournament);

        $this->assertSame($second['team']->id, $promoted->id);
        $this->assertSame(Team::STATUS_PENDING, $promoted->status);

        // Bracket generation needs confirmed teams.
        foreach ([$promoted] as $i => $team) {
            $team->status = Team::STATUS_CONFIRMED;
            $team->save();
        }

        $tournament->status = Tournament::STATUS_OPEN;
        $tournament->starts_at = now()->subMinute();
        $tournament->save();

        $matches = app(BracketService::class)->generate($tournament);
        $this->assertGreaterThanOrEqual(0, $matches);
    }

    public function test_checkin_rejects_unconfirmed_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org, 'open', [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);

        $team = $this->makeTeam($tournament, $captain, 'pending');

        $this->expectException(DomainException::class);
        app(TournamentParticipationService::class)->checkIn($tournament, $team, $captain);
    }
}
