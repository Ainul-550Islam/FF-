<?php

namespace Tests\Feature;

use App\Contracts\GoogleOAuthProviderInterface;
use App\Models\User;
use App\Models\UserIdentity;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

/**
 * Phase 14 — Google Sign-In orchestration with a deterministic fake OAuth
 * provider (tests never touch the network).
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A deterministic Google provider double.
     */
    protected function bindFakeGoogle(array $user, bool $configured = true): FakeGoogleProvider
    {
        $fake = new FakeGoogleProvider($user, $configured);
        $this->app->instance(GoogleOAuthProviderInterface::class, $fake);

        return $fake;
    }

    protected function addIdentity(User $user, string $subject, string $email, ?Carbon $verifiedAt = null): void
    {
        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'google';
        $identity->provider_subject = $subject;
        $identity->provider_email = $email;
        $identity->verified_at = $verifiedAt;
        $identity->save();
    }

    public function test_google_redirect_is_offered_when_configured(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-1', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $response = $this->get(route('google.redirect'));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_google_redirect_is_honest_when_not_configured(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-1', 'email' => null, 'email_verified' => false, 'name' => null], configured: false);

        $this->get(route('google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_google_callback_creates_a_new_account(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-123', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam Rahman']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $identity = UserIdentity::where('provider', 'google')->where('provider_subject', 'sub-123')->first();
        $this->assertNotNull($identity);
        $this->assertTrue($identity->isVerified());

        $user = $identity->user;
        $this->assertSame('alam@gmail.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password); // Google-only account, no password
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_callback_signs_into_existing_linked_account(): void
    {
        $existing = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $existing->account_status = 'active';
        $existing->save();
        $this->addIdentity($existing, 'sub-123', 'alam@gmail.com', now());

        $this->bindFakeGoogle(['id' => 'sub-123', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count(), 'No duplicate account is created.');
    }

    public function test_google_callback_links_into_existing_verified_email_account(): void
    {
        // Account with the same verified email but no Google link yet.
        $existing = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $existing->account_status = 'active';
        $existing->save();

        $this->bindFakeGoogle(['id' => 'sub-999', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count(), 'No silent duplicate account.');
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_subject' => 'sub-999',
        ]);
    }

    public function test_one_google_subject_can_never_link_to_two_users(): void
    {
        $first = User::factory()->create(['email' => 'one@example.com', 'email_verified_at' => now()]);
        $second = User::factory()->create(['email' => 'two@example.com', 'email_verified_at' => now()]);

        $this->addIdentity($first, 'sub-shared', 'one@example.com', now());

        $this->bindFakeGoogle(['id' => 'sub-shared', 'email' => 'two@example.com', 'email_verified' => true, 'name' => 'Two']);

        // The callback must sign into the FIRST account (subject owner), not
        // link the subject to a second user.
        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($first);
        $this->assertSame(2, User::count(), 'Both pre-existing users remain; no third is created.');
        $this->assertSame(1, UserIdentity::where('provider_subject', 'sub-shared')->count());
    }

    public function test_authenticated_user_can_link_google(): void
    {
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $user->account_status = 'active';
        $user->save();

        $this->bindFakeGoogle(['id' => 'sub-777', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->actingAs($user)
            ->withSession(['google_link_intent' => true])
            ->get(route('google.callback'))
            ->assertRedirect(route('settings.connected-accounts'));

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'sub-777',
        ]);
    }

    public function test_user_can_unlink_google_when_another_method_exists(): void
    {
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'password' => 'hashed']);
        $user->account_status = 'active';
        $user->save();
        $this->addIdentity($user, 'sub-777', 'alam@gmail.com', now());

        $this->actingAs($user)->post(route('settings.google.unlink'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_user_cannot_unlink_their_last_sign_in_method(): void
    {
        // Google-only account: no password, no other identity.
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'password' => null]);
        $user->account_status = 'active';
        $user->save();
        $this->addIdentity($user, 'sub-777', 'alam@gmail.com', now());

        $this->actingAs($user)->post(route('settings.google.unlink'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }
}

/**
 * Deterministic fake Google OAuth provider.
 */
class FakeGoogleProvider implements GoogleOAuthProviderInterface
{
    public function __construct(
        protected array $user,
        protected bool $configured,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function redirect(): RedirectResponse
    {
        if (! $this->configured) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?client_id=fake');
    }

    public function user(): array
    {
        if (! $this->configured) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return $this->user;
    }
}
