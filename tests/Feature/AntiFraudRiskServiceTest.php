<?php

namespace Tests\Feature;

use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\FraudRiskService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 — deterministic fraud/risk engine + granular restrictions.
 */
class AntiFraudRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function risk(): FraudRiskService
    {
        return app(FraudRiskService::class);
    }

    protected function restrictions(): RestrictionService
    {
        return app(RestrictionService::class);
    }

    // ------------------------------------------------------------------
    // Risk scoring + levels
    // ------------------------------------------------------------------

    public function test_profile_is_lazily_created_with_low_risk(): void
    {
        $user = $this->makeUser();

        $this->assertNull($user->riskProfile()->first());

        $profile = $this->risk()->profileFor($user);

        $this->assertSame(0, $profile->risk_score);
        $this->assertSame(RiskProfile::LEVEL_LOW, $profile->risk_level);
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $profile->status);
        $this->assertSame($profile->id, $user->riskProfile()->first()->id);
    }

    public function test_severity_scores_are_deterministic(): void
    {
        $this->assertSame(0, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_INFO));
        $this->assertSame(5, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_LOW));
        $this->assertSame(15, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_MEDIUM));
        $this->assertSame(30, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_HIGH));
        $this->assertSame(50, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_CRITICAL));
    }

    public function test_level_thresholds_are_deterministic(): void
    {
        $this->assertSame(RiskProfile::LEVEL_LOW, $this->risk()->levelFromScore(0));
        $this->assertSame(RiskProfile::LEVEL_LOW, $this->risk()->levelFromScore(29));
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $this->risk()->levelFromScore(30));
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $this->risk()->levelFromScore(59));
        $this->assertSame(RiskProfile::LEVEL_HIGH, $this->risk()->levelFromScore(60));
        $this->assertSame(RiskProfile::LEVEL_HIGH, $this->risk()->levelFromScore(89));
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $this->risk()->levelFromScore(90));
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $this->risk()->levelFromScore(100));
    }

    public function test_score_is_capped_at_one_hundred(): void
    {
        $user = $this->makeUser();

        foreach (range(1, 4) as $_) {
            $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        }

        $profile = $user->riskProfile()->first();

        $this->assertSame(100, $profile->risk_score);
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $profile->risk_level);
    }

    public function test_record_signal_recalculates_and_is_append_only(): void
    {
        $user = $this->makeUser();

        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', ['k' => 'v']);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);

        $this->assertSame(2, RiskEvent::where('user_id', $user->id)->count());
        $this->assertSame(30, $user->riskProfile()->first()->risk_score);
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $user->riskProfile()->first()->risk_level);
    }

    public function test_unknown_severity_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        $this->risk()->recordSignal($this->makeUser(), RiskEvent::TYPE_RISK_FLAG, 'super-bad', 'risk', []);
    }

    public function test_null_user_signal_persists_without_profile(): void
    {
        $event = $this->risk()->recordSignal(null, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_LOW, 'risk', []);

        $this->assertNull($event->user_id);
        $this->assertSame(RiskEvent::SEVERITY_LOW, $event->severity);
    }

    // ------------------------------------------------------------------
    // Actions + gates
    // ------------------------------------------------------------------

    public function test_action_for_each_level(): void
    {
        $low = $this->makeUser();
        $this->assertSame(FraudRiskService::ACTION_ALLOW, $this->risk()->actionFor($low));

        $medium = $this->makeUser();
        $this->risk()->recordSignal($medium, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->risk()->recordSignal($medium, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_FLAG, $this->risk()->actionFor($medium));

        $high = $this->makeUser();
        $this->risk()->recordSignal($high, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($high, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_REQUIRE_REVIEW, $this->risk()->actionFor($high));

        $critical = $this->makeUser();
        $this->risk()->recordSignal($critical, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->risk()->recordSignal($critical, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_RESTRICT, $this->risk()->actionFor($critical));
    }

    public function test_low_risk_gate_allows_silently(): void
    {
        $user = $this->makeUser();

        $this->assertSame(FraudRiskService::ACTION_ALLOW, $this->risk()->gate($user, 'registration'));
        $this->assertSame(0, RiskEvent::where('user_id', $user->id)->count());
    }

    public function test_medium_risk_gate_flags_but_allows(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);

        $action = $this->risk()->gate($user, 'registration');

        $this->assertSame(FraudRiskService::ACTION_FLAG, $action);
        $this->assertFalse($user->riskProfile()->first()->manual_review_required);
    }

    public function test_high_risk_gate_flags_for_review_and_allows(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $action = $this->risk()->gate($user, 'registration');

        $this->assertSame(FraudRiskService::ACTION_REQUIRE_REVIEW, $action);
        $this->assertTrue($user->riskProfile()->first()->manual_review_required);
    }

    public function test_critical_risk_gate_blocks(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);

        $this->expectException(DomainException::class);
        $this->risk()->gate($user, 'registration');
    }

    public function test_context_specific_restrictions_block_gates(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($user, Restriction::TYPE_SCORE_SUBMISSION_BLOCKED, 'test', 'manual', $admin);

        // Score submission is blocked…
        try {
            $this->risk()->gate($user, 'score_submission');
            $this->fail('Expected score submission to be blocked.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('restricted', $e->getMessage());
        }

        // …but an unrelated context is not blocked (no blanket ban). The
        // restriction itself raised an audit signal (high severity → medium
        // risk), so the unrelated action is allowed-but-flagged, not blocked.
        $this->assertNotSame(FraudRiskService::ACTION_RESTRICT, $this->risk()->gate($user, 'registration'));
    }

    // ------------------------------------------------------------------
    // Restrictions
    // ------------------------------------------------------------------

    public function test_restriction_requires_reason_and_valid_type(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        try {
            $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, '   ', 'manual', $admin);
            $this->fail('Expected a DomainException for an empty reason.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->restrictions()->restrict($user, 'not_a_type', 'x', 'manual', $admin);
    }

    public function test_suspension_freezes_profile_and_records_audit(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict(
            $user,
            Restriction::TYPE_ACCOUNT_SUSPENDED,
            'ban evasion review',
            'manual',
            $admin,
        );

        $this->assertSame(Restriction::STATUS_ACTIVE, $restriction->status);
        $this->assertSame($admin->id, $restriction->actor_id);
        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);
        $this->assertTrue($this->restrictions()->isBlocked($user, []));

        $events = RiskEvent::where('user_id', $user->id)->where('type', RiskEvent::TYPE_ACCOUNT_RESTRICTED)->get();
        $this->assertCount(1, $events);
        $this->assertSame(RiskEvent::SEVERITY_CRITICAL, $events->first()->severity);
    }

    public function test_expired_restriction_no_longer_blocks(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict(
            $user,
            Restriction::TYPE_CHECKIN_BLOCKED,
            'temp',
            'manual',
            $admin,
            now()->addDay(),
        );

        $this->assertTrue($this->restrictions()->isBlocked($user, [Restriction::TYPE_CHECKIN_BLOCKED]));

        // Simulate the expiry passing.
        Restriction::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($this->restrictions()->isBlocked($user, [Restriction::TYPE_CHECKIN_BLOCKED]));
    }

    public function test_lift_restores_profile_status(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);

        $this->restrictions()->lift($restriction, $admin);

        $this->assertSame(Restriction::STATUS_LIFTED, $restriction->fresh()->status);
        $this->assertSame($admin->id, $restriction->fresh()->lifted_by);
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $user->riskProfile()->first()->status);
        $this->assertFalse($this->restrictions()->isBlocked($user, []));
    }

    public function test_lifting_twice_is_rejected(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict($user, Restriction::TYPE_DISPUTE_BLOCKED, 'x', 'manual', $admin);
        $this->restrictions()->lift($restriction, $admin);

        $this->expectException(DomainException::class);
        $this->restrictions()->lift($restriction, $admin);
    }

    public function test_other_suspension_keeps_profile_suspended_after_lift(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $first = $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'one', 'manual', $admin);
        $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'two', 'manual', $admin);

        $this->restrictions()->lift($first, $admin);

        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);
    }

    // ------------------------------------------------------------------
    // Mass-assignment protection
    // ------------------------------------------------------------------

    public function test_risk_models_reject_mass_assignment(): void
    {
        $user = $this->makeUser();

        // All Phase 10 models declare `$fillable = []`, so `fill()` is
        // totally guarded and throws a MassAssignmentException.
        foreach ([new RiskProfile(), new RiskEvent(), new Restriction()] as $model) {
            try {
                $model->fill([
                    'user_id' => $user->id,
                    'risk_score' => 99,
                    'risk_level' => RiskProfile::LEVEL_CRITICAL,
                    'severity' => RiskEvent::SEVERITY_CRITICAL,
                    'type' => Restriction::TYPE_ACCOUNT_SUSPENDED,
                    'status' => RiskProfile::STATUS_SUSPENDED,
                ]);
                $this->fail(get_class($model) . ' accepted mass assignment.');
            } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
