<?php

namespace Tests\Feature\Api;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Services\ScoringService;

/**
 * Phase 15 — match reads and score submission: participant auth, duplicate
 * protection, placement claims, server-derived scoring, no client state
 * mutation.
 */
class ApiMatchesScoresTest extends ApiTestCase
{
    protected function makeMatch($tournament, $team1, $team2, string $status = 'live'): GameMatch
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team1?->id;
        $match->team2_id = $team2?->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = GameMatch::BRACKET_WINNERS;
        $match->status = $status;
        $match->save();

        return $match;
    }

    protected function makeRuleSet($tournament): ScoringRule
    {
        return app(ScoringService::class)->createVersion($tournament, [
            'name' => 'v1',
            'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
    }

    public function test_match_show_exposes_scores(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, null, 'confirmed', 'UIDM01A1');
        $b = $this->makeTeam($t, null, 'confirmed', 'UIDM01B1');
        $match = $this->makeMatch($t, $a, $b, 'completed');

        $res = $this->getJson('/api/v1/matches/'.$match->id);
        $res->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }

    public function test_score_submission_by_non_participant_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $intruder = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS01A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS01B1');
        $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $this->asUser($intruder, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $a->id,
                'kills' => 5,
                'placement' => 1,
            ])
            ->assertStatus(403);
    }

    public function test_score_submission_computes_points_server_side(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS02A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS02B1');
        $rule = $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $res = $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $a->id,
                'kills' => 5,
                'placement' => 1,
            ]);

        $res->assertStatus(201);
        // placement_points=12, kill_points=5 → total 17, derived server-side.
        $this->assertSame(12, $res->json('data.placement_points'));
        $this->assertSame(5, $res->json('data.kill_points'));
        $this->assertSame(17, $res->json('data.points'));

        // A client-supplied authoritative point total is not accepted (422).
        $this->asUser($captainB, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $b->id,
                'kills' => 1,
                'placement' => 2,
                'points' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_score_and_placement_claims_are_rejected(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS03A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS03B1');
        $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $a->id, 'kills' => 5, 'placement' => 1,
            ])->assertStatus(201);

        // Duplicate team score.
        $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $a->id, 'kills' => 5, 'placement' => 2,
            ])->assertStatus(409);

        // Placement already claimed.
        $this->asUser($captainB, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $b->id, 'kills' => 1, 'placement' => 1,
            ])->assertStatus(409);
    }

    public function test_no_client_match_state_mutation_endpoints_exist(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, null, 'confirmed', 'UIDM02A1');
        $b = $this->makeTeam($t, null, 'confirmed', 'UIDM02B1');
        $match = $this->makeMatch($t, $a, $b, 'live');
        $player = $this->user();

        // PATCHing a match (state mutation) does not exist for clients.
        $this->asUser($player, ['scores:submit'])
            ->patchJson('/api/v1/matches/'.$match->id, ['status' => 'completed'])
            ->assertStatus(405);
    }
}
