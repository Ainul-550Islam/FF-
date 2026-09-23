<?php

namespace Tests\Feature;

use App\Exceptions\PayoutReviewRequiredException;
use App\Models\AntiCheatIncident;
use App\Models\IdentityVerification;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PrizeDistribution;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FraudRiskService;
use App\Services\PayoutService;
use App\Services\RestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 10 — HTTP authorization (IDOR / role escalation), admin security UI
 * smoke and the payout fraud gate.
 */
class AntiFraudSecurityHttpTest extends TestCase
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
        $t->name = 'Test Tournament';
        $t->slug = 'test-tournament-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function risk(): FraudRiskService
    {
        return app(FraudRiskService::class);
    }

    // ------------------------------------------------------------------
    // Admin security UI — role gating
    // ------------------------------------------------------------------

    public function test_guest_is_redirected_from_security_routes(): void
    {
        $this->get('/admin/security')->assertRedirect('/login');
        $this->get('/security/incidents')->assertRedirect('/login');
    }

    public function test_player_cannot_access_admin_security(): void
    {
        $this->actingAs($this->makeUser('player'))->get('/admin/security')->assertStatus(403);
    }

    public function test_organizer_cannot_access_admin_security(): void
    {
        $this->actingAs($this->makeUser('organizer'))->get('/admin/security')->assertStatus(403);
    }

    public function test_moderator_cannot_access_admin_security(): void
    {
        // The admin route middleware is admin-only, even though moderators
        // may view the read-only security summary at /moderation/security.
        $this->actingAs($this->makeUser('moderator'))->get('/admin/security')->assertStatus(403);
    }

    public function test_admin_can_access_security_dashboard(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get(route('admin.security.dashboard'))
            ->assertOk()
            ->assertSee('Security Dashboard');
    }

    public function test_admin_can_access_users_and_events_lists(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('admin.security.users'))->assertOk();
        $this->actingAs($admin)->get(route('admin.security.events'))->assertOk();
    }

    public function test_player_cannot_view_another_users_risk_detail(): void
    {
        $player = $this->makeUser('player');
        $victim = $this->makeUser('player');

        $this->actingAs($player)->get(route('admin.security.user', $victim))->assertStatus(403);
    }

    public function test_admin_can_view_another_users_risk_detail(): void
    {
        $admin = $this->makeUser('admin');
        $victim = $this->makeUser('player');

        $this->actingAs($admin)
            ->get(route('admin.security.user', $victim))
            ->assertOk()
            ->assertSee('Risk Profile');
    }

    // ------------------------------------------------------------------
    // Incidents — staff/organizer/player gating
    // ------------------------------------------------------------------

    public function test_player_cannot_view_the_incident_queue(): void
    {
        $this->actingAs($this->makeUser('player'))->get(route('security.incidents.index'))->assertStatus(403);
    }

    public function test_organizer_can_view_incidents(): void
    {
        $this->actingAs($this->makeUser('organizer'))->get(route('security.incidents.index'))->assertOk();
    }

    public function test_moderator_can_view_incidents(): void
    {
        $this->actingAs($this->makeUser('moderator'))->get(route('security.incidents.index'))->assertOk();
    }

    public function test_organizer_cannot_open_an_incident(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($organizer)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'category' => 'aimbot',
            'severity' => 'high',
            'description' => 'suspicious',
        ])->assertStatus(403);

        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_moderator_can_open_review_and_resolve_incident(): void
    {
        $moderator = $this->makeUser('moderator');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $accused = $this->makeUser('player');

        $this->actingAs($moderator)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'accused_user_id' => $accused->id,
            'category' => 'teaming',
            'severity' => 'medium',
            'description' => 'coordinated',
        ])->assertRedirect(route('security.incidents.index'));

        $incident = AntiCheatIncident::firstOrFail();
        $this->assertSame(AntiCheatIncident::STATUS_FLAGGED, $incident->status);

        $this->actingAs($moderator)->post(route('security.incidents.review', $incident))
            ->assertRedirect();

        $this->actingAs($moderator)->post(route('security.incidents.resolve', $incident), [
            'resolution' => AntiCheatIncident::STATUS_DISMISSED,
            'resolution_text' => 'insufficient evidence',
        ])->assertRedirect();

        $this->assertSame(AntiCheatIncident::STATUS_DISMISSED, $incident->fresh()->status);
    }

    public function test_player_cannot_resolve_an_incident(): void
    {
        $moderator = $this->makeUser('moderator');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($moderator)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'category' => 'other',
            'severity' => 'low',
        ]);

        $incident = AntiCheatIncident::firstOrFail();

        $this->actingAs($this->makeUser('player'))->post(route('security.incidents.resolve', $incident), [
            'resolution' => AntiCheatIncident::STATUS_CLEARED,
            'resolution_text' => 'hax',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Restrictions + identity — admin-only actions
    // ------------------------------------------------------------------

    public function test_moderator_cannot_apply_a_restriction(): void
    {
        $moderator = $this->makeUser('moderator');
        $victim = $this->makeUser('player');

        $this->actingAs($moderator)->post(route('admin.security.restrict', $victim), [
            'type' => Restriction::TYPE_ACCOUNT_SUSPENDED,
            'reason' => 'test',
        ])->assertStatus(403);

        $this->assertSame(0, Restriction::count());
    }

    public function test_admin_can_restrict_and_lift(): void
    {
        $admin = $this->makeUser('admin');
        $victim = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.restrict', $victim), [
            'type' => Restriction::TYPE_DISPUTE_BLOCKED,
            'reason' => 'dispute abuse',
        ])->assertRedirect();

        $restriction = Restriction::firstOrFail();
        $this->assertTrue($restriction->isActive());

        $this->actingAs($admin)->post(route('admin.security.lift', $restriction))->assertRedirect();

        $this->assertFalse($restriction->fresh()->isActive());
    }

    public function test_player_can_request_verification_for_themselves(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->from('/wallet')->post(route('security.identity.request'))->assertRedirect('/wallet');

        $this->assertSame(IdentityVerification::STATUS_PENDING, $player->identityVerification()->first()->status);
    }

    public function test_player_cannot_verify_themselves(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->post(route('admin.security.verify', $player), ['notes' => 'self'])
            ->assertStatus(403);

        $this->assertSame(
            0,
            IdentityVerification::where('user_id', $player->id)->where('status', IdentityVerification::STATUS_VERIFIED)->count()
        );
    }

    public function test_admin_verifies_identity_and_it_shows_in_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.verify', $player), ['notes' => 'manual review passed'])
            ->assertRedirect();

        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $player->identityVerification()->first()->status);

        $this->actingAs($player)->get(route('wallet.index'))->assertOk()->assertSee('Verified');
    }

    // ------------------------------------------------------------------
    // Payout fraud gate (Phase 09 service + Phase 10 gate)
    // ------------------------------------------------------------------

    protected function makePayout(Tournament $tournament, User $recipient): Payout
    {
        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = 'approved';
        $distribution->pool_minor = 10000;
        $distribution->total_allocated_minor = 10000;
        $distribution->save();

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 1;
        $payout->amount_minor = 10000;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_APPROVED;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->save();

        return $payout;
    }

    public function test_low_risk_recipient_payout_processes(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $payout = $this->makePayout($tournament, $recipient);

        $processed = app(PayoutService::class)->process($payout, $admin);

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertSame(10000, $recipient->wallet()->first()->balance_minor);
    }

    public function test_high_risk_recipient_payout_is_held_not_failed(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        // Push the recipient to high risk (2 × high severity = 60).
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $this->assertSame(RiskProfile::LEVEL_HIGH, $recipient->riskProfile()->first()->risk_level);

        $payout = $this->makePayout($tournament, $recipient);

        try {
            app(PayoutService::class)->process($payout, $admin);
            $this->fail('Expected the payout to be held for fraud review.');
        } catch (PayoutReviewRequiredException $e) {
            // Held, not failed, not confiscated.
        }

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
        $this->assertNull($recipient->wallet()->first());
        $this->assertTrue($recipient->riskProfile()->first()->manual_review_required);
    }

    public function test_authorized_override_processes_held_payout_with_reason(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $payout = $this->makePayout($tournament, $recipient);

        $processed = app(PayoutService::class)->processWithOverride($payout, $admin, 'manual review cleared — prize is legitimate');

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertSame(10000, $recipient->wallet()->first()->balance_minor);

        $event = $payout->events()->where('event', PayoutEvent::EVENT_PROCESSING)->first();
        $this->assertTrue((bool) ($event->metadata['override'] ?? false));
        $this->assertSame('manual review cleared — prize is legitimate', $event->metadata['reason'] ?? null);
    }

    public function test_override_requires_a_reason(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $payout = $this->makePayout($tournament, $recipient);

        $this->expectException(\DomainException::class);
        app(PayoutService::class)->processWithOverride($payout, $admin, '   ');
    }

    public function test_registration_gate_blocks_suspended_user(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        app(RestrictionService::class)->restrict($player, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->actingAs($player)->post(route('teams.store', $tournament), [
            'name' => 'Blocked Team',
            'captain_name' => $player->name,
            'phone' => '01700000000',
            'game_uid' => 'UID123456',
        ])->assertRedirect();

        $this->assertSame(0, $tournament->teams()->count());
        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $player->riskProfile()->first()->status);
    }
}
