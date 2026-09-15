<?php

namespace Tests\Feature\Phase16;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 16 — production hardening tests base.
 */
abstract class Phase16TestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a user with the given role.
     */
    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    /**
     * Snapshot a set of config keys so a test can restore them afterwards.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function snapshotConfig(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = config($key);
        }

        return $snapshot;
    }

    /**
     * Restore config keys from a snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreConfig(array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
            config([$key => $value]);
        }
    }
}
