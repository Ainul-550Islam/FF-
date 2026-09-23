<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 14 — email/password auth: signup, login, logout, email verification,
 * password reset (enumeration-safe) and rate limits.
 */
class AccountAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_user_can_register_with_email_and_password(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Alam Rahman',
            'username' => 'alamrahman',
            'email' => 'alam@example.com',
            'phone' => '01712345678',
            'game_uid' => '123456789',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('home'));

        $user = User::where('email', 'alam@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('player', $user->role);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertSame('active', $user->account_status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_reserved_role(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Hacker',
            'username' => 'hacker',
            'email' => 'hacker@example.com',
            'role' => 'admin',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com']);
    }

    public function test_login_succeeds_with_correct_credentials_and_records_history(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_LOGIN_PASSWORD,
            'status' => 'success',
        ]);
    }

    public function test_login_fails_with_wrong_password_and_records_failure(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_LOGIN_FAILED,
            'status' => 'failure',
        ]);
    }

    public function test_login_is_throttled(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_forgot_password_is_enumeration_safe(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $response = $this->post(route('password.email'), ['email' => 'alam@example.com']);
        $response->assertSessionHas('success');

        // A non-existent address gets the identical generic message.
        $response2 = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
        $response2->assertSessionHas('success');
    }

    public function test_password_reset_with_valid_token_changes_password(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('oldsecret')]);

        $token = app('auth.password.broker')->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'alam@example.com',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_password_reset_with_invalid_token_is_rejected(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->post(route('password.update'), [
            'token' => 'bogus',
            'email' => 'alam@example.com',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_verification_signed_url_marks_email_verified(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('alam@example.com')],
        );

        $this->actingAs($user)->get($url)->assertRedirect(route('home'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_EMAIL_VERIFIED,
        ]);
    }

    public function test_email_verification_with_wrong_hash_is_rejected(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('other@example.com')],
        );

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_with_tampered_signature_is_rejected(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('alam@example.com')],
        );

        // Tamper with the id in the path after signing.
        $tampered = str_replace("/verify-email/{$user->id}/", '/verify-email/'.($user->id + 1).'/', $url);

        $this->actingAs($user)->get($tampered)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_verification_sends_a_link_only_for_unverified_users(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $this->actingAs($user)->post(route('verification.resend'))->assertSessionHas('success');
    }

    public function test_verification_notice_redirects_verified_users_home(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('home'));
    }
}
