<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-10 F-40 — the profile under test is the database under test.
 *
 * Each phpunit profile declares its identity (FFARENA_TEST_PROFILE) and pins
 * its coordinates in $_SERVER (see the <server> entries each phpunit.*.xml
 * carries). This test asserts, from inside the suite, that the RESOLVED
 * connection matches the DECLARED identity — so a hostile process
 * environment (DB_CONNECTION=pgsql exported while running the default
 * profile) cannot produce a PostgreSQL run that prints "SQLite green".
 *
 * The static floor runs this test in exactly that hostile environment
 * (HostileEnvironmentFloor); the re-check is `DB_CONNECTION=pgsql php
 * vendor/bin/phpunit`, which must stay SQLite.
 */
class ProfilePinningTest extends TestCase
{
    public function test_resolved_connection_matches_profile_identity(): void
    {
        // env() reads $_SERVER first — the adapter the profiles pin — so
        // this observes the same value the database layer resolved.
        $profile = (string) env('FFARENA_TEST_PROFILE', 'sqlite');

        $this->assertContains(
            $profile,
            ['sqlite', 'pgsql', 'redis'],
            'Unknown test profile identity: '.$profile
        );

        $driver = DB::connection()->getDriverName();
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($profile === 'pgsql') {
            // Host/port/name stay environment-overridable by design (CI
            // points them at the service container); only the dialect is
            // pinned, so only the dialect is asserted.
            $this->assertSame('pgsql', $driver);
            $this->assertSame('pgsql', (string) config('database.default'));

            return;
        }

        // sqlite + redis profiles: in-memory SQLite, pinned coordinates.
        $this->assertSame('sqlite', $driver);
        $this->assertSame(':memory:', $database);
    }
}
