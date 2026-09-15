<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 14 — profiles: public profile privacy, editing, username rules,
 * preferences, privacy presets and password change.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_public_profile_is_visible_to_guests(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Hello', 'country' => 'BD']);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Hello');
    }

    public function test_registered_profile_is_hidden_from_guests(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Hello']);
        $user->privacy = 'registered';
        $user->save();

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('private', false);
    }

    public function test_private_profile_is_hidden_from_other_users(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Secret']);
        $user->privacy = 'private';
        $user->save();

        $other = $this->makeUser();

        $this->actingAs($other)->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee('Secret');
    }

    public function test_private_profile_is_visible_to_its_owner(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Secret']);
        $user->privacy = 'private';
        $user->save();

        $this->actingAs($user)->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Secret');
    }

    public function test_profile_is_editable_by_owner(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'New Name',
            'bio' => 'New bio',
            'country' => 'BD',
            'region' => 'Dhaka',
            'avatar' => 'https://example.com/a.png',
        ])->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('New bio', $user->bio);
        $this->assertSame('BD', $user->country);
    }

    public function test_user_cannot_edit_someone_elses_profile(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->actingAs($a)->put(route('profile.update'), ['name' => 'Hijacked'])
            ->assertSessionHas('success');

        // `a` edited only their own profile.
        $this->assertSame('Hijacked', $a->fresh()->name);
        $this->assertNotSame('Hijacked', $b->fresh()->name);
    }

    public function test_username_change_enforces_rules_and_reserved_names(): void
    {
        $user = $this->makeUser(attrs: ['username' => 'alam']);

        $this->actingAs($user)->put(route('profile.username'), ['username' => 'admin'])
            ->assertSessionHas('error');
    }

    public function test_username_must_be_unique(): void
    {
        $this->makeUser(attrs: ['username' => 'taken']);
        $user = $this->makeUser(attrs: ['username' => 'alam']);

        $this->actingAs($user)->put(route('profile.username'), ['username' => 'taken'])
            ->assertSessionHas('error');
    }

    public function test_privacy_update_persists(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->put(route('profile.privacy'), ['privacy' => 'private'])
            ->assertSessionHas('success');

        $this->assertSame('private', $user->fresh()->privacy);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->makeUser(attrs: ['password' => Hash::make('oldsecret')]);

        $this->actingAs($user)->post(route('settings.password'), [
            'current_password' => 'wrong',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHas('error');
    }

    public function test_password_change_succeeds_with_current_password(): void
    {
        $user = $this->makeUser(attrs: ['password' => Hash::make('oldsecret')]);

        $this->actingAs($user)->post(route('settings.password'), [
            'current_password' => 'oldsecret',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_role_cannot_be_changed_through_profile_endpoints(): void
    {
        $user = $this->makeUser('player');

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'X',
            'role' => 'admin',
        ])->assertSessionHas('success');

        $this->assertSame('player', $user->fresh()->role);
    }
}
