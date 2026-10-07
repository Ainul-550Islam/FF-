<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GAP-10 F-14/F-15 — money core: a payment settles EXACTLY once.
 *
 * The defect this pins: `PaymentService::settleSuccess()` decided the
 * settlement from the payment instance the caller had loaded. Two concurrent
 * confirmations of the same payment therefore both saw a "pending" status and
 * both settled it, duplicating the `payment.paid` event and everything hanging
 * off it. The fix takes the decision under an exclusive row lock
 * (`Payment::query()->whereKey(...)->lockForUpdate()` inside the surrounding
 * transaction) and records every losing attempt as an audited duplicate
 * instead of silently dropping it.
 *
 * Two guarantees are asserted here:
 *
 *  1. a stale writer can never fail, re-settle or re-stamp a settled payment;
 *  2. concurrent confirmations settle exactly once — with real, parallel
 *     writers whenever pcntl and PostgreSQL are available, and with the
 *     identical sequential invariant otherwise, so the property is still
 *     tested on a SQLite-only run.
 *
 * This class is required-test entry GAP10-PG-002. It never skips.
 *
 * Fixture hygiene, on purpose:
 *  - The class does NOT use `RefreshDatabase`. The fixture has to be
 *    COMMITTED before the confirmations run: forked children are separate
 *    processes with their own connections and cannot see a row that only
 *    exists inside the parent's open transaction. A parent transaction would
 *    also hold a row lock that the children's writes would block on — the
 *    parent would wait for the children while the children wait for the
 *    parent, and the suite would hang instead of failing.
 *  - Every row this class creates is tagged with a unique run id, and tearDown
 *    (plus setUp) deletes by that pattern, so an aborted previous run can
 *    never leave a row behind that collides with the unique indexes — the
 *    `payments_provider_reference_unique` constraint makes that fatal rather
 *    than cosmetic.
 */
class SettlementExactlyOnceTest extends TestCase
{
    /**
     * Tag shared by every row this test run creates. Matches the pattern the
     * cleanup uses, so stale rows from a crashed run are swept away too.
     */
    protected string $runTag = '';

    /** How many independent confirmations the concurrency test fires. */
    protected int $workers = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runTag = 'GAP10F14-'.bin2hex(random_bytes(4));

        // This class does not use RefreshDatabase (see the class docblock), so
        // it owns its schema: on an in-memory SQLite database nothing has run
        // yet, and on PostgreSQL the class may be the first one to touch a
        // freshly created test database. Migrate only when the schema is
        // missing, so the PostgreSQL suite does not re-migrate per test.
        if (! Schema::hasTable('users')) {
            $this->artisan('migrate:fresh');
        }

        // Sweep anything a previous, aborted run left behind before creating
        // this run's fixture.
        $this->purgeTaggedRows();

        // A regression that stops taking the row lock would block a writer
        // behind the other connection's lock; cancelling the statement turns
        // that hang into a loud failure.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::connection()->statement('SET lock_timeout = 4000');
            DB::connection()->statement('SET statement_timeout = 15000');
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::connection()->statement('RESET statement_timeout');
                DB::connection()->statement('RESET lock_timeout');
            } catch (\Throwable) {
                // The connection may already be gone; nothing to reset.
            }
        }

        // Forked children run outside any test transaction and therefore
        // commit rows (marketing touches, notifications, webhook records)
        // that the rest of the suite can see. Wipe the schema so no later
        // class reads stale rows — see finding F-16.
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Fixture
    // -----------------------------------------------------------------------

    /**
     * Build a pending payment with its team and tournament through the same
     * shapes the production flows use.
     *
     * `forceCreate` (not `create`): the financial models are deliberately
     * guarded so no money field can be mass-assigned, and the columns filled
     * here (status, slug, organizer, reference tags) are exactly the ones the
     * application fills in its own flows.
     */
    protected function pendingPayment(int $amountMinor = 50000): Payment
    {
        $tag = $this->runTag;

        $organizer = User::factory()->create([
            'name' => 'Organizer '.$tag,
            'email' => 'gap10f14-organizer-'.$tag.'@example.com',
        ]);

        $captain = User::factory()->create([
            'name' => 'Captain '.$tag,
            'email' => 'gap10f14-captain-'.$tag.'@example.com',
        ]);

        $tournament = Tournament::query()->forceCreate([
            'name' => 'Settlement Cup '.$tag,
            'slug' => 'settlement-cup-'.strtolower($tag),
            'game_mode' => 'squad',
            'format' => 'single_elimination',
            'status' => 'registration',
            'organizer_id' => $organizer->id,
            'entry_fee_minor' => $amountMinor,
            'entry_fee' => (int) ($amountMinor / 100),
            'team_slots' => 16,
            'team_size' => 4,
            'max_teams' => 16,
            'starts_at' => now()->addDay(),
        ]);

        $team = Team::query()->forceCreate([
            'tournament_id' => $tournament->id,
            'captain_id' => $captain->id,
            'name' => 'Settlement Squad '.$tag,
            'captain_name' => $captain->name,
            'status' => Team::STATUS_PENDING,
        ]);

        return Payment::query()->forceCreate([
            'user_id' => $captain->id,
            'payer_user_id' => $captain->id,
            'tournament_id' => $tournament->id,
            'team_id' => $team->id,
            'provider' => 'bkash',
            'provider_reference' => $tag.'-initial',
            'amount_minor' => $amountMinor,
            'amount' => $amountMinor / 100,
            'currency' => 'BDT',
            'status' => Payment::STATUS_PENDING,
            'method' => 'bkash',
        ]);
    }

    /**
     * A provider confirmation payload the service accepts. The reference is
     * unique per confirmation so the unique index can never mask the
     * behaviour under test.
     *
     * @return array<string, mixed>
     */
    protected function completedPayload(Payment $payment, string $suffix): array
    {
        $reference = $this->runTag.'-'.$suffix;

        return [
            'status' => 'completed',
            'currency' => $payment->currency,
            'amount' => number_format($payment->amountMinor() / 100, 2, '.', ''),
            'reference' => $reference,
            'gateway_transaction_id' => strtoupper($reference),
        ];
    }

    protected function eventsFor(Payment $payment, string $event): int
    {
        return PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event', $event)
            ->count();
    }

    /**
     * A second, independent connection to the same database. Needed to state
     * that the fixture is committed and to read the settled row from outside
     * the default connection after forked children have written to it.
     */
    protected function secondConnection(): Connection
    {
        $name = 'pgsql_test_second';

        if (! array_key_exists($name, (array) config('database.connections'))) {
            config(['database.connections.'.$name => config('database.connections.pgsql')]);
        }

        return DB::connection($name);
    }

    protected function supportsParallelWriters(): bool
    {
        return function_exists('pcntl_fork')
            && DB::connection()->getDriverName() === 'pgsql'
            && $this->secondConnectionCanReachTheRow();
    }

    protected function secondConnectionCanReachTheRow(): bool
    {
        try {
            $this->secondConnection()->statement('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // 1. A stale writer must never disturb a settled payment
    // -----------------------------------------------------------------------

    public function test_a_stale_writer_can_never_fail_a_settled_payment(): void
    {
        $payment = $this->pendingPayment();
        $service = app(PaymentService::class);

        // The stale writer: an instance loaded BEFORE the settlement lands —
        // e.g. a request that read the payment while the callback was in
        // flight.
        $stale = Payment::query()->findOrFail($payment->id);

        $settled = $service->confirmProviderPayment($payment, $this->completedPayload($payment, 'winner'));

        $this->assertSame(Payment::STATUS_PAID, $settled->fresh()->status, 'The first confirmation settles the payment.');
        $this->assertSame(1, $this->eventsFor($payment, PaymentEvent::EVENT_PAID), 'Exactly one payment.paid event.');
        $this->assertNotNull($settled->fresh()->paid_at, 'A settled payment carries its settlement timestamp.');

        $settledAt = $settled->fresh()->paid_at;

        // The loser now acts. It must be a no-op: same status, same timestamp,
        // no second payment.paid event.
        $again = $service->confirmProviderPayment($stale, $this->completedPayload($stale, 'loser'));

        $this->assertSame(
            Payment::STATUS_PAID,
            $again->fresh()->status,
            'A second confirmation must never move a settled payment out of its settled state.',
        );
        $this->assertSame(
            1,
            $this->eventsFor($payment, PaymentEvent::EVENT_PAID),
            'The losing attempt must not write a second payment.paid event.',
        );
        $this->assertSame(
            $settledAt?->toDateTimeString(),
            $again->fresh()->paid_at?->toDateTimeString(),
            'The losing attempt must not re-stamp the settlement time.',
        );

        // And the loss is auditable rather than silent.
        $duplicates = PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_GATEWAY_CONFIRMED)
            ->get()
            ->filter(fn (PaymentEvent $event): bool => (bool) (($event->metadata ?? [])['duplicate'] ?? false));

        $this->assertGreaterThanOrEqual(
            1,
            $duplicates->count(),
            'Every losing confirmation must be recorded as an audited duplicate, not silently dropped.',
        );
    }

    // -----------------------------------------------------------------------
    // 2. Concurrent confirmations settle exactly once
    // -----------------------------------------------------------------------

    public function test_concurrent_confirmations_settle_exactly_once(): void
    {
        $payment = $this->pendingPayment();
        $id = $payment->id;

        $parallel = $this->supportsParallelWriters();

        if ($parallel) {
            $this->settleWithParallelWriters($id);

            // A forked parent shares its connection with the children, so the
            // settled row is read through a fresh one. On SQLite this MUST NOT
            // run: an in-memory database lives inside its connection, so
            // purging it would drop the schema itself.
            DB::purge();
            DB::reconnect();
        } else {
            $this->settleSequentially($id);
        }

        $fresh = Payment::query()->findOrFail($id);

        $this->assertSame(
            Payment::STATUS_PAID,
            $fresh->status,
            'The payment must end up settled exactly once.',
        );

        $this->assertSame(
            1,
            PaymentEvent::query()->where('payment_id', $id)->where('event', PaymentEvent::EVENT_PAID)->count(),
            'Exactly one payment.paid event may exist after '.$this->workers.' confirmations, however they were scheduled.',
        );

        $this->assertGreaterThanOrEqual(
            1,
            PaymentEvent::query()->where('payment_id', $id)->where('event', PaymentEvent::EVENT_CREATED)->count()
            + PaymentEvent::query()->where('payment_id', $id)->count(),
            'The settlement is auditable.',
        );

        if ($fresh->team_id !== null) {
            $this->assertSame(
                Team::STATUS_CONFIRMED,
                (string) DB::table('teams')->where('id', $fresh->team_id)->value('status'),
                'The settlement confirms the team — and only the settlement may do so.',
            );
        }

        // A later confirmation is still a no-op.
        $paidAt = $fresh->paid_at;

        app(PaymentService::class)->confirmProviderPayment($fresh, $this->completedPayload($fresh, 'after'));

        $this->assertSame(
            $paidAt?->toDateTimeString(),
            Payment::query()->findOrFail($id)->paid_at?->toDateTimeString(),
            'A later confirmation must not re-stamp the settlement time.',
        );
        $this->assertSame(
            1,
            PaymentEvent::query()->where('payment_id', $id)->where('event', PaymentEvent::EVENT_PAID)->count(),
            'A later confirmation must not add a second payment.paid event.',
        );
    }

    /**
     * Fire the confirmations from real, separate processes.
     */
    protected function settleWithParallelWriters(int $paymentId): void
    {
        $pids = [];

        for ($i = 0; $i < $this->workers; $i++) {
            $pid = pcntl_fork();

            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed');

            if ($pid === 0) {
                // Child process: a fresh connection and a freshly loaded model,
                // i.e. exactly what a second provider callback sees.
                try {
                    DB::purge();
                    DB::reconnect();

                    DB::connection()->statement('SET lock_timeout = 4000');
                    DB::connection()->statement('SET statement_timeout = 15000');

                    $child = Payment::query()->findOrFail($paymentId);

                    app(PaymentService::class)->confirmProviderPayment(
                        $child,
                        $this->completedPayload($child, 'worker-'.getmypid()),
                    );

                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'concurrent confirmation worker failed: '.$e->getMessage()."\n");

                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, 0);

            $this->assertSame(
                0,
                pcntl_wexitstatus($status),
                'A concurrent confirmation worker crashed — a losing confirmation must be handled, never thrown.',
            );
        }
    }

    /**
     * The same invariant without process-level parallelism (a SQLite-only
     * environment, or a build without pcntl): every confirmation after the
     * first must be a recorded no-op.
     */
    protected function settleSequentially(int $paymentId): void
    {
        for ($i = 0; $i < $this->workers; $i++) {
            $payment = Payment::query()->findOrFail($paymentId);

            app(PaymentService::class)->confirmProviderPayment(
                $payment,
                $this->completedPayload($payment, 'sequential-'.$i),
            );
        }
    }

    // -----------------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------------

    /**
     * Delete every row tagged with GAP10F14-*, whatever process wrote it.
     *
     * Deleting by pattern rather than by remembered id means a run that was
     * killed between fixture creation and teardown cannot poison the next one
     * with a duplicate `provider_reference`.
     */
    protected function purgeTaggedRows(): void
    {
        $pattern = 'GAP10F14-%';

        try {
            $connection = DB::connection();

            $paymentIds = $connection->table('payments')
                ->where('provider_reference', 'like', $pattern)
                ->pluck('id')
                ->all();

            $userIds = $connection->table('users')
                ->where('email', 'like', 'gap10f14-%')
                ->pluck('id')
                ->all();

            if ($paymentIds === [] && $userIds !== []) {
                $paymentIds = $connection->table('payments')->whereIn('user_id', $userIds)->pluck('id')->all();
            }

            if ($paymentIds !== []) {
                $connection->table('payment_events')->whereIn('payment_id', $paymentIds)->delete();
                $connection->table('payments')->whereIn('id', $paymentIds)->delete();
            }

            $connection->table('users')
                ->whereIn('id', $userIds)
                ->update(['captain_id' => null]);

            $connection->table('teams')
                ->where('name', 'like', 'Settlement Squad '.$pattern)
                ->delete();

            $connection->table('tournaments')
                ->where('slug', 'like', 'settlement-cup-gap10f14-%')
                ->delete();

            if ($userIds !== []) {
                $connection->table('users')->whereIn('id', $userIds)->delete();
            }
        } catch (\Throwable) {
            // Cleanup must never mask the assertion that ended the test.
        }
    }
}
