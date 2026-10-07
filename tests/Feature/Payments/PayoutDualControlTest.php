<?php

namespace Tests\Feature\Payments;

use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PrizeDistribution;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A4 — payout defence in depth.
 *
 *  - Maker-checker: an administrator who approves a payout through the payout
 *    queue cannot also disburse / complete it. The maker is read from the
 *    payout's own approval event, and every refused attempt is recorded.
 *  - Reviewed reference: a manual completion always requires a bounded,
 *    reviewed external reference — prize payouts included.
 *  - The audit row written by the admin payout endpoint is part of the same
 *    transaction as the money move (see PayoutController::transition()).
 *
 * The gate is deliberately scoped to queue approvals: batch payouts created
 * already-approved by PrizeDistributionService carry no queue maker (their
 * maker is the separately audited distribution approval) and are covered by
 * PayoutDualControlTest::test_batch_distribution_payouts_are_not_gated_by_the_queue_maker().
 */
class PayoutDualControlTest extends TestCase
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

    protected function makeTournament(User $organizer): Tournament
    {
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Dual Control Cup';
        $tournament->slug = 'dual-'.Str::lower(Str::random(8));
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

    protected function makeDistribution(Tournament $tournament): PrizeDistribution
    {
        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = 'draft';
        $distribution->pool_minor = 5000;
        $distribution->total_allocated_minor = 5000;
        $distribution->save();

        return $distribution;
    }

    /**
     * A payout that arrived through the payout queue: created pending, then
     * approved by $approver through PayoutService::approve().
     */
    protected function queuedPayout(Tournament $tournament, User $recipient, User $approver, string $provider = 'wallet', int $amountMinor = 5000): Payout
    {
        $distribution = $this->makeDistribution($tournament);

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 1;
        $payout->amount_minor = $amountMinor;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_PENDING;
        $payout->payout_method = $provider === 'wallet' ? Payout::METHOD_WALLET : Payout::METHOD_MANUAL;
        $payout->provider = $provider;
        $payout->idempotency_key = (string) Str::uuid();
        $payout->save();

        app(PayoutService::class)->approve($payout, $approver);

        return $payout->fresh();
    }

    // ------------------------------------------------------------------
    // Maker-checker
    // ------------------------------------------------------------------

    public function test_the_approver_cannot_also_process_the_payout(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $admin);

        try {
            app(PayoutService::class)->process($payout, $admin);
            $this->fail('The approver must not be able to process the payout.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // Nothing moved: no wallet credit, no ledger row, payout still approved.
        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
        $this->assertNull($recipient->wallet()->first());
        $this->assertSame(0, LedgerEntry::where('reference_type', 'payout')->where('reference_id', $payout->id)->count());

        // The refused attempt is auditable.
        $violation = PayoutEvent::where('payout_id', $payout->id)
            ->where('event', PayoutEvent::EVENT_PROCESSING)
            ->get()
            ->first(fn (PayoutEvent $event) => (bool) ($event->metadata['dual_control_violation'] ?? false));

        $this->assertNotNull($violation, 'A refused maker-checker attempt must be recorded.');
        $this->assertSame($admin->id, (int) $violation->metadata['maker_user_id']);
        $this->assertSame($admin->id, (int) $violation->metadata['checker_user_id']);
        $this->assertSame('process', $violation->metadata['stage']);
    }

    public function test_a_second_administrator_can_process_the_payout(): void
    {
        $organizer = $this->makeUser('organizer');
        $approver = $this->makeUser('admin');
        $checker = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $approver);

        $processed = app(PayoutService::class)->process($payout, $checker);

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertSame($checker->id, (int) $processed->processed_by);
        $this->assertSame($approver->id, (int) $processed->approved_by);
        $this->assertSame(5000, $recipient->wallet()->first()->balance_minor);

        $completed = PayoutEvent::where('payout_id', $payout->id)
            ->where('event', PayoutEvent::EVENT_COMPLETED)
            ->firstOrFail();

        $this->assertSame($checker->id, (int) $completed->actor_id);
    }

    public function test_the_approver_cannot_override_process_the_payout(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $admin);

        try {
            app(PayoutService::class)->processWithOverride($payout, $admin, 'cleared by review');
            $this->fail('The approver must not be able to override-process the payout.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
    }

    public function test_the_approver_cannot_complete_a_manual_payout(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $admin, 'manual');
        app(PayoutService::class)->process($payout, $this->makeUser('admin'));

        $payout->refresh();
        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);

        try {
            app(PayoutService::class)->completeManually($payout, $admin, 'BANK-REF-1');
            $this->fail('The approver must not be able to complete the payout.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame(Payout::STATUS_PROCESSING, $payout->fresh()->status);
    }

    public function test_the_threshold_can_relax_dual_control_for_small_payouts(): void
    {
        config()->set('payments.payout_dual_control_threshold_minor', 100000);

        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        // 5,000 minor is below the 100,000 minor threshold, so the approver may
        // process it; the same payout was refused at the default threshold of 0.
        $payout = $this->queuedPayout($tournament, $recipient, $admin, 'wallet', 5000);

        $processed = app(PayoutService::class)->process($payout, $admin);

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
    }

    public function test_batch_distribution_payouts_are_not_gated_by_the_queue_maker(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $distribution = $this->makeDistribution($tournament);

        // Already approved at creation by the distribution approval — the
        // maker is the distribution, not the payout queue.
        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 1;
        $payout->amount_minor = 5000;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_APPROVED;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->approved_by = $admin->id;
        $payout->idempotency_key = (string) Str::uuid();
        $payout->save();

        $processed = app(PayoutService::class)->process($payout, $admin);

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
    }

    // ------------------------------------------------------------------
    // Reviewed reference
    // ------------------------------------------------------------------

    public function test_manual_completion_requires_a_reference_for_a_prize_payout(): void
    {
        $organizer = $this->makeUser('organizer');
        $approver = $this->makeUser('admin');
        $checker = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        // Provider 'manual' is an external gateway: it moves to
        // processing and is closed by hand with a reviewed reference.
        $payout = $this->queuedPayout($tournament, $recipient, $approver, 'manual', 250000);
        app(PayoutService::class)->process($payout, $checker);

        $this->assertSame(Payout::STATUS_PROCESSING, $payout->fresh()->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A reviewed external reference is required');

        app(PayoutService::class)->completeManually($payout->fresh(), $checker, null);
    }

    public function test_manual_completion_rejects_an_unbounded_reference(): void
    {
        config()->set('payments.payout_reference_max_length', 255);

        $organizer = $this->makeUser('organizer');
        $approver = $this->makeUser('admin');
        $checker = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $approver, 'manual', 250000);
        app(PayoutService::class)->process($payout, $checker);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('at most 255 characters');

        app(PayoutService::class)->completeManually($payout->fresh(), $checker, str_repeat('R', 256));
    }

    public function test_manual_completion_records_the_reviewed_reference_and_both_actors(): void
    {
        $organizer = $this->makeUser('organizer');
        $approver = $this->makeUser('admin');
        $checker = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $approver, 'manual', 250000);
        app(PayoutService::class)->process($payout, $checker);

        $completed = app(PayoutService::class)->completeManually($payout->fresh(), $checker, '  BANK-TRX-987654  ');

        $this->assertSame(Payout::STATUS_COMPLETED, $completed->status);
        $this->assertSame('BANK-TRX-987654', $completed->provider_reference);

        $event = PayoutEvent::where('payout_id', $payout->id)
            ->where('event', PayoutEvent::EVENT_COMPLETED)
            ->firstOrFail();

        $this->assertSame('BANK-TRX-987654', $event->metadata['reference']);
        $this->assertSame($approver->id, (int) $event->metadata['maker_user_id']);
        $this->assertSame($checker->id, (int) $event->metadata['checker_user_id']);
    }

    // ------------------------------------------------------------------
    // The admin endpoint moves the audit row into the money transaction
    // ------------------------------------------------------------------

    public function test_admin_approval_writes_the_audit_row_in_the_same_transaction(): void
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $distribution = $this->makeDistribution($tournament);

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 2;
        $payout->amount_minor = 2500;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_PENDING;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->save();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.payouts.approve', $payout))
            ->assertRedirect();

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payout.approved',
            'entity_type' => 'payout',
            'entity_id' => $payout->id,
            'actor_user_id' => $admin->id,
            'tournament_id' => $tournament->id,
        ]);
    }

    public function test_a_second_admin_cannot_process_a_payout_approved_by_the_first_until_they_are_the_checker(): void
    {
        $organizer = $this->makeUser('organizer');
        $approver = $this->makeUser('admin');
        $checker = $this->makeUser('admin');
        $recipient = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payout = $this->queuedPayout($tournament, $recipient, $approver);

        // The endpoint refuses the maker …
        $this->actingAs($approver)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.payouts.process', $payout))
            ->assertSessionHas('error');

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);

        // … and accepts the checker.
        $this->actingAs($checker)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.payouts.process', $payout))
            ->assertSessionHas('success');

        $this->assertSame(Payout::STATUS_COMPLETED, $payout->fresh()->status);
    }
}
