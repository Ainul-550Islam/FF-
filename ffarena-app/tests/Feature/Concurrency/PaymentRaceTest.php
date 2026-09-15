<?php

namespace Tests\Feature\Concurrency;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — payment idempotency race.
 *
 * N concurrent confirmations of the SAME payment race through
 * PaymentService::confirmProviderPayment. The payment must settle exactly
 * once: a single `payment.paid` event, a single team confirmation, and no
 * duplicate wallet/ledger movement. Concurrent duplicates must be recorded as
 * audited duplicates, never as second settlements.
 *
 * Execution model mirrors RegistrationRaceTest: true process-level
 * concurrency on PostgreSQL + pcntl; a sequential fallback (same invariant)
 * on SQLite where parallel writers are not testable.
 */
class PaymentRaceTest extends TestCase
{
    // See RegistrationRaceTest for why we run `migrate:fresh` explicitly
    // instead of using the DatabaseMigrations trait (its teardown rollback
    // fails on SQLite).
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $org = new User();
        $org->name = 'Race Organizer';
        $org->email = 'payrace-org-'.Str::random(6).'@ffarena.local';
        $org->password = bcrypt('secret123');
        $org->email_verified_at = now();
        $org->save();
        $org->role = 'organizer';
        $org->account_status = 'active';
        $org->save();

        $captain = new User();
        $captain->name = 'Race Payer';
        $captain->email = 'payrace-cap-'.Str::random(6).'@ffarena.local';
        $captain->password = bcrypt('secret123');
        $captain->email_verified_at = now();
        $captain->save();
        $captain->role = 'player';
        $captain->account_status = 'active';
        $captain->save();

        $t = new Tournament();
        $t->organizer_id = $org->id;
        $t->name = 'Payment Race';
        $t->slug = 'race-pay-'.Str::lower(Str::random(10));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = Tournament::STATUS_OPEN;
        $t->save();

        $team = new Team();
        $team->tournament_id = $t->id;
        $team->captain_id = $captain->id;
        $team->name = 'Race Pay Team';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'PAYRACE1';
        $team->status = Team::STATUS_PENDING;
        $team->save();

        $this->payment = app(PaymentService::class)->createForTeam(
            $t, $team, $captain, 'bkash', 'RACETRX1', 'bkash', 'RACETRX1',
        );
    }

    public function test_concurrent_confirmation_settles_exactly_once(): void
    {
        $total = 6; // concurrent confirmations of the same payment

        if ($this->canFork()) {
            $this->forkConfirmations($total);
        } else {
            for ($i = 0; $i < $total; $i++) {
                $this->confirmOne($this->payment->id);
            }

            fwrite(STDERR, "[note] payment race ran sequentially — true parallel writers need pcntl + PostgreSQL.\n");
        }

        $payment = Payment::find($this->payment->id);

        // Exactly one settlement, ever.
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->where('event', PaymentEvent::EVENT_PAID)->count());

        // The team is confirmed exactly once.
        $this->assertSame(Team::STATUS_CONFIRMED, $payment->team->fresh()->status);

        // Exactly one non-duplicate gateway confirmation.
        $confirmed = PaymentEvent::where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_GATEWAY_CONFIRMED)
            ->get()
            ->filter(fn (PaymentEvent $e) => ($e->metadata['duplicate'] ?? null) !== true);

        $this->assertCount(1, $confirmed);
    }

    protected function canFork(): bool
    {
        return function_exists('pcntl_fork') && config('database.default') === 'pgsql';
    }

    protected function forkConfirmations(int $total): void
    {
        $pids = [];

        for ($i = 0; $i < $total; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();

                try {
                    $this->confirmOne($this->payment->id);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "child {$i}: ".$e->getMessage()."\n");
                    exit(2);
                }
            }

            $pids[] = $pid;
        }

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame(array_fill(0, $total, 0), $exitCodes, 'all confirmation children must succeed');
    }

    protected function confirmOne(int $paymentId): void
    {
        $payment = Payment::find($paymentId);

        app(PaymentService::class)->confirmProviderPayment($payment, [
            'status' => 'completed',
            'reference' => (string) $payment->provider_reference,
            'currency' => 'BDT',
            'amount' => null, // omitted → no re-check path; idempotency still enforced
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->payment)) {
            DB::table('payment_events')->where('payment_id', $this->payment->id)->delete();
            DB::table('payments')->where('id', $this->payment->id)->delete();
            DB::table('teams')->where('id', $this->payment->team_id)->delete();
            DB::table('tournaments')->where('id', $this->payment->tournament_id)->delete();
            DB::table('users')->where('email', 'like', 'payrace-%@ffarena.local')->delete();
        }

        parent::tearDown();
    }
}
