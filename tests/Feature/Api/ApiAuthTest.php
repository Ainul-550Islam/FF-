<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserIdentity;

/**
 * Phase 15 — API authentication: register, login, Google, OTP, token
 * issuance and revocation, deactivated-account denial.
 */
class ApiAuthTest extends ApiTestCase
{
    public function test_register_creates_account_and_returns_token_once(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.user.role', 'player');

        $this->assertIsString($res->json('data.token'));
        $this->assertArrayNotHasKey('password', $res->json('data.user'));

        // The token authenticates.
        $this->authForget();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/me')->assertStatus(200);

        $this->assertSame(1, User::where('email', 'alice@example.com')->count());
    }

    public function test_register_rejects_admin_role_and_mass_assignment(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'username' => 'mallory',
            'email' => 'mallory@example.com',
            'role' => 'admin',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422);

        $this->assertSame(0, User::where('email', 'mallory@example.com')->count());
    }

    public function test_login_succeeds_and_failed_login_is_enumeration_safe(): void
    {
        $this->user(['email' => 'bob@example.com', 'password' => bcrypt('secret123')]);

        $ok = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $ok->assertStatus(200)->assertJsonPath('data.user.email', 'bob@example.com');
        $this->assertIsString($ok->json('data.token'));

        // Wrong password + unknown email → identical 401 payload.
        $badPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame($badPassword->json('error.code'), $unknownEmail->json('error.code'));
        $this->assertSame('invalid_credentials', $unknownEmail->json('error.code'));
    }

    public function test_deactivated_account_cannot_authenticate(): void
    {
        $user = $this->user(['email' => 'gone@example.com', 'password' => bcrypt('secret123'), 'account_status' => 'deactivated']);
        $token = $this->tokenFor($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'gone@example.com',
            'password' => 'secret123',
        ])->assertStatus(401)->assertJsonPath('error.code', 'account_inactive');

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_google_login_creates_then_reuses_account(): void
    {
        $this->bindFakeGoogle(['id' => 'g-123', 'email' => 'google@example.com', 'email_verified' => true, 'name' => 'Google User']);

        $first = $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-1']);
        $first->assertStatus(201)->assertJsonPath('data.created', true);

        $second = $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-1']);
        $second->assertStatus(200)->assertJsonPath('data.created', false);

        $this->assertSame(1, User::where('email', 'google@example.com')->count());
    }

    public function test_google_login_unconfigured_is_honest(): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new ApiFakeGoogleVerifier(
            ['id' => 'g', 'email' => null, 'email_verified' => false, 'name' => null],
            configured: false,
        ));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'not_configured');
    }

    public function test_phone_otp_login_round_trip(): void
    {
        $sms = $this->bindFakeSms();

        // Link a phone to a user up front (verified identity).
        $user = $this->user(['phone' => '+8801712345678']);
        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'phone';
        $identity->provider_subject = '+8801712345678';
        $identity->verified_at = now();
        $identity->save();

        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '01712345678',
            'purpose' => 'login',
        ])->assertStatus(200);

        $code = $sms->lastCodeFor('+8801712345678');
        $this->assertNotNull($code);

        $verify = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '01712345678',
            'purpose' => 'login',
            'code' => $code,
        ]);

        $verify->assertStatus(200)->assertJsonPath('data.user.id', $user->id);
        $this->assertIsString($verify->json('data.token'));
    }

    public function test_revoked_token_stops_working(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $tokenId = $user->tokens()->first()->id;

        $this->authForget();
        $this->withToken($token)->deleteJson('/api/v1/me/tokens/'.$tokenId)->assertStatus(204);

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_token_metadata_is_listed_without_plaintext(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user, ['profile:read']);

        $this->authForget();
        $res = $this->withToken($token)->getJson('/api/v1/me/tokens');

        $res->assertStatus(200);
        $this->assertSame('test-token', $res->json('data.0.name'));
        $this->assertSame(['profile:read'], $res->json('data.0.abilities'));
        $this->assertArrayNotHasKey('token', $res->json('data.0'));
    }

    public function test_register_notifies_welcome(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Carol',
            'username' => 'carol',
            'email' => 'carol@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(201);

        $user = User::where('email', 'carol@example.com')->first();
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', Notification::TYPE_WELCOME)->count());
    }
}
