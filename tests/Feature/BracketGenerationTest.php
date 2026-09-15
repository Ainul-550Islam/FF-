<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\BracketService;
use App\Services\MatchProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 05 — bracket generation tests.
 *
 * Covers single-elimination fields of 1..16 teams (byes), deterministic
 * seeding, idempotent regeneration, explicit dependency-graph advancement,
 * the grand final, double-elimination structure and progression, and the
 * exclusion of withdrawn/unchecked-in teams.
 */
class BracketGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'closed', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Bracket Tournament';
        $t->slug = $o['slug'] ?? ('bracket-'.Str::random(8));
        $t->game_mode = $o['game_mode'] ?? 'squad';
        $t->map = $o['map'] ?? 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = $o['team_slots'] ?? 16;
        $t->team_size = $o['team_size'] ?? 4;
        $t->rules = $o['rules'] ?? null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->format = $o['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(
        Tournament $tournament,
        ?User $captain = null,
        string $status = 'confirmed',
        ?int $seed = null,
        bool $checkedIn = false,
    ): Team {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        if ($seed !== null) {
            $team->seed = $seed;
        }
        if ($checkedIn) {
            $team->checked_in_at = now();
        }
        $team->save();

        return $team;
    }

    protected function bracket(): BracketService
    {
        return app(BracketService::class);
    }

    protected function progression(): MatchProgressionService
    {
        return app(MatchProgressionService::class);
    }

    // ------------------------------------------------------------------
    // Single elimination — field sizes & byes
    // ------------------------------------------------------------------

    public function test_single_team_cannot_form_a_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(0, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_two_teams_form_a_single_final(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(1, $this->bracket()->generate($tournament));

        $final = GameMatch::where('tournament_id', $tournament->id)->firstOrFail();
        $this->assertSame(1, $final->round);
        $this->assertSame(1, $final->match_no);
        $this->assertSame($t1->id, $final->team1_id);
        $this->assertSame($t2->id, $final->team2_id);
        $this->assertSame('ready', $final->status);
    }

    public function test_three_teams_produce_one_bye(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(3, $this->bracket()->generate($tournament));

        $byes = GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->get();
        $this->assertCount(1, $byes);
        $this->assertSame($t3->id, $byes->first()->winner_team_id);

        // The bye team is auto-advanced into the round-2 slot.
        $round2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->firstOrFail();
        $this->assertTrue(in_array($t3->id, [$round2->team1_id, $round2->team2_id], true));
    }

    public function test_four_teams_pair_sequentially(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(3, $this->bracket()->generate($tournament));

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $this->assertSame($t1->id, $m1->team1_id);
        $this->assertSame($t2->id, $m1->team2_id);
        $this->assertSame($t3->id, $m2->team1_id);
        $this->assertSame($t4->id, $m2->team2_id);
    }

    public function test_five_teams_produce_three_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 5; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());

        // No round-1 match may be empty.
        $empty = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)
            ->whereNull('team1_id')->whereNull('team2_id')->count();
        $this->assertSame(0, $empty);
    }

    public function test_six_teams_produce_two_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
    }

    public function test_seven_teams_produce_one_bye_and_seven_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
        $this->assertSame(8, (int) $tournament->fresh()->bracket_size);
    }

    public function test_eight_teams_produce_seven_matches_no_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
        $this->assertSame(4, GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->count());
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('round', 3)->count());
    }

    public function test_sixteen_teams_produce_fifteen_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['team_slots' => 16]);
        for ($i = 0; $i < 16; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(15, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
    }

    // ------------------------------------------------------------------
    // Seeding, idempotency
    // ------------------------------------------------------------------

    public function test_seeding_follows_seed_then_id_order_and_normalises_seeds(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        // Created out of seed order.
        $a = $this->makeTeam($tournament, null, 'confirmed', 4);
        $b = $this->makeTeam($tournament, null, 'confirmed', 2);
        $c = $this->makeTeam($tournament, null, 'confirmed', 1);
        $d = $this->makeTeam($tournament, null, 'confirmed', 3);

        $this->bracket()->generate($tournament);

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        // Rank order: c(1), b(2), d(3), a(4) → 1v2 = c vs b, 3v4 = d vs a.
        $this->assertSame($c->id, $m1->team1_id);
        $this->assertSame($b->id, $m1->team2_id);

        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $this->assertSame($d->id, $m2->team1_id);
        $this->assertSame($a->id, $m2->team2_id);

        // Seeds normalised to the deterministic rank.
        $this->assertSame(1, (int) $c->fresh()->seed);
        $this->assertSame(2, (int) $b->fresh()->seed);
        $this->assertSame(3, (int) $d->fresh()->seed);
        $this->assertSame(4, (int) $a->fresh()->seed);
    }

    public function test_regeneration_is_idempotent_and_deterministic(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 5; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $service = $this->bracket();

        $first = $service->generate($tournament);
        $snapshot1 = GameMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_no')
            ->get(['bracket', 'round', 'match_no', 'team1_id', 'team2_id', 'winner_team_id', 'status', 'next_slot', 'loser_slot'])
            ->map->getAttributes()
            ->all();

        $second = $service->generate($tournament);
        $snapshot2 = GameMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_no')
            ->get(['bracket', 'round', 'match_no', 'team1_id', 'team2_id', 'winner_team_id', 'status', 'next_slot', 'loser_slot'])
            ->map->getAttributes()
            ->all();

        $this->assertSame($first, $second);
        $this->assertSame($snapshot1, $snapshot2);
    }

    // ------------------------------------------------------------------
    // Exclusion of ineligible teams
    // ------------------------------------------------------------------

    public function test_withdrawn_and_unchecked_in_teams_are_excluded(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', [
            'check_in_starts_at' => now()->subHours(2),
            'check_in_ends_at' => now()->subHour(),
        ]);

        $eligible = [];
        for ($i = 0; $i < 4; $i++) {
            $eligible[] = $this->makeTeam($tournament, null, 'confirmed', null, true)->id;
        }
        $withdrawn = $this->makeTeam($tournament, null, 'withdrawn', null, true);
        $unchecked = $this->makeTeam($tournament, null, 'confirmed'); // confirmed but not checked in

        $this->bracket()->generate($tournament);

        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($eligible);
        sort($participantIds);
        $this->assertSame($eligible, $participantIds);
        $this->assertNotContains($withdrawn->id, $participantIds);
        $this->assertNotContains($unchecked->id, $participantIds);
    }

    // ------------------------------------------------------------------
    // Advancement through the dependency graph (single elim)
    // ------------------------------------------------------------------

    public function test_winner_advances_through_explicit_links_to_final(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->bracket()->generate($tournament);
        $progression = $this->progression();

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $final = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->where('match_no', 1)->firstOrFail();

        // Explicit dependency links exist (not arithmetic).
        $this->assertSame($final->id, $m1->next_match_id);
        $this->assertSame($final->id, $m2->next_match_id);

        $progression->complete($m1, $t1);
        $this->assertSame($t1->id, $final->fresh()->team1_id);
        $this->assertNull($final->fresh()->team2_id);
        $this->assertSame('pending', $final->fresh()->status);

        $progression->complete($m2, $t4);
        $this->assertSame($t4->id, $final->fresh()->team2_id);
        $this->assertSame('ready', $final->fresh()->status);

        $final->refresh();
        $progression->complete($final, $t4);
        $this->assertSame('completed', $final->fresh()->status);
        $this->assertSame($t4->id, $final->fresh()->winner_team_id);
        // The grand final has no further destination.
        $this->assertNull($final->fresh()->next_match_id);
    }

    // ------------------------------------------------------------------
    // Double elimination
    // ------------------------------------------------------------------

    public function test_double_elim_requires_power_of_two_field(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(0, $this->bracket()->generate($tournament));
    }

    public function test_double_elim_four_team_structure(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        for ($i = 0; $i < 4; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(6, $this->bracket()->generate($tournament));

        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->count());
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->count());
    }

    public function test_double_elim_eight_team_structure(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', [
            'format' => Tournament::FORMAT_DOUBLE_ELIM,
            'team_slots' => 8,
        ]);
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(14, $this->bracket()->generate($tournament));

        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->count());
        $this->assertSame(6, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->count());
    }

    public function test_double_elim_winner_advances_and_loser_drops(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->bracket()->generate($tournament);
        $progression = $this->progression();

        $w1m1 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 1)->where('match_no', 1)->firstOrFail(); // t1 vs t2
        $w1m2 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 1)->where('match_no', 2)->firstOrFail(); // t3 vs t4
        $wbFinal = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 2)->firstOrFail();
        $lb1 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->where('round', 1)->firstOrFail();
        $lb2 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->where('round', 2)->firstOrFail();
        $gf = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->firstOrFail();

        // WB round-1 match 1: t1 wins → t1 to WB final, t2 drops to LB1 slot 1.
        $progression->complete($w1m1, $t1);
        $this->assertSame($t1->id, $wbFinal->fresh()->team1_id);
        $this->assertSame($t2->id, $lb1->fresh()->team1_id);

        // WB round-1 match 2: t4 wins → t4 to WB final, t3 drops to LB1 slot 2.
        $progression->complete($w1m2, $t4);
        $this->assertSame($t4->id, $wbFinal->fresh()->team2_id);
        $this->assertSame($t3->id, $lb1->fresh()->team2_id);
        $this->assertSame('ready', $lb1->fresh()->status);

        // LB1: t3 beats t2 → t3 to LB2 slot 1 (t2 eliminated).
        $lb1->refresh();
        $progression->complete($lb1, $t3);
        $this->assertSame($t3->id, $lb2->fresh()->team1_id);

        // WB final: t4 beats t1 → t4 to grand final slot 1, t1 drops to LB2 slot 2.
        $wbFinal->refresh();
        $progression->complete($wbFinal, $t4);
        $this->assertSame($t4->id, $gf->fresh()->team1_id);
        $this->assertSame($t1->id, $lb2->fresh()->team2_id);
        $this->assertSame('ready', $lb2->fresh()->status);

        // LB2: t1 beats t3 → t1 to grand final slot 2.
        $lb2->refresh();
        $progression->complete($lb2, $t1);
        $this->assertSame($t1->id, $gf->fresh()->team2_id);
        $this->assertSame('ready', $gf->fresh()->status);

        // Grand final: t1 wins it all.
        $gf->refresh();
        $progression->complete($gf, $t1);
        $this->assertSame('completed', $gf->fresh()->status);
        $this->assertSame($t1->id, $gf->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Lifecycle integration & authorization
    // ------------------------------------------------------------------

    public function test_start_generates_bracket_and_sets_live(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_start_fails_when_no_bracket_can_be_formed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        $this->makeTeam($tournament, null, 'confirmed'); // only one team

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('error');

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_player_cannot_generate_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($player)->post(route('tournaments.bracket', $tournament))->assertStatus(403);

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_other_organizer_cannot_generate_bracket(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($orgB)->post(route('tournaments.bracket', $tournament))->assertStatus(403);

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }
}
