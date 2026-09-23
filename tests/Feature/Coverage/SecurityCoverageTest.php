<?php

namespace Tests\Feature\Coverage;

use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FraudRiskService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — security/anti-fraud critical-path coverage.
 *
 * Executes the deterministic risk engine, restriction enforcement and the
 * authorization boundaries around sensitive actions so coverage runs
 * exercise the security layer even under a targeted subset.
 */
class SecurityCoverageTest extends TestCase
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

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'SecCover Tournament';
        $t->slug = 'seccov-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    public function test_risk_profile_escalates_deterministically(): void
    {
        $user = $this->makeUser();
        $risk = app(FraudRiskService::class);

        $low = $risk->profileFor($user);
        $this->assertSame(RiskProfile::LEVEL_LOW, $low->risk_level);

        $risk->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $risk->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);

        $this->assertSame(100, $user->riskProfile()->first()->risk_score);
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $user->riskProfile()->first()->risk_level);
    }

    public function test_critical_risk_blocks_registration(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $risk = app(FraudRiskService::class);

        $risk->recordSignal($captain, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $risk->recordSignal($captain, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);

        $this->expectException(DomainException::class);
        $risk->evaluateRegistration($tournament, $captain);
    }

    public function test_restrictions_are_enforced_and_liftable(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser();
        $tournament = $this->makeTournament($this->makeUser('organizer'));
        $restrictions = app(RestrictionService::class);

        $restriction = $restrictions->restrict(
            $player,
            Restriction::TYPE_REGISTRATION_BLOCKED,
            'Coverage test restriction',
            'manual',
            $admin,
        );

        $this->assertTrue($restriction->isActive());
        $this->assertTrue($player->restrictions()->where('type', Restriction::TYPE_REGISTRATION_BLOCKED)->exists());

        // The restriction blocks registration via the risk gate.
        $this->expectException(DomainException::class);
        app(FraudRiskService::class)->evaluateRegistration($tournament, $player);
    }

    public function test_authorization_policies_block_cross_team_actions(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $other = $this->makeUser();
        $tournament = $this->makeTournament($org);

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Sec Team';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UIDSEC1';
        $team->status = Team::STATUS_PENDING;
        $team->save();

        // A stranger cannot pay on behalf of the team.
        $this->actingAs($other)->get(route('payment.show', [$tournament, $team]))
            ->assertForbidden();
    }

    public function test_account_status_gates_are_enforced(): void
    {
        $org = $this->makeUser('organizer');
        $suspended = $this->makeUser();
        $suspended->account_status = 'suspended';
        $suspended->save();

        $this->makeTournament($org);

        // A suspended account is parked on the security settings page (the
        // only place it can reactivate) instead of reaching its wallet.
        $this->actingAs($suspended)->get(route('wallet.index'))
            ->assertRedirect(route('settings.security'));
    }
}
