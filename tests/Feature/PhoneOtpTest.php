<?php

namespace Tests\Feature;

use App\Contracts\PhoneOtpProviderInterface;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\IdentityService;
use App\Services\PhoneOtpService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 — phone OTP: normalization, issuance, verification, abuse limits,
 * phone login and account linking (deterministic provider, no SMS sent).
 */
class PhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function bindFakeSms(): FakeSmsProvider
    {
        $fake = new FakeSmsProvider();
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        return $fake;
    }

    protected function otp(): PhoneOtpService
    {
        return app(PhoneOtpService::class);
    }

    protected function addPhoneIdentity(User $user, string $normalizedPhone): void
    {
        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'phone';
        $identity->provider_subject = $normalizedPhone;
        $identity->verified_at = now();
        $identity->save();
    }

    public function test_normalization_accepts_bangladeshi_local_formats(): void
    {
        $this->assertSame('+8801712345678', $this->otp()->normalize('01712345678'));
        $this->assertSame('+8801712345678', $this->otp()->normalize('8801712345678'));
        $this->assertSame('+8801712345678', $this->otp()->normalize('+880 1712-345678'));
    }

    public function test_normalization_rejects_invalid_numbers(): void
    {
        $this->expectException(DomainException::class);
        $this->otp()->normalize('12345');
    }

    public function test_issue_and_verify_round_trip(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $challenge = $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->assertNotNull($challenge);
        $this->assertSame('+8801712345678', $fake->lastPhone);
        $this->assertNotEmpty($fake->lastCode);
        $this->assertSame('+8801712345678', $challenge->phone);
        $this->assertNotSame($fake->lastCode, $challenge->code_hash, 'Raw code is never stored.');

        $verified = $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
        $this->assertSame(OtpChallenge::STATUS_VERIFIED, $verified->status);
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
    }

    public function test_challenge_expires(): void
    {
        $this->bindFakeSms();
        $user = User::factory()->create();

        $challenge = $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
        $challenge->expires_at = now()->subMinute();
        $challenge->save();

        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
    }

    public function test_code_is_single_use(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);

        // Same code again must fail.
        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->expectException(DomainException::class);
        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
    }

    public function test_attempts_are_capped(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
            } catch (DomainException $e) {
                // expected
            }
        }

        // Even the correct code is now refused (challenge consumed).
        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
    }

    public function test_phone_login_flows_through_otp(): void
    {
        $fake = $this->bindFakeSms();

        $user = User::factory()->create(['email' => 'alam@example.com']);
        $user->account_status = 'active';
        $user->save();
        $this->addPhoneIdentity($user, '+8801712345678');

        $this->post(route('phone.request'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));

        $this->post(route('phone.login.verify'), [
            'phone' => '01712345678',
            'purpose' => 'login',
            'code' => $fake->lastCode,
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => 'login.phone',
            'status' => 'success',
        ]);
    }

    public function test_phone_login_with_unknown_number_never_leaks_accounts(): void
    {
        $this->bindFakeSms();

        $this->post(route('phone.request'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));
    }

    public function test_authenticated_user_can_link_and_unlink_phone(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create(['email' => 'alam@example.com', 'password' => 'hashed']);
        $user->account_status = 'active';
        $user->save();

        $this->actingAs($user)->post(route('settings.phone.link'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));

        $this->actingAs($user)->post(route('settings.phone.link.verify'), [
            'phone' => '01712345678',
            'purpose' => 'link',
            'code' => $fake->lastCode,
        ])->assertRedirect(route('settings.connected-accounts'));

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'phone',
            'provider_subject' => '+8801712345678',
        ]);

        // Unlink (a password remains, so this is allowed).
        $this->actingAs($user)->post(route('settings.phone.unlink'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'phone']);
    }

    public function test_phone_identity_is_unique_across_users(): void
    {
        $first = User::factory()->create(['email' => 'one@example.com', 'password' => 'hashed']);
        $second = User::factory()->create(['email' => 'two@example.com', 'password' => 'hashed']);

        $this->addPhoneIdentity($first, '+8801712345678');

        $this->expectException(DomainException::class);
        app(IdentityService::class)->linkPhone($second, '+8801712345678');
    }

    public function test_otp_issue_refuses_when_provider_is_not_configured(): void
    {
        $fake = new FakeSmsProvider(configured: false);
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        $this->expectException(DomainException::class);
        $this->otp()->issue(User::factory()->create(), '01712345678', OtpChallenge::PURPOSE_LINK);
    }
}

/**
 * Deterministic in-memory SMS provider double.
 */
class FakeSmsProvider implements PhoneOtpProviderInterface
{
    public ?string $lastPhone = null;

    public ?string $lastCode = null;

    public function __construct(protected bool $configured = true) {}

    public function id(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->configured) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        $this->lastPhone = $phone;
        $this->lastCode = $code;
    }
}
