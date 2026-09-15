<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\IpIntel;
use App\Models\IpLink;
use App\Models\MatchAnomaly;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AccountLinkService;
use App\Services\AntiCheatService;
use App\Services\DeviceFingerprintService;
use App\Services\IdentityVerificationService;
use App\Services\IpIntelligenceService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 10 — identity verification, device/IP pseudonymization, account
 * linking, ban-evasion detection, anti-cheat incidents and match anomalies.
 */
class AntiFraudTrustSafetyTest extends TestCase
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
        $t->slug = 'test-tournament-' . Str::random(8);
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

    protected function makeTeam(Tournament $tournament, ?User $captain = null): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = 'UID' . rand(100000, 999999);
        $t->status = 'confirmed';
        $t->save();

        return $t;
    }

    protected function makeRequest(array $server = []): Request
    {
        return Request::create('/', 'GET', [], [], [], array_merge([
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'REMOTE_ADDR' => '203.0.113.7',
        ], $server));
    }

    protected function identity(): IdentityVerificationService
    {
        return app(IdentityVerificationService::class);
    }

    protected function devices(): DeviceFingerprintService
    {
        return app(DeviceFingerprintService::class);
    }

    protected function ipIntel(): IpIntelligenceService
    {
        return app(IpIntelligenceService::class);
    }

    protected function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    protected function antiCheat(): AntiCheatService
    {
        return app(AntiCheatService::class);
    }

    protected function restrictions(): RestrictionService
    {
        return app(RestrictionService::class);
    }

    // ------------------------------------------------------------------
    // Identity verification state machine
    // ------------------------------------------------------------------

    public function test_identity_defaults_to_unverified(): void
    {
        $user = $this->makeUser();

        $record = $this->identity()->effectiveStatus($user);

        $this->assertSame(IdentityVerification::STATUS_UNVERIFIED, $record->status);
        $this->assertSame('manual', $record->provider);
        $this->assertFalse($record->isVerified());
    }

    public function test_request_moves_unverified_to_pending(): void
    {
        $user = $this->makeUser();

        $record = $this->identity()->request($user);

        $this->assertSame(IdentityVerification::STATUS_PENDING, $record->status);
        $this->assertFalse($record->isVerified());
    }

    public function test_request_is_idempotent_while_pending(): void
    {
        $user = $this->makeUser();
        $this->identity()->request($user);

        $this->identity()->request($user);

        $this->assertSame(1, IdentityVerification::where('user_id', $user->id)->count());
        $this->assertSame(IdentityVerification::STATUS_PENDING, $user->identityVerification()->first()->status);
    }

    public function test_only_admin_manual_review_verifies(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $this->identity()->request($user);

        $record = $this->identity()->verifyManually($user, $admin, 'documents reviewed');

        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $record->status);
        $this->assertSame($admin->id, $record->reviewed_by);
        $this->assertNotNull($record->verified_at);
        $this->assertSame('documents reviewed', $record->notes);
        $this->assertTrue($record->isVerified());
    }

    public function test_verified_record_expires_lazily(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->identity()->verifyManually($user, $admin, null, now()->addDays(30));
        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $this->identity()->effectiveStatus($user)->status);

        // Force the expiry into the past.
        IdentityVerification::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(IdentityVerification::STATUS_EXPIRED, $this->identity()->effectiveStatus($user)->status);
    }

    public function test_rejection_and_re_request(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->identity()->reject($user, $admin, 'unreadable');
        $this->assertSame(IdentityVerification::STATUS_REJECTED, $user->identityVerification()->first()->status);

        $this->identity()->request($user);
        $this->assertSame(IdentityVerification::STATUS_PENDING, $user->identityVerification()->first()->status);
    }

    public function test_verified_identity_cannot_be_rejected(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $this->identity()->verifyManually($user, $admin);

        $this->expectException(DomainException::class);
        $this->identity()->reject($user, $admin, 'changed my mind');
    }

    public function test_manual_provider_never_fabricates_verification(): void
    {
        $user = $this->makeUser();

        $result = $this->identity()->attemptViaProvider($user, 'manual');

        $this->assertSame(IdentityVerification::STATUS_PENDING, $result['status']);
        $this->assertSame(0, IdentityVerification::where('user_id', $user->id)->where('status', IdentityVerification::STATUS_VERIFIED)->count());
    }

    public function test_unknown_identity_provider_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->identity()->attemptViaProvider($this->makeUser(), 'fake-kyc');
    }

    // ------------------------------------------------------------------
    // Device fingerprinting (pseudonymous)
    // ------------------------------------------------------------------

    public function test_device_hash_is_deterministic_and_pseudonymous(): void
    {
        $hash = $this->devices()->hashFrom($this->makeRequest());

        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, $this->devices()->hashFrom($this->makeRequest()));
        $this->assertStringNotContainsString('TestAgent', $hash);

        $other = $this->devices()->hashFrom($this->makeRequest(['HTTP_USER_AGENT' => 'OtherAgent/9.9']));
        $this->assertNotSame($hash, $other);
    }

    public function test_register_creates_device_and_link(): void
    {
        $user = $this->makeUser();

        $device = $this->devices()->register($this->makeRequest(), $user);

        $this->assertNotNull($device);
        $this->assertSame(Device::STATUS_ACTIVE, $device->status);
        $this->assertSame(1, DeviceLink::where('user_id', $user->id)->count());
        $this->assertSame(1, $this->devices()->devicesFor($user)->count());
    }

    public function test_shared_device_below_tolerance_is_not_flagged(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 3))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->devices()->register($request, $u));

        // 3 accounts on one device — under the default tolerance of 4.
        $this->assertSame(0, RiskEvent::where('type', RiskEvent::TYPE_AUTH_DEVICE_SHARED)->count());
        // …but the accounts are still linked as a similarity signal.
        $this->assertGreaterThan(0, AccountLink::count());
    }

    public function test_shared_device_above_tolerance_raises_signal_only(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 9))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->devices()->register($request, $u));

        $signals = RiskEvent::where('type', RiskEvent::TYPE_AUTH_DEVICE_SHARED)->get();

        $this->assertTrue($signals->isNotEmpty());
        $this->assertTrue($signals->every(fn ($e) => in_array($e->severity, [RiskEvent::SEVERITY_MEDIUM, RiskEvent::SEVERITY_HIGH], true)));

        // No account is suspended by a shared device alone.
        foreach ($users as $u) {
            $this->assertFalse($this->restrictions()->isBlocked($u, []));
        }
    }

    public function test_device_block_marks_blocked(): void
    {
        $user = $this->makeUser();
        $device = $this->devices()->register($this->makeRequest(), $user);

        $this->devices()->block($device);

        $this->assertTrue($device->fresh()->isBlocked());
    }

    // ------------------------------------------------------------------
    // IP intelligence (pseudonymous)
    // ------------------------------------------------------------------

    public function test_ip_hashes_are_pseudonymous_and_grouped(): void
    {
        $service = $this->ipIntel();

        $hash = $service->ipHash('203.0.113.7');
        $subnet = $service->subnetHash('203.0.113.7');

        $this->assertSame(64, strlen($hash));
        $this->assertNotSame('203.0.113.7', $hash);
        $this->assertNotSame($hash, $subnet);
        $this->assertSame($subnet, $service->subnetHash('203.0.113.99')); // same /24
        $this->assertNotSame($subnet, $service->subnetHash('203.0.114.7')); // different /24
    }

    public function test_observe_records_ip_intel_without_raw_ip(): void
    {
        $user = $this->makeUser();

        $intel = $this->ipIntel()->observe($this->makeRequest(), $user);

        $this->assertSame(1, $intel->observation_count);
        $this->assertSame(1, IpLink::where('user_id', $user->id)->count());
        $this->assertSame($this->ipIntel()->ipHash('203.0.113.7'), $intel->ip_hash);

        // The raw IP is never stored anywhere.
        $this->assertStringNotContainsString('203.0.113.7', $intel->ip_hash);
        $this->assertDatabaseMissing('ip_intel', ['ip_hash' => '203.0.113.7']);
    }

    public function test_shared_network_is_tolerated_below_threshold(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 5))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->ipIntel()->observe($request, $u));

        $this->assertSame(0, RiskEvent::where('type', RiskEvent::TYPE_AUTH_IP_SHARED)->count());
    }

    public function test_shared_network_above_threshold_is_a_low_signal_only(): void
    {
        config(['antifraud.ip.max_accounts_shared' => 3]);

        $request = $this->makeRequest();

        $users = collect(range(1, 5))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->ipIntel()->observe($request, $u));

        $signals = RiskEvent::where('type', RiskEvent::TYPE_AUTH_IP_SHARED)->get();

        $this->assertTrue($signals->isNotEmpty());
        $this->assertTrue($signals->every(fn ($e) => $e->severity === RiskEvent::SEVERITY_LOW));
    }

    // ------------------------------------------------------------------
    // Account similarity + ban evasion
    // ------------------------------------------------------------------

    public function test_account_link_is_canonical_and_never_duplicated(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->links()->link($a, $b, AccountLink::STRENGTH_MODERATE, ['shared_device'], 'device');
        $this->links()->link($b, $a, AccountLink::STRENGTH_MODERATE, ['shared_device'], 'device');

        $this->assertSame(1, AccountLink::count());

        $link = AccountLink::first();
        $this->assertSame(min($a->id, $b->id), $link->user_id);
        $this->assertSame(max($a->id, $b->id), $link->linked_user_id);
    }

    public function test_link_strength_upgrades_but_never_downgrades(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->links()->link($a, $b, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');
        $this->links()->link($a, $b, AccountLink::STRENGTH_STRONG, ['payment_match'], 'payment');

        $link = AccountLink::first();
        $this->assertSame(AccountLink::STRENGTH_STRONG, $link->strength);

        $this->links()->link($a, $b, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');
        $this->assertSame(AccountLink::STRENGTH_STRONG, $link->fresh()->strength);
    }

    public function test_self_link_and_invalid_strength_are_rejected(): void
    {
        $a = $this->makeUser();

        try {
            $this->links()->link($a, $a, AccountLink::STRENGTH_WEAK, ['x'], 'x');
            $this->fail('Expected self-link to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->links()->link($a, $this->makeUser(), 'overwhelming', ['x'], 'x');
    }

    public function test_strong_link_to_suspended_account_is_ban_evasion_not_auto_ban(): void
    {
        $restricted = $this->makeUser();
        $newcomer = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($restricted, Restriction::TYPE_ACCOUNT_SUSPENDED, 'previous abuse', 'manual', $admin);

        $this->links()->link($newcomer, $restricted, AccountLink::STRENGTH_STRONG, ['shared_device', 'payment_match'], 'device');

        // The newcomer is flagged for review with a ban-evasion signal…
        $this->assertTrue($newcomer->riskProfile()->first()->manual_review_required);
        $this->assertTrue(
            RiskEvent::where('user_id', $newcomer->id)->where('type', RiskEvent::TYPE_BAN_EVASION)->exists()
        );

        // …but is NOT automatically suspended.
        $this->assertFalse($this->restrictions()->isBlocked($newcomer, []));
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $newcomer->riskProfile()->first()->status);
    }

    public function test_weak_link_to_suspended_account_is_not_ban_evasion(): void
    {
        $restricted = $this->makeUser();
        $newcomer = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($restricted, Restriction::TYPE_ACCOUNT_SUSPENDED, 'abuse', 'manual', $admin);

        $this->links()->link($newcomer, $restricted, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');

        $this->assertFalse(
            RiskEvent::where('user_id', $newcomer->id)->where('type', RiskEvent::TYPE_BAN_EVASION)->exists()
        );
    }

    // ------------------------------------------------------------------
    // Anti-cheat incidents
    // ------------------------------------------------------------------

    public function test_open_incident_validates_category_and_severity(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $reporter = $this->makeUser('moderator');

        try {
            $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'speedhax', 'high');
            $this->fail('Expected invalid category to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('category', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'aimbot', 'extreme');
    }

    public function test_confirmed_incident_applies_audited_restriction(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $accused = $this->makeUser();
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $reporter,
            AntiCheatIncident::SOURCE_STAFF,
            AntiCheatService::CATEGORY_AIMBOT,
            'high',
            'wall-tracking footage',
            'evidence/ref-1',
        );

        $this->assertSame(AntiCheatIncident::STATUS_FLAGGED, $incident->status);
        $this->assertSame('evidence/ref-1', $incident->evidence_reference);

        $this->antiCheat()->review($incident, $reporter);
        $this->assertSame(AntiCheatIncident::STATUS_UNDER_REVIEW, $incident->fresh()->status);
        $this->assertSame($reporter->id, $incident->fresh()->reviewer_id);

        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CONFIRMED, 'replay shows tracking');

        $this->assertSame(AntiCheatIncident::STATUS_CONFIRMED, $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);

        // A granular restriction is applied — never an uncontrolled ban.
        $this->assertTrue(
            $this->restrictions()->isBlocked($accused, [Restriction::TYPE_SCORE_SUBMISSION_BLOCKED])
        );

        $this->assertTrue(
            RiskEvent::where('user_id', $accused->id)->where('type', RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED)->exists()
        );
    }

    public function test_cleared_incident_is_false_positive_safe(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $accused = $this->makeUser();
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $reporter,
            AntiCheatIncident::SOURCE_PARTICIPANT,
            AntiCheatService::CATEGORY_TEAMING,
            'low',
        );

        $this->antiCheat()->review($incident, $reporter);
        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CLEARED, 'both teams denied and no evidence');

        $this->assertSame(AntiCheatIncident::STATUS_CLEARED, $incident->fresh()->status);
        $this->assertFalse($this->restrictions()->isBlocked($accused, []));
    }

    public function test_incident_transitions_are_enforced(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'other', 'low');

        // A flagged incident cannot be resolved without review.
        $this->expectException(DomainException::class);
        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CLEARED, 'nope');
    }

    // ------------------------------------------------------------------
    // Match anomalies (deterministic, never "cheating")
    // ------------------------------------------------------------------

    public function test_record_anomaly_validates_kind_and_severity(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $this->makeUser());
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'completed';
        $match->save();

        $anomaly = $this->antiCheat()->recordAnomaly($match, $tournament, MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, MatchAnomaly::SEVERITY_SUSPICIOUS, ['kills' => 61]);

        $this->assertSame(MatchAnomaly::SEVERITY_SUSPICIOUS, $anomaly->status);
        $this->assertSame(MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, $anomaly->kind);

        $this->expectException(DomainException::class);
        $this->antiCheat()->recordAnomaly($match, $tournament, 'impossible_score', MatchAnomaly::SEVERITY_ANOMALY, []);
    }

    public function test_abnormal_kill_ratio_is_detected_on_submission(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $captain = $this->makeUser();
        $team = $this->makeTeam($tournament, $captain);
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 999, 1);

        $this->assertCount(1, $created);
        $this->assertSame(MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, $created->first()->kind);
        $this->assertSame(MatchAnomaly::SEVERITY_SUSPICIOUS, $created->first()->status);

        // An anomaly is an observation, never an auto-accusation.
        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_repeated_pattern_is_detected(): void
    {
        config(['antifraud.anomaly.repeat_pattern_threshold' => 1]);

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $captain = $this->makeUser();
        $team = $this->makeTeam($tournament, $captain);

        $other = new GameMatch();
        $other->tournament_id = $tournament->id;
        $other->round = 1;
        $other->match_no = 2;
        $other->team1_id = $team->id;
        $other->status = 'completed';
        $other->save();

        $score = new Score();
        $score->match_id = $other->id;
        $score->team_id = $team->id;
        $score->kills = 20;
        $score->placement = 2;
        $score->points = 0;
        $score->save();

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 2;
        $match->match_no = 3;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 20, 2);

        $this->assertCount(1, $created);
        $this->assertSame(MatchAnomaly::KIND_REPEATED_PATTERN, $created->first()->kind);
        $this->assertSame(MatchAnomaly::SEVERITY_ANOMALY, $created->first()->status);
    }

    public function test_normal_submission_creates_no_anomaly(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $this->makeUser());
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 12, 3);

        $this->assertCount(0, $created);
        $this->assertSame(0, MatchAnomaly::count());
    }
}
