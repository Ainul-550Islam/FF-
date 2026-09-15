<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 19/G1 — base for PostgreSQL-specific tests.
 *
 * These tests exercise behaviours that only PostgreSQL's engine provides
 * (row-level locking, unique/FK enforcement, jsonb, statement timeouts) and
 * are skipped on SQLite, where the same logical guarantees come from the
 * single-writer serialisation and where lockForUpdate() is a no-op.
 */
abstract class PostgresTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only test.');
        }
    }

    /**
     * A second, independent connection to the same PostgreSQL test database.
     * Used to simulate two concurrent clients without process-level threading.
     *
     * @return Connection
     */
    protected function secondConnection()
    {
        $name = 'pgsql_test_second';

        if (! array_key_exists($name, (array) config('database.connections'))) {
            config(['database.connections.'.$name => config('database.connections.pgsql')]);
        }

        return DB::connection($name);
    }

    /**
     * Insert a row on the second (committed) connection and clean it up
     * afterwards.
     *
     * @return int the inserted id
     */
    protected function committedInsert(string $table, array $row): int
    {
        return (int) $this->secondConnection()->table($table)->insertGetId($row);
    }
}
