<?php

namespace Tests\Feature\Regression;

use App\Models\AuditLog;
use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\Restriction;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dialect-parity regressions (GAP-10, PostgreSQL verification).
 *
 * These three defects were all invisible on SQLite and wrong or fatal on
 * PostgreSQL, which is the production database:
 *
 *   1. `MarketingAttribution` has no `created_at` column (it stores
 *      `first_seen_at` / `last_seen_at`), but the marketing analytics
 *      dashboard filtered it by `created_at`. SQLite silently reinterprets an
 *      unknown double-quoted identifier as a *string literal* (the legacy
 *      "double-quoted string" misfeature), so the predicate degraded into
 *      `'created_at' >= '<timestamp>'`, which is always true — the period
 *      filter simply never applied. PostgreSQL raises
 *      `SQLSTATE[42703] undefined column`, and because the caller runs inside
 *      a transaction every later statement fails with `25P02` and the
 *      dashboard returns 500.
 *
 *   2. `where('name', 'like', ...)` is case-insensitive on SQLite but
 *      case-sensitive on PostgreSQL, so `?q=bermuda` matched nothing on
 *      production. The same pattern lived in the admin account search.
 *
 *   3. `SecurityController::restrict()` passed the acting admin as the
 *      `$source` argument (a `varchar(20)` column) and the expiry as `$actor`.
 *      Coercing the `User` model to a string yields its JSON representation,
 *      which SQLite stores happily and PostgreSQL rejects with
 *      `SQLSTATE[22001] value too long for type character varying(20)` — the
 *      admin "restrict user" button was a 500 on production.
 *
 * Nothing here is dialect-specific in its *assertion*: each test states the
 * behaviour the application must have on any database, so the suite fails
 * loudly on the next regression instead of depending on which driver ran.
 */
class DialectParityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_period_filter_actually_excludes_old_attribution_touches(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        MarketingCampaign::create([
            'name' => 'Parity Cup',
            'headline' => 'Dialect parity',
            'slug' => 'parity-cup-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);

        // Inside the reporting window.
        $recent = new MarketingAttribution();
        $recent->anonymous_id = 'parity_recent';
        $recent->campaign_key = 'parity-cup';
        $recent->source = 'recent-source';
        $recent->medium = 'cpc';
        $recent->campaign = 'parity-cup';
        $recent->first_seen_at = now()->subDay();
        $recent->last_seen_at = now()->subDay();
        $recent->save();

        // Far outside the reporting window: it must not be counted, and it
        // must not blow the page up on a database that enforces the schema.
        $ancient = new MarketingAttribution();
        $ancient->anonymous_id = 'parity_ancient';
        $ancient->campaign_key = 'parity-cup';
        $ancient->source = 'ancient-source';
        $ancient->medium = 'cpc';
        $ancient->campaign = 'parity-cup';
        $ancient->first_seen_at = now()->subDays(90);
        $ancient->last_seen_at = now()->subDays(90);
        $ancient->save();

        $response = $this->actingAs($admin)->get('/admin/marketing/analytics?days=30');

        $response->assertOk();
        $response->assertSee('recent-source');
        $response->assertDontSee('ancient-source');
    }

    public function test_marketing_dashboard_renders_when_the_window_contains_nothing(): void
    {
        // The period-filtered queries must not explode for an empty window;
        // the dashboard falls back to all-time aggregates by design.
        $admin = User::factory()->create(['role' => 'admin']);

        $old = new MarketingAttribution();
        $old->anonymous_id = 'parity_only_old';
        $old->campaign_key = '(direct)';
        $old->source = 'only-old-source';
        $old->first_seen_at = now()->subDays(120);
        $old->last_seen_at = now()->subDays(120);
        $old->save();

        $this->actingAs($admin)
            ->get('/admin/marketing/analytics?days=7')
            ->assertOk()
            ->assertSee('only-old-source');
    }

    public function test_tournament_search_is_case_insensitive_on_every_dialect(): void
    {
        $organizer = $this->makeOrganizer();

        $this->makeTournament($organizer, ['name' => 'Bermuda Blitz', 'map' => 'Bermuda']);
        $this->makeTournament($organizer, ['name' => 'Purgatorio Cup', 'map' => 'Purgatorio']);

        $this->get(route('tournaments.index', ['q' => 'bermuda']))
            ->assertOk()
            ->assertSee('Bermuda Blitz')
            ->assertDontSee('Purgatorio Cup');
    }

    public function test_tournament_search_still_escapes_like_wildcards(): void
    {
        $organizer = $this->makeOrganizer();

        $this->makeTournament($organizer, ['name' => 'Wildcard Blitz', 'map' => 'Bermuda']);

        // A bare `%` is escaped, so it matches nothing instead of everything.
        $this->get(route('tournaments.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('No tournaments found');
    }

    public function test_admin_restriction_records_the_admin_as_actor_and_a_bounded_source(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $victim = User::factory()->create(['role' => 'player']);

        $this->actingAs($admin)->post(route('admin.security.restrict', $victim), [
            'type' => Restriction::TYPE_DISPUTE_BLOCKED,
            'reason' => 'dialect parity regression',
        ])->assertRedirect();

        $restriction = Restriction::query()->latest('id')->firstOrFail();

        $this->assertSame('admin_manual', $restriction->source);
        $this->assertSame($admin->id, $restriction->actor_id);

        // The audit entry carries the same actor, so the moderation trail is
        // attributable (this is what the varchar(20) overflow used to break).
        $audit = AuditLog::query()
            ->where('action', 'restriction.applied')
            ->where('entity_id', $restriction->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_user_id);
    }

    protected function makeOrganizer(): User
    {
        return User::factory()->create([
            'role' => 'organizer',
            'account_status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTournament(User $organizer, array $attributes = []): Tournament
    {
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = $attributes['name'] ?? 'Parity Tournament';
        $tournament->slug = 'parity-'.Str::lower(Str::random(10));
        $tournament->game_mode = 'squad';
        $tournament->map = $attributes['map'] ?? 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = Tournament::STATUS_OPEN;
        $tournament->save();

        return $tournament;
    }
}
