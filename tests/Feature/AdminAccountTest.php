<?php

namespace Tests\Feature;

use App\Models\Restriction;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 14 — admin account administration: inspect accounts, revoke
 * sessions, deactivate/reactivate/delete; and the authorization matrix that
 * keeps organizers and moderators out of global account controls.
 */
class AdminAccountTest extends TestCase
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

    public function test_admin_can_list_accounts(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser();

        $this->actingAs($admin)->get(route('admin.accounts.index'))->assertOk();
    }

    public function test_organizer_cannot_list_accounts(): void
    {
        $organizer = $this->makeUser('organizer');

        $this->actingAs($organizer)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_moderator_cannot_list_accounts(): void
    {
        $moderator = $this->makeUser('moderator');

        $this->actingAs($moderator)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_admin_can_view_account_detail(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'google';
        $identity->provider_subject = 'sub-1';
        $identity->verified_at = now();
        $identity->save();

        $this->actingAs($admin)->get(route('admin.accounts.show', $user))
            ->assertOk()
            ->assertSee($user->email);
    }

    public function test_organizer_cannot_view_account_detail(): void
    {
        $organizer = $this->makeUser('organizer');
        $user = $this->makeUser();

        $this->actingAs($organizer)->get(route('admin.accounts.show', $user))->assertForbidden();
    }

    public function test_admin_can_revoke_a_users_sessions(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        DB::table('sessions')->insert([
            'id' => 'sess-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'x',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->post(route('admin.accounts.sessions.revoke', $user))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_admin_can_deactivate_and_reactivate_an_account(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $this->actingAs($admin)->post(route('admin.accounts.deactivate', $user))
            ->assertSessionHas('success');

        $this->assertSame('deactivated', $user->fresh()->account_status);

        $this->actingAs($admin)->post(route('admin.accounts.reactivate', $user))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }

    public function test_admin_can_delete_an_account_as_an_anonymized_tombstone(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $this->actingAs($admin)->post(route('admin.accounts.delete', $user))
            ->assertSessionHas('success');

        $deleted = $user->fresh();
        $this->assertSame('deleted', $deleted->account_status);
        $this->assertSame('Deleted User', $deleted->name);
        $this->assertStringContainsString('ffarena.invalid', $deleted->email);
    }

    public function test_admin_cannot_delete_an_account_with_an_active_restriction(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $restriction = new Restriction();
        $restriction->user_id = $user->id;
        $restriction->type = 'payment';
        $restriction->reason = 'Under review';
        $restriction->source = 'admin';
        $restriction->actor_id = $admin->id;
        $restriction->status = Restriction::STATUS_ACTIVE;
        $restriction->save();

        $this->actingAs($admin)->post(route('admin.accounts.delete', $user))
            ->assertSessionHas('error');

        $this->assertSame('active', $user->fresh()->account_status);
    }
}
