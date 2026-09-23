<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 13 — analytics: deterministic aggregates and role scoping.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Analytics Tournament';
        $t->slug = 'analytics-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed', bool $checkedIn = false): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->checked_in_at = $checkedIn ? now() : null;
        $team->save();

        return $team;
    }

    protected function analytics(): AnalyticsService
    {
        return app(AnalyticsService::class);
    }

    public function test_tournament_metrics_are_deterministic(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->makeTeam($tournament, $this->makeUser(), Team::STATUS_CONFIRMED, true);
        $this->makeTeam($tournament, $this->makeUser(), Team::STATUS_CONFIRMED, false);
        $this->makeTeam($tournament, $this->makeUser(), Team::STATUS_WAITLISTED);
        $this->makeTeam($tournament, $this->makeUser(), Team::STATUS_NO_SHOW);

        $match1 = new GameMatch();
        $match1->tournament_id = $tournament->id;
        $match1->round = 1;
        $match1->match_no = 1;
        $match1->status = GameMatch::STATUS_COMPLETED;
        $match1->save();

        $match2 = new GameMatch();
        $match2->tournament_id = $tournament->id;
        $match2->round = 1;
        $match2->match_no = 2;
        $match2->status = GameMatch::STATUS_DISPUTED;
        $match2->save();

        $metrics = $this->analytics()->tournamentMetrics($tournament);

        $this->assertSame(4, $metrics['teams']['total']);
        $this->assertSame(2, $metrics['teams']['confirmed']);
        $this->assertSame(1, $metrics['teams']['waitlisted']);
        $this->assertSame(1, $metrics['teams']['no_show']);
        $this->assertSame(1, $metrics['teams']['checked_in']);

        // Slot teams = 2 confirmed + 1 no-show; 1 of 3 checked in.
        $this->assertSame(33.3, $metrics['rates']['check_in']);
        $this->assertSame(33.3, $metrics['rates']['no_show']);
        $this->assertSame(50.0, $metrics['rates']['match_completion']);
        $this->assertSame(2, $metrics['matches']['total']);
        $this->assertSame(1, $metrics['matches']['completed']);
        $this->assertSame(1, $metrics['matches']['disputed']);
    }

    public function test_platform_overview_counts(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_LIVE);
        $this->makeTournament($organizer, Tournament::STATUS_FINISHED);
        $this->makeUser('player');
        $this->makeUser('player');

        $overview = $this->analytics()->platformOverview();

        $this->assertGreaterThanOrEqual(2, $overview['tournaments']['total']);
        $this->assertSame(1, $overview['tournaments']['live']);
        $this->assertSame(1, $overview['tournaments']['finished']);
        $this->assertGreaterThanOrEqual(3, $overview['users']);
    }

    public function test_financial_metrics_return_money_in_minor_units(): void
    {
        $metrics = $this->analytics()->financialMetrics();

        $this->assertArrayHasKey('volume_minor', $metrics['payments']);
        $this->assertArrayHasKey('balance_minor', $metrics['wallets']);
        $this->assertArrayHasKey('completed_minor', $metrics['payouts']);
        $this->assertArrayHasKey('exceptions', $metrics['settlements']);
    }

    public function test_security_metrics_have_role_safe_shape(): void
    {
        $metrics = $this->analytics()->securityMetrics();

        $this->assertArrayHasKey('low', $metrics['risk_levels']);
        $this->assertArrayHasKey('active', $metrics['restrictions']);
        $this->assertArrayHasKey('identity_reviews', $metrics);
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_global_analytics_is_admin_only(): void
    {
        $this->get(route('admin.analytics.index'))->assertRedirect(route('login'));

        $this->actingAs($this->makeUser('player'))->get(route('admin.analytics.index'))->assertForbidden();
        $this->actingAs($this->makeUser('organizer'))->get(route('admin.analytics.index'))->assertForbidden();
        $this->actingAs($this->makeUser('moderator'))->get(route('admin.analytics.index'))->assertForbidden();

        $this->actingAs($this->makeUser('admin'))->get(route('admin.analytics.index'))->assertOk();
    }

    public function test_financial_and_security_analytics_are_admin_only(): void
    {
        $admin = $this->makeUser('admin');
        $moderator = $this->makeUser('moderator');

        $this->actingAs($admin)->get(route('admin.analytics.financial'))->assertOk();
        $this->actingAs($admin)->get(route('admin.analytics.security'))->assertOk();

        $this->actingAs($moderator)->get(route('admin.analytics.financial'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.analytics.security'))->assertForbidden();
    }

    public function test_dispute_and_support_analytics_are_staff_only(): void
    {
        $admin = $this->makeUser('admin');
        $moderator = $this->makeUser('moderator');
        $player = $this->makeUser('player');

        $this->actingAs($moderator)->get(route('admin.analytics.disputes'))->assertOk();
        $this->actingAs($moderator)->get(route('admin.analytics.support'))->assertOk();
        $this->actingAs($admin)->get(route('admin.analytics.disputes'))->assertOk();

        $this->actingAs($player)->get(route('admin.analytics.disputes'))->assertForbidden();
        $this->actingAs($player)->get(route('admin.analytics.support'))->assertForbidden();
    }

    public function test_organizer_can_view_own_tournament_analytics_only(): void
    {
        $organizer = $this->makeUser('organizer');
        $other = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($organizer)->get(route('tournaments.analytics', $tournament))->assertOk();
        $this->actingAs($other)->get(route('tournaments.analytics', $tournament))->assertForbidden();
        $this->actingAs($this->makeUser('player'))->get(route('tournaments.analytics', $tournament))->assertForbidden();
        $this->actingAs($this->makeUser('moderator'))->get(route('tournaments.analytics', $tournament))->assertOk();
    }

    public function test_tournament_analytics_export_is_admin_only(): void
    {
        $this->actingAs($this->makeUser('admin'))->get(route('admin.analytics.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($this->makeUser('moderator'))->get(route('admin.analytics.export'))->assertForbidden();
    }
}
