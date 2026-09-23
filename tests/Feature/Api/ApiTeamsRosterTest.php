<?php

namespace Tests\Feature\Api;

use App\Models\TeamMember;

/**
 * Phase 15 — team & roster APIs with authorization (TeamPolicy) and IDOR
 * protection.
 */
class ApiTeamsRosterTest extends ApiTestCase
{
    public function test_my_teams_lists_only_own_teams(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $mine = $this->makeTeam($tournament, $captain, 'pending', 'UIDMINE001');
        $this->makeTeam($tournament, $other, 'pending', 'UIDOTHER001');

        $res = $this->asUser($captain, ['teams:read'])->getJson('/api/v1/me/teams');
        $res->assertStatus(200);
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
    }

    public function test_team_view_and_phone_privacy(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDPRIV001');

        // A stranger cannot view the team at all (TeamPolicy::view is
        // captain/organizer/staff only), so the phone can never leak.
        $this->asUser($stranger, ['teams:read'])->getJson('/api/v1/teams/'.$team->id)->assertStatus(403);

        $captainView = $this->asUser($captain, ['teams:read'])->getJson('/api/v1/teams/'.$team->id);
        $captainView->assertStatus(200);
        $this->assertSame('01700000000', $captainView->json('data.phone'));
    }

    public function test_team_profile_update_by_non_captain_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDEDIT001');

        $payload = [
            'name' => 'Hijacked',
            'captain_name' => 'Hijacker',
            'phone' => '01799999999',
            'game_uid' => 'UIDHIJACK1',
        ];

        $this->asUser($stranger, ['teams:write'])->patchJson('/api/v1/teams/'.$team->id, $payload)->assertStatus(403);

        $this->asUser($captain, ['teams:write'])->patchJson('/api/v1/teams/'.$team->id, $payload)->assertStatus(200);
        $this->assertSame('Hijacked', $team->fresh()->name);
    }

    public function test_roster_add_remove_with_authorization(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDROST001');

        $add = ['player_name' => 'Rookie', 'game_uid' => 'UIDROOK1E'];

        $this->asUser($stranger, ['roster:write'])->postJson('/api/v1/teams/'.$team->id.'/roster', $add)->assertStatus(403);

        $created = $this->asUser($captain, ['roster:write'])->postJson('/api/v1/teams/'.$team->id.'/roster', $add);
        $created->assertStatus(201);
        $memberId = $created->json('data.id');

        $this->assertSame(1, TeamMember::where('team_id', $team->id)->count());

        $this->asUser($stranger, ['roster:write'])->deleteJson('/api/v1/teams/'.$team->id.'/roster/'.$memberId)->assertStatus(403);
        $this->asUser($captain, ['roster:write'])->deleteJson('/api/v1/teams/'.$team->id.'/roster/'.$memberId)->assertStatus(204);

        $this->assertSame(0, TeamMember::where('team_id', $team->id)->count());
    }

    public function test_roster_member_from_other_team_is_not_found(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captain, 'pending', 'UIDAAA0001');
        $teamB = $this->makeTeam($tournament, null, 'pending', 'UIDBBB0001');

        $member = new TeamMember();
        $member->team_id = $teamB->id;
        $member->player_name = 'Other';
        $member->game_uid = 'UIDOTHER2';
        $member->save();

        $this->asUser($captain, ['roster:write'])
            ->deleteJson('/api/v1/teams/'.$teamA->id.'/roster/'.$member->id)
            ->assertStatus(404);
    }

    public function test_withdraw_by_non_captain_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDWDRAW1');

        $this->asUser($stranger, ['teams:write'])->postJson('/api/v1/teams/'.$team->id.'/withdraw')->assertStatus(403);

        $this->asUser($captain, ['teams:write'])->postJson('/api/v1/teams/'.$team->id.'/withdraw')->assertStatus(200);
        $this->assertSame('withdrawn', $team->fresh()->status);
    }
}
