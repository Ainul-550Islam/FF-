<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 03 — Team + roster management & competitive team integrity.
 */
class TeamRosterTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (sensitive fields set explicitly, mirroring production)
    // ------------------------------------------------------------------

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $overrides = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $overrides['name'] ?? 'Roster Tournament';
        $t->slug = $overrides['slug'] ?? ('roster-' . Str::random(8));
        $t->game_mode = $overrides['game_mode'] ?? 'squad';
        $t->map = $overrides['map'] ?? 'Bermuda';
        $t->entry_fee = $overrides['entry_fee'] ?? 100;
        $t->prize_pool = $overrides['prize_pool'] ?? 5000;
        $t->team_slots = $overrides['team_slots'] ?? 8;
        $t->team_size = $overrides['team_size'] ?? 4;
        $t->rules = $overrides['rules'] ?? null;
        $t->starts_at = $overrides['starts_at'] ?? now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending', string $uid = 'UIDCAPTAIN'): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = $uid;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeMember(Team $team, string $name = 'Player', string $uid = 'UIDMEMBER'): TeamMember
    {
        $m = new TeamMember();
        $m->team_id = $team->id;
        $m->player_name = $name;
        $m->game_uid = $uid;
        $m->save();

        return $m;
    }

    protected function validRegistrationPayload(string $name = 'Test Squad', string $uid = 'UID123456'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Who may manage a team
    // ------------------------------------------------------------------

    public function test_guest_cannot_manage_a_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->get(route('teams.show', [$tournament, $team]))->assertRedirect(route('login'));

        $this->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Hacker', 'game_uid' => 'UIDHACK',
        ])->assertRedirect(route('login'));
    }

    public function test_player_cannot_manage_another_users_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $intruder = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($intruder)->get(route('teams.show', [$tournament, $team]))->assertStatus(403);

        $this->actingAs($intruder)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Sneak', 'game_uid' => 'UIDSNEAK',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    public function test_captain_can_manage_own_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'SquadMate',
            'game_uid' => 'uidmate99', // lowercase on purpose — must be normalized
        ])->assertSessionHas('success');

        $this->assertSame(1, $team->members()->count());
        $this->assertSame('UIDMATE99', $team->members()->first()->game_uid);
    }

    public function test_admin_can_perform_authorized_team_management(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($admin)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Admin Add',
            'game_uid' => 'UIDADMIN',
        ])->assertSessionHas('success');
        $this->assertSame(1, $team->members()->count());

        $member = $team->members()->first();
        $this->actingAs($admin)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertSessionHas('success');
        $this->assertSame(0, $team->members()->count());
    }

    public function test_organizer_permissions_remain_compatible(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // Organizers can view a team (they manage the tournament)...
        $this->actingAs($org)->get(route('teams.show', [$tournament, $team]))->assertOk();

        // ...but cannot edit its roster (competitive integrity).
        $this->actingAs($org)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Organizer Add', 'game_uid' => 'UIDORG',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 2. Roster size
    // ------------------------------------------------------------------

    public function test_team_cannot_exceed_tournament_team_size(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]); // captain + 3 members
        $team = $this->makeTeam($tournament, $captain);

        foreach (['UIDA001', 'UIDA002', 'UIDA003'] as $i => $uid) {
            $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
                'player_name' => 'Player ' . $i,
                'game_uid' => $uid,
            ])->assertSessionHas('success');
        }

        $this->assertSame(3, $team->members()->count());

        // 4th member must be rejected.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Overflow',
            'game_uid' => 'UIDOVER',
        ])->assertSessionHas('error');

        $this->assertSame(3, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 3. Duplicate player prevention
    // ------------------------------------------------------------------

    public function test_duplicate_member_cannot_be_added(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Dup One',
            'game_uid' => 'uiddup01',
        ])->assertSessionHas('success');

        // Same UID, different case + different name → rejected.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Dup Two',
            'game_uid' => 'UIDDUP01',
        ])->assertSessionHas('error');

        $this->assertSame(1, $team->members()->count());
    }

    public function test_member_cannot_share_captain_uid(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDCAPTAIN');

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Clone',
            'game_uid' => 'uidcaptain',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team->members()->count());
    }

    public function test_database_enforces_unique_member_per_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->makeMember($team, 'First', 'UIDDUPDB');

        $dup = new TeamMember();
        $dup->team_id = $team->id;
        $dup->player_name = 'Second';
        $dup->game_uid = 'UIDDUPDB';

        $threw = false;
        try {
            $dup->save();
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the team_members_team_uid_unique constraint to reject a duplicate member.');
    }

    // ------------------------------------------------------------------
    // 4. Unauthorized mutations
    // ------------------------------------------------------------------

    public function test_unauthorized_user_cannot_add_member(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($stranger)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Stranger',
            'game_uid' => 'UIDSTRA',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    public function test_unauthorized_user_cannot_remove_member(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);
        $member = $this->makeMember($team, 'Keep Me', 'UIDKEEP');

        $this->actingAs($stranger)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertStatus(403);

        $this->assertSame(1, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 5. Cross-tournament protection
    // ------------------------------------------------------------------

    public function test_team_from_tournament_a_cannot_be_modified_via_tournament_b(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', ['name' => 'Tournament A']);
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Tournament B']);
        $teamA = $this->makeTeam($tournamentA, $captain);

        $this->actingAs($captain)->get(route('teams.show', [$tournamentB, $teamA]))->assertStatus(404);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournamentB, $teamA]), [
            'player_name' => 'X', 'game_uid' => 'UIDXXXX',
        ])->assertStatus(404);

        $this->assertSame(0, $teamA->members()->count());
    }

    // ------------------------------------------------------------------
    // 6. Cross-team roster abuse (same tournament)
    // ------------------------------------------------------------------

    public function test_player_cannot_join_two_teams_in_same_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $captain1 = $this->makeUser('player');
        $captain2 = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        // Team One: captain1 with UID UIDAAAA.
        $team1 = $this->makeTeam($tournament, $captain1, 'pending', 'UIDAAAA');
        // Team Two: captain2 with UID UIDBBBB.
        $team2 = $this->makeTeam($tournament, $captain2, 'pending', 'UIDBBBB');

        // captain2 tries to add UIDAAAA (already team one's captain) → rejected.
        $this->actingAs($captain2)->post(route('teams.members.store', [$tournament, $team2]), [
            'player_name' => 'Ring In',
            'game_uid' => 'UIDAAAA',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team2->members()->count());

        // Registration path: a new captain registering a team whose member UID
        // already belongs to team one must also be rejected.
        $captain3 = $this->makeUser('player');
        $this->actingAs($captain3)->post(route('teams.store', $tournament), [
            'name' => 'Team Three',
            'captain_name' => 'Cap3',
            'phone' => '01700000000',
            'game_uid' => 'UIDCCCC',
            'members' => [['player_name' => 'Clash', 'game_uid' => 'UIDAAAA']],
        ])->assertSessionHas('error');

        $this->assertSame(0, Team::where('name', 'Team Three')->count());
    }

    public function test_player_can_participate_in_different_tournaments(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', ['name' => 'Tournament A']);
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Tournament B']);

        $this->actingAs($captain)->post(route('teams.store', $tournamentA), [
            'name' => 'Team A', 'captain_name' => 'Cap', 'phone' => '01700000000',
            'game_uid' => 'UIDAAAA',
            'members' => [['player_name' => 'Shared', 'game_uid' => 'UIDSHARED']],
        ])->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournamentB), [
            'name' => 'Team B', 'captain_name' => 'Cap', 'phone' => '01700000000',
            'game_uid' => 'UIDBBBB',
            'members' => [['player_name' => 'Shared', 'game_uid' => 'UIDSHARED']],
        ])->assertRedirect();

        $this->assertSame(1, Team::where('name', 'Team A')->count());
        $this->assertSame(1, Team::where('name', 'Team B')->count());
        $this->assertSame(1, Team::where('name', 'Team A')->first()->members()->count());
        $this->assertSame(1, Team::where('name', 'Team B')->first()->members()->count());
    }

    // ------------------------------------------------------------------
    // 7. Roster lock
    // ------------------------------------------------------------------

    public function test_roster_cannot_be_modified_after_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed'); // registration closed → locked
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Late',
            'game_uid' => 'UIDLATE',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team->members()->count());
    }

    public function test_captain_cannot_bypass_roster_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        $team = $this->makeTeam($tournament, $captain);
        $member = $this->makeMember($team, 'Locked In', 'UIDLOCK');

        $this->actingAs($captain)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertSessionHas('error');

        $this->assertSame(1, $team->members()->count());
    }

    public function test_profile_update_blocked_after_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UIDCAPTAIN',
        ])->assertSessionHas('error');

        $this->assertNotSame('Renamed', $team->fresh()->name);
    }

    // ------------------------------------------------------------------
    // 8. UID validation
    // ------------------------------------------------------------------

    public function test_invalid_free_fire_uid_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // Invalid format in addMember.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Bad UID',
            'game_uid' => 'bad uid!',
        ])->assertSessionHasErrors('game_uid');

        // Too short at registration (captain UID).
        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Bad UID Team', 'abc'))
            ->assertSessionHasErrors('game_uid');

        $this->assertSame(0, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 9. Registration integrity
    // ------------------------------------------------------------------

    public function test_valid_roster_can_be_submitted(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Full Squad',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'uidcap777',
            'members' => [
                ['player_name' => 'Mate 1', 'game_uid' => 'uidm1111'],
                ['player_name' => 'Mate 2', 'game_uid' => 'uidm2222'],
            ],
        ])->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Full Squad')->firstOrFail()]));

        $team = Team::where('name', 'Full Squad')->firstOrFail();
        $this->assertSame('pending', $team->status);
        $this->assertSame('UIDCAP777', $team->game_uid); // normalized
        $this->assertSame(2, $team->members()->count());
        $this->assertTrue($team->members()->pluck('game_uid')->contains('UIDM1111'));
        $this->assertTrue($team->members()->pluck('game_uid')->contains('UIDM2222'));
    }

    public function test_registration_with_captain_only_is_allowed(): void
    {
        // Full roster is NOT required by existing rules — a captain may
        // register alone and complete the roster later (while open).
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Solo Entry', 'UIDSOLO1'))
            ->assertRedirect();

        $team = Team::where('name', 'Solo Entry')->firstOrFail();
        $this->assertSame(0, $team->members()->count());
        $this->assertSame(1, $team->rosterSize());
    }

    public function test_captain_can_update_team_profile(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDCAPTAIN');

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed Squad',
            'captain_name' => 'New Captain Name',
            'phone' => '01800000000',
            'game_uid' => 'UIDCAPTAIN',
        ])->assertSessionHas('success');

        $this->assertSame('Renamed Squad', $team->fresh()->name);
        $this->assertSame('New Captain Name', $team->fresh()->captain_name);
        $this->assertSame('01800000000', $team->fresh()->phone);
    }
}
