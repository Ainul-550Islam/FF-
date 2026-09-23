<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 — security settings: active sessions, login history, account
 * lifecycle (deactivate/reactivate/deletion) and the active-account gate.
 */
class AccountSecurityTest extends TestCase
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

    protected function seedSession(User $user, string $id, int $lastActivity, string $ua = 'Mozilla/5.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => $ua,
            'payload' => 'x',
            'last_activity' => $lastActivity,
        ]);
    }

    protected function addLoginEvent(User $user, string $event, array $extra = []): void
    {
        $entry = new LoginEvent();
        $entry->user_id = $user->id;
        $entry->event = $event;
        $entry->status = $extra['status'] ?? 'success';
        $entry->ip_hash = $extra['ip_hash'] ?? null;
        $entry->device_label = $extra['device_label'] ?? null;
        $entry->save();
    }

    public function test_security_page_is_self_only(): void
    {
        $user = $this->makeUser();

        $this->get(route('settings.security'))->assertRedirect(route('login'));

        $this->actingAs($user)->get(route('settings.security'))->assertOk();
    }

    public function test_sessions_page_lists_only_own_sessions(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->seedSession($user, 'sess-a', now()->timestamp, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0');
        $this->seedSession($other, 'sess-b', now()->timestamp, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');

        $this->actingAs($user)->get(route('settings.sessions'))
            ->assertOk()
            ->assertSee('Firefox on Linux')
            ->assertDontSee('Safari on iOS');
    }

    public function test_revoke_other_sessions_keeps_current(): void
    {
        $user = $this->makeUser();

        $this->seedSession($user, 'sess-a', now()->timestamp);
        $this->seedSession($user, 'sess-b', now()->timestamp);

        $this->actingAs($user)->post(route('settings.sessions.revokeOthers'))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_revoke_all_sessions_logs_user_out(): void
    {
        $user = $this->makeUser();
        $this->seedSession($user, 'sess-a', now()->timestamp);

        $this->actingAs($user)->post(route('settings.sessions.revokeAll'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_login_history_is_self_only(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->addLoginEvent($user, 'login.password');
        $this->addLoginEvent($other, 'account.linked');

        $this->actingAs($user)->get(route('settings.login-history'))
            ->assertOk()
            ->assertSee('Login Password')
            ->assertDontSee('Account Linked');
    }

    public function test_login_history_never_contains_raw_ips_or_passwords(): void
    {
        $user = $this->makeUser();
        $this->addLoginEvent($user, 'login.password', [
            'ip_hash' => hash_hmac('sha256', '203.0.113.9', 'secret'),
            'device_label' => 'Chrome on Linux',
        ]);

        $html = $this->actingAs($user)->get(route('settings.login-history'))->content();

        $this->assertStringNotContainsString('203.0.113.9', $html);
    }

    public function test_deactivation_revokes_sessions_and_signs_out(): void
    {
        $user = $this->makeUser();
        $this->seedSession($user, 'sess-a', now()->timestamp);

        $this->actingAs($user)->post(route('settings.deactivate'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('deactivated', $user->fresh()->account_status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_ACCOUNT_DEACTIVATED,
        ]);
    }

    public function test_reactivation_restores_active_status(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->post(route('settings.reactivate'))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }

    public function test_deactivated_user_is_redirected_to_security_settings(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->get(route('home'))
            ->assertRedirect(route('settings.security'));
    }

    public function test_deactivated_user_can_still_reach_security_settings(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->get(route('settings.security'))->assertOk();
    }

    public function test_deletion_request_is_blocked_while_payments_are_pending(): void
    {
        $user = $this->makeUser();
        $org = $this->makeUser('organizer');

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
        $team->captain_id = $user->id;
        $team->name = 'Team';
        $team->captain_name = $user->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.Str::random(6);
        $team->status = Team::STATUS_PENDING;
        $team->save();

        $payment = new Payment();
        $payment->tournament_id = $tournament->id;
        $payment->team_id = $team->id;
        $payment->payer_user_id = $user->id;
        $payment->amount_minor = 10000;
        $payment->amount = '100.00';
        $payment->currency = 'BDT';
        $payment->method = 'bkash';
        $payment->trx_id = 'TRX1';
        $payment->provider = 'bkash';
        $payment->provider_reference = 'TRX1';
        $payment->idempotency_key = (string) Str::uuid();
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        $this->actingAs($user)->post(route('settings.deletion.request'))
            ->assertSessionHas('error');

        $this->assertNotSame('deletion_pending', $user->fresh()->account_status);
    }

    public function test_deletion_request_succeeds_when_clear(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.deletion.request'))
            ->assertSessionHas('success');

        $this->assertSame('deletion_pending', $user->fresh()->account_status);
    }

    public function test_deletion_request_can_be_cancelled(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deletion_pending';
        $user->save();

        $this->actingAs($user)->post(route('settings.deletion.cancel'))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }
}
