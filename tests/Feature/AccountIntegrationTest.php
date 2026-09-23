<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 — cross-cutting integration: notifications, audit log and the
 * user-targeted realtime feed all react to account activity, without leaking
 * anything sensitive.
 */
class AccountIntegrationTest extends TestCase
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

    public function test_registration_writes_audit_and_welcome_notification(): void
    {
        $this->post(route('register'), [
            'name' => 'Alam',
            'username' => 'alam',
            'email' => 'alam@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $user = User::where('email', 'alam@example.com')->first();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.register',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => Notification::TYPE_WELCOME,
        ]);
    }

    public function test_login_records_login_event_and_audit(): void
    {
        $user = $this->makeUser();
        $user->email = 'alam@example.com';
        $user->password = 'secret123';
        $user->save();

        // Force a fresh password hash.
        $user->password = Hash::make('secret123');
        $user->save();

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123']);

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => 'login.password',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
        ]);
    }

    public function test_account_live_feed_is_self_only(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $event = new LiveEvent();
        $event->target_user_id = $user->id;
        $event->type = LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS;
        $event->payload = ['payment_id' => 1, 'status' => 'pending'];
        $event->save();

        $this->actingAs($user)->getJson(route('account.live'))->assertOk();

        $this->actingAs($other)->getJson(route('account.live'))
            ->assertOk()
            ->assertJsonCount(0, 'events');
    }

    public function test_account_live_feed_uses_cursor(): void
    {
        $user = $this->makeUser();

        $event = new LiveEvent();
        $event->target_user_id = $user->id;
        $event->type = LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS;
        $event->payload = ['status' => 'pending'];
        $event->save();

        $response = $this->actingAs($user)->getJson(route('account.live'));
        $response->assertOk();
        // The cursor is the max event id (monotonic). PostgreSQL sequences do
        // not roll back with the test transaction, so assert against the
        // actual event id rather than assuming it is 1.
        $this->assertSame($event->id, $response->json('revision'));

        // Only events newer than the cursor come back.
        $later = $this->actingAs($user)->getJson(route('account.live').'?since='.$event->id);
        $later->assertOk()->assertJsonCount(0, 'events');
    }

    public function test_payment_initiation_notifies_and_live_events_the_payer(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();

        $tournament = new Tournament();
        $tournament->organizer_id = $org->id;
        $tournament->name = 'T';
        $tournament->slug = 't-'.Str::random(6);
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 1000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = 'open';
        $tournament->save();

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.Str::random(6);
        $team->status = Team::STATUS_PENDING;
        $team->save();

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
            'trx_id' => 'TRX42',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $captain->id,
            'type' => Notification::TYPE_PAYMENT_INITIATED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $captain->id,
            'action' => 'payment.initiated',
        ]);
        $this->assertDatabaseHas('live_events', [
            'target_user_id' => $captain->id,
            'type' => LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS,
        ]);
    }

    public function test_session_revocation_emits_a_user_live_event(): void
    {
        $user = $this->makeUser();

        DB::table('sessions')->insert([
            'id' => 'sess-x',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'x',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->post(route('settings.sessions.revokeOthers'));

        $this->assertDatabaseHas('live_events', [
            'target_user_id' => $user->id,
            'type' => LiveEvent::TYPE_ACCOUNT_SESSION_REVOKED,
        ]);
    }
}
