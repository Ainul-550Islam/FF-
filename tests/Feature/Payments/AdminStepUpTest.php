<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A4 — step-up authentication on admin money actions.
 *
 * Payout approve / process / process-override / complete / fail / cancel and
 * admin refunds require a recent password confirmation
 * (`auth.password_confirmed_at` in the session, the window defined by
 * `config('auth.password_timeout')`). Without it the action is refused and the
 * administrator is sent to the confirmation screen; with it the action runs.
 *
 * The middleware fails closed: a stale confirmation, a missing one, or a
 * non-admin session never reaches the money path.
 */
class AdminStepUpTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', string $password = 'secret-password'): User
    {
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer): Tournament
    {
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Step Up Cup';
        $tournament->slug = 'stepup-'.Str::lower(Str::random(8));
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = Tournament::STATUS_FINISHED;
        $tournament->save();

        return $tournament;
    }

    protected function makePayout(Tournament $tournament, User $recipient): Payout
    {
        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = 'draft';
        $distribution->pool_minor = 5000;
        $distribution->total_allocated_minor = 5000;
        $distribution->save();

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 1;
        $payout->amount_minor = 5000;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_PENDING;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->save();

        return $payout;
    }

    protected function makeSettledPayment(Tournament $tournament, User $payer): Payment
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $payer->id;
        $team->name = 'Step Up Team';
        $team->captain_name = $payer->name;
        $team->phone = '01700000000';
        $team->game_uid = 'STEPUP'.strtoupper(Str::random(4));
        $team->status = Team::STATUS_CONFIRMED;
        $team->save();

        $payment = new Payment();
        $payment->tournament_id = $tournament->id;
        $payment->team_id = $team->id;
        $payment->payer_user_id = $payer->id;
        $payment->amount = 100;
        $payment->amount_minor = 10000;
        $payment->currency = 'BDT';
        $payment->method = 'bkash';
        $payment->trx_id = 'STEPUP'.strtoupper(Str::random(6));
        $payment->provider = 'bkash';
        $payment->provider_reference = $payment->trx_id;
        $payment->idempotency_key = (string) Str::uuid();
        $payment->status = Payment::STATUS_VERIFIED;
        $payment->save();

        return $payment;
    }

    // ------------------------------------------------------------------
    // Payout actions
    // ------------------------------------------------------------------

    public function test_payout_approval_without_recent_confirmation_is_refused(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        $this->actingAs($admin)
            ->post(route('admin.payouts.approve', $payout))
            ->assertRedirect(route('password.confirm'));

        // Fail closed — the payout is untouched.
        $this->assertSame(Payout::STATUS_PENDING, $payout->fresh()->status);
    }

    public function test_payout_approval_with_a_stale_confirmation_is_refused(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        $this->actingAs($admin)
            ->withSession([
                // Just outside the configured window.
                'auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 60,
            ])
            ->post(route('admin.payouts.approve', $payout))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(Payout::STATUS_PENDING, $payout->fresh()->status);
    }

    public function test_confirming_the_password_unlocks_the_payout_action(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin', 'secret-password');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        // 1. The action redirects to the confirmation screen.
        $this->actingAs($admin)
            ->post(route('admin.payouts.approve', $payout))
            ->assertRedirect(route('password.confirm'));

        // 2. The administrator confirms their password.
        $this->actingAs($admin)
            ->post(route('password.confirm.store'), ['password' => 'secret-password'])
            ->assertRedirect();

        // 3. The action now runs in the same session.
        $this->actingAs($admin)
            ->post(route('admin.payouts.approve', $payout))
            ->assertSessionHas('success');

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
    }

    public function test_a_wrong_password_does_not_unlock_the_action(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin', 'secret-password');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        $this->actingAs($admin)
            ->post(route('password.confirm.store'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)
            ->post(route('admin.payouts.approve', $payout))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(Payout::STATUS_PENDING, $payout->fresh()->status);
    }

    public function test_every_money_action_route_is_behind_step_up(): void
    {
        $routes = [
            'admin.payouts.approve',
            'admin.payouts.process',
            'admin.payouts.process_override',
            'admin.payouts.complete',
            'admin.payouts.fail',
            'admin.payouts.cancel',
        ];

        foreach ($routes as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] must exist.");

            $this->assertContains(
                'password.recent',
                $route->gatherMiddleware(),
                "Route [{$name}] must be behind the password.recent step-up middleware."
            );
        }
    }

    public function test_admin_refund_is_behind_step_up(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $payer = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payment = $this->makeSettledPayment($tournament, $payer);

        $this->actingAs($admin)
            ->post(route('admin.payments.refund', $payment), ['reason' => 'Goodwill'])
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_json_clients_receive_423_instead_of_a_redirect(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        $this->actingAs($admin)
            ->postJson(route('admin.payouts.approve', $payout))
            ->assertStatus(423)
            ->assertJsonPath('error', 'password_confirmation_required');

        $this->assertSame(Payout::STATUS_PENDING, $payout->fresh()->status);
    }

    public function test_non_admins_are_still_forbidden_before_step_up(): void
    {
        $organizer = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $payout = $this->makePayout($tournament, $recipient);

        // Authorization runs first: a player is refused outright (403), never
        // sent to a confirmation screen they could satisfy.
        $this->actingAs($player)
            ->post(route('admin.payouts.approve', $payout))
            ->assertForbidden();
    }
}
