<?php

namespace Tests\Feature\Api;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Score;

/**
 * Phase 15 — read-only discovery surfaces: live feeds, leaderboards,
 * rankings, bracket and match lists.
 */
class ApiReadSurfacesTest extends ApiTestCase
{
    public function test_live_leaderboard_and_bracket_surfaces(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $player, 'confirmed', 'UIDLIVE01');

        $event = new LiveEvent();
        $event->tournament_id = $tournament->id;
        $event->actor_user_id = $player->id;
        $event->type = LiveEvent::TYPE_TEAM_REGISTERED;
        $event->payload = ['team' => $team->name];
        $event->created_at = now();
        $event->save();

        $token = $this->tokenFor($player, ['*']);
        $this->authForget();

        $surfaces = [
            ['GET', '/api/v1/tournaments/'.$tournament->slug.'/live', 'with token'],
            ['GET', '/api/v1/me/live', 'with token'],
            ['GET', '/api/v1/leaderboards', 'guest'],
            ['GET', '/api/v1/leaderboards/'.$tournament->slug, 'guest'],
            ['GET', '/api/v1/players/'.$player->id.'/ranking', 'guest'],
            ['GET', '/api/v1/tournaments/'.$tournament->slug.'/leaderboard', 'guest'],
            ['GET', '/api/v1/tournaments/'.$tournament->slug.'/bracket', 'guest'],
            ['GET', '/api/v1/tournaments/'.$tournament->slug.'/matches', 'guest'],
        ];

        foreach ($surfaces as [$method, $uri, $auth]) {
            $req = $auth === 'with token' ? $this->withToken($token) : $this;

            $status = $method === 'GET'
                ? $req->getJson($uri)->getStatusCode()
                : $req->postJson($uri, [])->getStatusCode();

            $this->assertSame(200, $status, "{$method} {$uri} ({$auth})");
        }
    }

    /**
     * Regression: ScoringService::standings() emits stdClass rows, and the
     * API layer used to read them as arrays — so any tournament WITH scores
     * 500'd on every leaderboard surface. This test seeds a real score and
     * asserts every surface serializes the non-empty standings correctly.
     */
    public function test_leaderboard_endpoints_serialize_non_empty_standings(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $player, 'confirmed', 'UIDLB0001');

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->team2_id = null;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->save();

        $score = new Score();
        $score->match_id = $match->id;
        $score->team_id = $team->id;
        $score->kills = 5;
        $score->placement = 1;
        $score->points = 42;
        $score->placement_points = 30;
        $score->kill_points = 12;
        $score->status = 'verified';
        $score->save();

        $this->getJson('/api/v1/leaderboards')->assertOk();

        $this->getJson('/api/v1/leaderboards/'.$tournament->slug)
            ->assertOk()
            ->assertJsonPath('data.0.team_id', $team->id)
            ->assertJsonPath('data.0.points', 42);

        $this->getJson('/api/v1/tournaments/'.$tournament->slug.'/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.0.team_id', $team->id)
            ->assertJsonPath('data.0.points', 42);

        $this->getJson('/api/v1/players/'.$player->id.'/ranking')
            ->assertOk()
            ->assertJsonPath('data.rankings.0.team_id', $team->id)
            ->assertJsonPath('data.rankings.0.points', 42);
    }
}
