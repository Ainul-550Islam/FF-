<?php

namespace Tests\Feature\Postgres;

use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19/G1 — PostgreSQL concurrency semantics.
 *
 * Uses a second, independent connection to the same database to exercise the
 * locking primitives the financial/registration services rely on
 * (lockForUpdate(), atomic conditional UPDATE, unique backstops) without
 * spawning real threads.
 */
class PostgresConcurrencyTest extends PostgresTestCase
{
    public function test_lock_for_update_serialises_writers(): void
    {
        $second = $this->secondConnection();

        $id = $second->table('users')->insertGetId([
            'name' => 'Lock Contention',
            'email' => 'lock-'.bin2hex(random_bytes(6)).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // The second connection holds a row lock inside its own transaction.
            $second->beginTransaction();
            $second->table('users')->where('id', $id)->lockForUpdate()->first();

            // The default connection must block until statement_timeout fires,
            // proving FOR UPDATE actually serialises the write. The probe runs
            // inside a savepoint so the timeout aborts only that savepoint and
            // leaves the ambient test transaction usable for cleanup.
            DB::statement("SET statement_timeout = '500ms'");

            try {
                DB::transaction(function () use ($id) {
                    DB::table('users')->where('id', $id)->lockForUpdate()->first();
                });
                $this->fail('Expected the second writer to block on the held row lock.');
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('timeout', $e->getMessage());
            }
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }

            DB::statement('RESET statement_timeout');

            $second->table('users')->where('id', $id)->delete();
        }
    }

    public function test_atomic_slot_claim_update_refuses_the_final_slot(): void
    {
        $second = $this->secondConnection();
        $uid = bin2hex(random_bytes(6));

        $organizerId = $second->table('users')->insertGetId([
            'name' => 'Org '.$uid,
            'email' => 'org-'.$uid.'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tournamentId = $second->table('tournaments')->insertGetId([
            'organizer_id' => $organizerId,
            'name' => 'Slot Race '.$uid,
            'slug' => 'slot-'.$uid,
            'status' => Tournament::STATUS_OPEN,
            'team_slots' => 1,
            'starts_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamId = $second->table('teams')->insertGetId([
            'tournament_id' => $tournamentId,
            'name' => 'First Team',
            'captain_name' => 'Captain',
            'status' => Team::STATUS_CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // team_slots = 1, one confirmed team → the atomic claim must fail.
            $claimed = $this->claimSlot($second, $tournamentId);
            $this->assertSame(0, $claimed);

            // With a free slot the same atomic update succeeds exactly once.
            $second->table('tournaments')->where('id', $tournamentId)->update(['team_slots' => 2]);
            $claimed = $this->claimSlot($second, $tournamentId);
            $this->assertSame(1, $claimed);
        } finally {
            $second->table('teams')->where('id', $teamId)->delete();
            $second->table('tournaments')->where('id', $tournamentId)->delete();
            $second->table('users')->where('id', $organizerId)->delete();
        }
    }

    public function test_duplicate_webhook_delivery_is_rejected_at_the_database_level(): void
    {
        $second = $this->secondConnection();
        $provider = 'bkash';
        $external = 'evt-race-'.bin2hex(random_bytes(6));

        $base = [
            'provider' => $provider,
            'external_event_id' => $external,
            'event_type' => 'payment.updated',
            'signature_status' => 'verified',
            'status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            // First delivery commits (simulating a completed transaction).
            $second->table('webhook_events')->insert($base);

            // A second delivery of the same external event id must be refused
            // by the unique index — the DB-level idempotency backstop.
            $second->table('webhook_events')->insert($base);

            $this->fail('Expected the duplicate delivery to violate the unique index.');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        } finally {
            $second->table('webhook_events')
                ->where('provider', $provider)
                ->where('external_event_id', $external)
                ->delete();
        }
    }

    public function test_payout_row_lock_serialises_transitions(): void
    {
        $second = $this->secondConnection();
        $uid = bin2hex(random_bytes(6));

        // Build the FK chain (organizer → tournament → distribution → payout)
        // on the committed second connection so it is visible to the default
        // connection.
        $organizerId = $second->table('users')->insertGetId([
            'name' => 'Payout Org '.$uid,
            'email' => 'payout-org-'.$uid.'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tournamentId = $second->table('tournaments')->insertGetId([
            'organizer_id' => $organizerId,
            'name' => 'Payout Race '.$uid,
            'slug' => 'payout-'.$uid,
            'status' => Tournament::STATUS_FINISHED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $distributionId = $second->table('prize_distributions')->insertGetId([
            'tournament_id' => $tournamentId,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payoutId = $second->table('payouts')->insertGetId([
            'distribution_id' => $distributionId,
            'tournament_id' => $tournamentId,
            'rank' => 1,
            'amount_minor' => 1000,
            'currency' => 'BDT',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // A concurrent worker holds the payout row lock while transitioning.
            $second->beginTransaction();
            $second->table('payouts')->where('id', $payoutId)->lockForUpdate()->first();

            // The default connection (mirroring PayoutService) must block on the
            // same FOR UPDATE until statement_timeout fires. The probe runs in a
            // savepoint so the timeout aborts only that savepoint.
            DB::statement("SET statement_timeout = '500ms'");

            try {
                DB::transaction(function () use ($payoutId) {
                    DB::table('payouts')->where('id', $payoutId)->lockForUpdate()->first();
                });
                $this->fail('Expected the payout transition to block on the held row lock.');
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('timeout', $e->getMessage());
            }
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }

            DB::statement('RESET statement_timeout');

            $second->table('payouts')->where('id', $payoutId)->delete();
            $second->table('prize_distributions')->where('id', $distributionId)->delete();
            $second->table('tournaments')->where('id', $tournamentId)->delete();
            $second->table('users')->where('id', $organizerId)->delete();
        }
    }

    /**
     * Mirror of the atomic slot-claim UPDATE used by RegistrationService.
     * Runs on the given (committed) connection so the test does not hold a
     * row lock inside the framework's ambient test transaction.
     */
    protected function claimSlot($connection, int $tournamentId): int
    {
        return $connection->table('tournaments')
            ->where('id', $tournamentId)
            ->where('status', Tournament::STATUS_OPEN)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
            })
            ->whereRaw(
                '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
            )
            ->update(['updated_at' => now()]);
    }
}
