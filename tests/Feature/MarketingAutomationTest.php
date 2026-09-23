<?php

namespace Tests\Feature;

use App\Models\MarketingAutomation;
use App\Models\MarketingEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MarketingAutomationService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\Push\PushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21 — lifecycle automation.
 *
 * Automations deliver through the existing NotificationService, are
 * idempotent per recipient inside their cooldown window (send ledger =
 * marketing_events), honour the audience filter, and fail quietly. The
 * end-to-end tests prove the three product hooks fire: registration →
 * user.registered, newsletter capture → lead.subscribed, payment failure →
 * payment.failed (without touching the payment state machine).
 */
class MarketingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_automation_sends_through_notification_service(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('welcome-drip', 'user.registered', [
            'type' => Notification::TYPE_MARKETING,
            'title' => 'Welcome to the arena!',
            'body' => 'Your first tournament awaits.',
        ]);

        $sent = $this->automations()->evaluate('user.registered', $user);

        $this->assertSame(1, $sent);

        $notification = Notification::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame(Notification::TYPE_MARKETING, $notification->type);
        $this->assertSame('Welcome to the arena!', $notification->title);

        $ledger = MarketingEvent::query()->where('name', MarketingAutomationService::SEND_EVENT)->first();
        $this->assertNotNull($ledger, 'Every send is recorded in the funnel ledger.');
        $this->assertSame($user->id, $ledger->user_id);
    }

    public function test_repeats_inside_the_cooldown_are_skipped(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('welcome-drip', 'user.registered', null, 24);

        $this->automations()->evaluate('user.registered', $user);
        $sent = $this->automations()->evaluate('user.registered', $user);

        $this->assertSame(0, $sent, 'No duplicate send inside the cooldown window.');
        $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, MarketingEvent::query()->where('name', MarketingAutomationService::SEND_EVENT)->count());
    }

    public function test_send_repeats_after_the_cooldown_expires(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('nudge', 'user.registered', null, 1);

        $this->automations()->evaluate('user.registered', $user);

        // Move the cooldown window into the past.
        $ledger = MarketingEvent::query()->where('name', MarketingAutomationService::SEND_EVENT)->first();
        $ledger->created_at = now()->subHours(2);
        $ledger->save();

        $sent = $this->automations()->evaluate('user.registered', $user);

        $this->assertSame(1, $sent);
        $this->assertSame(2, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_disabled_automations_are_not_evaluated(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('off-drip', 'user.registered', null, 24, false);

        $sent = $this->automations()->evaluate('user.registered', $user);

        $this->assertSame(0, $sent);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_other_triggers_do_not_fire(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('welcome-drip', 'user.registered');

        $sent = $this->automations()->evaluate('payment.failed', $user);

        $this->assertSame(0, $sent);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_audience_filter_scopes_the_recipient(): void
    {
        $player = $this->makeUser('player');
        $organizer = $this->makeUser('organizer');
        $this->makeAutomation('org-only', 'user.registered', null, 24, true, ['role' => 'organizer']);

        $this->assertSame(0, $this->automations()->evaluate('user.registered', $player));
        $this->assertSame(1, $this->automations()->evaluate('user.registered', $organizer));
    }

    public function test_a_broken_send_never_blocks_other_automations_or_throws(): void
    {
        $user = $this->makeUser();
        $this->makeAutomation('welcome-drip', 'user.registered');

        // A delivery backend that is down: send() always throws. evaluate()
        // must swallow the failure per automation (quiet-fail contract) and
        // never let it escape into the auth/payment flow that fired it.
        $this->app->instance(NotificationService::class, new class(app(PushDispatcher::class)) extends NotificationService
        {
            public function send(User $recipient, string $type, string $title, string $body, ?string $link = null, array $data = []): Notification
            {
                throw new \RuntimeException('mail backend down');
            }
        });

        $sent = $this->automations()->evaluate('user.registered', $user);

        $this->assertSame(0, $sent);
        $this->assertSame(0, Notification::query()->count(), 'No notification was written when delivery throws.');
    }

    public function test_an_automation_with_an_empty_recipient_is_ignored(): void
    {
        $this->makeAutomation('welcome-drip', 'user.registered');

        $this->assertSame(0, $this->automations()->evaluate('user.registered', null));
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_registration_fires_the_user_registered_hook_end_to_end(): void
    {
        $this->makeAutomation('welcome-drip', MarketingAutomation::TRIGGER_USER_REGISTERED);

        $this->post('/register', [
            'name' => 'Fresh Player',
            'username' => 'freshplayer',
            'email' => 'fresh@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect();

        $user = User::query()->where('email', 'fresh@example.com')->first();
        $this->assertNotNull($user);

        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', Notification::TYPE_MARKETING)
            ->first();
        $this->assertNotNull($notification, 'The welcome drip fired on real registration.');

        $ledger = MarketingEvent::query()
            ->where('name', MarketingAutomationService::SEND_EVENT)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($ledger, 'The send is cooldown-ledgered.');
    }

    public function test_lead_capture_fires_the_lead_subscribed_hook_end_to_end(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->makeAutomation('lead-welcome', MarketingAutomation::TRIGGER_LEAD_SUBSCRIBED);

        $this->post('/newsletter', ['email' => 'lead@example.com', 'name' => 'Lead Larry'])
            ->assertRedirect();

        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', Notification::TYPE_MARKETING)
            ->first();
        $this->assertNotNull($notification, 'The lead automation fired on newsletter capture.');
    }

    public function test_a_failed_payment_fires_the_payment_failed_hook_end_to_end(): void
    {
        $captain = $this->makeUser('player');
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');

        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain);
        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX'.Str::upper(Str::random(6)));

        $this->makeAutomation('recovery-nudge', MarketingAutomation::TRIGGER_PAYMENT_FAILED);

        app(PaymentService::class)->markFailed($payment, $admin, 'integration test');

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status, 'The payment state machine is untouched by marketing.');

        $marketing = Notification::query()
            ->where('user_id', $captain->id)
            ->where('type', Notification::TYPE_MARKETING)
            ->first();
        $this->assertNotNull($marketing, 'The recovery automation fired on payment failure.');

        $paymentFailed = Notification::query()
            ->where('user_id', $captain->id)
            ->where('type', Notification::TYPE_PAYMENT_FAILED)
            ->first();
        $this->assertNotNull($paymentFailed, 'The existing payment-failure notification still fires.');
    }

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeAutomation(string $key, string $trigger, ?array $action = null, int $cooldown = 24, bool $enabled = true, ?array $audience = null): MarketingAutomation
    {
        return MarketingAutomation::create([
            'key' => $key,
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'trigger' => $trigger,
            'audience' => $audience,
            'action' => $action ?? [
                'type' => Notification::TYPE_MARKETING,
                'title' => 'Hello from FF Arena',
                'body' => 'A quick nudge about your tournaments.',
            ],
            'cooldown_hours' => $cooldown,
            'enabled' => $enabled,
        ]);
    }

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Automation Tournament';
        $t->slug = 'auto-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'pending';
        $team->save();

        return $team;
    }

    protected function automations(): MarketingAutomationService
    {
        return app(MarketingAutomationService::class);
    }
}
