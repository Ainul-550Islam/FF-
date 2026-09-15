<?php

namespace Tests\Feature\Postgres;

use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19/G1 — PostgreSQL integrity guarantees exercised directly:
 * unique backstops, foreign keys, jsonb round-trip/containment, pagination,
 * case-insensitive search and transactional rollback.
 */
class PostgresConstraintTest extends PostgresTestCase
{
    public function test_unique_index_backstops_duplicate_webhook_delivery(): void
    {
        $provider = 'bkash';
        $external = 'evt-uniq-'.bin2hex(random_bytes(6));
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

        DB::table('webhook_events')->insert($base);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('webhook_events')->insert($base);
    }

    public function test_unique_idempotency_key_backstops_duplicate_payment(): void
    {
        $user = User::factory()->create();
        $tournament = DB::table('tournaments')->insertGetId([
            'organizer_id' => $user->id,
            'name' => 'Idem Tournament',
            'slug' => 'idem-'.bin2hex(random_bytes(4)),
            'status' => Tournament::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $team = DB::table('teams')->insertGetId([
            'tournament_id' => $tournament,
            'name' => 'Idem Team',
            'captain_name' => 'Captain',
            'status' => Team::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = 'idem-'.bin2hex(random_bytes(6));
        $base = [
            'tournament_id' => $tournament,
            'team_id' => $team,
            'idempotency_key' => $key,
            'amount_minor' => 1000,
            'currency' => 'BDT',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payments')->insert($base);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('payments')->insert($base);
    }

    public function test_unique_team_registration_backstops_duplicate_captain(): void
    {
        $user = User::factory()->create();
        $tournament = DB::table('tournaments')->insertGetId([
            'organizer_id' => $user->id,
            'name' => 'Unique Teams',
            'slug' => 'teams-'.bin2hex(random_bytes(4)),
            'status' => Tournament::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $base = [
            'tournament_id' => $tournament,
            'captain_id' => $user->id,
            'name' => 'Captain Team',
            'captain_name' => 'Captain',
            'status' => Team::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('teams')->insert($base);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('teams')->insert($base);
    }

    public function test_foreign_key_constraint_is_enforced(): void
    {
        try {
            DB::table('notifications')->insert([
                'user_id' => 99999999,
                'type' => 'test',
                'title' => 'orphan',
                'body' => 'should not be allowed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('Expected a foreign-key violation for the orphan notification.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }
    }

    public function test_jsonb_round_trips_and_supports_containment(): void
    {
        $metadata = ['risk' => 'low', 'flags' => ['a', 'b'], 'count' => 3];

        $id = DB::table('webhook_events')->insertGetId([
            'provider' => 'nagad',
            'external_event_id' => 'evt-jsonb-'.bin2hex(random_bytes(6)),
            'event_type' => 'payout.updated',
            'signature_status' => 'verified',
            'status' => 'received',
            'metadata' => json_encode($metadata),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('webhook_events')->where('id', $id)->first();
        $this->assertIsString($row->metadata);

        // jsonb canonicalises key order (by length, then byte order), so the
        // round-trip is compared order-insensitively.
        $decoded = json_decode($row->metadata, true);
        $this->assertEqualsCanonicalizing($metadata, $decoded);

        // jsonb containment (the column is jsonb after G1's conversion).
        $matches = DB::table('webhook_events')
            ->where('id', $id)
            ->whereJsonContains('metadata', ['risk' => 'low'])
            ->count();

        $this->assertSame(1, $matches);
    }

    public function test_pagination_is_supported(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            DB::table('tournaments')->insert([
                'organizer_id' => $user->id,
                'name' => "Paginate {$i}",
                'slug' => 'pag-'.bin2hex(random_bytes(3)).'-'.$i,
                'status' => Tournament::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $page = DB::table('tournaments')->orderBy('name')->paginate(2);

        $this->assertSame(2, $page->count());
        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
    }

    public function test_case_insensitive_search_uses_lower_like(): void
    {
        $user = User::factory()->create();

        DB::table('tournaments')->insert([
            'organizer_id' => $user->id,
            'name' => 'Bermuda Blitz',
            'slug' => 'bermuda-'.bin2hex(random_bytes(4)),
            'status' => Tournament::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $found = DB::table('tournaments')
            ->whereRaw('LOWER(name) LIKE ?', ['%bermuda%'])
            ->count();

        $this->assertSame(1, $found);
    }

    public function test_api_idempotency_key_unique_backstops_concurrent_requests(): void
    {
        $user = User::factory()->create();

        // The (user_id, key) unique backstop: two concurrent requests carrying
        // the same Idempotency-Key cannot both insert — the second one raises a
        // unique violation, so the operation can never be performed twice.
        $base = [
            'user_id' => $user->id,
            'key' => 'idem-'.bin2hex(random_bytes(6)),
            'method' => 'POST',
            'path' => '/api/v1/payments',
            'request_fingerprint' => hash('sha256', 'body'),
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ];

        DB::table('api_idempotency_keys')->insert($base);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('api_idempotency_keys')->insert($base);
    }

    public function test_transaction_rollback_leaves_no_partial_rows(): void
    {
        $user = User::factory()->create();

        DB::beginTransaction();

        DB::table('tournaments')->insert([
            'organizer_id' => $user->id,
            'name' => 'Rollback Me',
            'slug' => 'rollback-'.bin2hex(random_bytes(4)),
            'status' => Tournament::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::rollBack();

        $this->assertSame(
            0,
            DB::table('tournaments')->where('name', 'Rollback Me')->count()
        );
    }
}
