<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — scheduler heartbeat.
 *
 * Runs every minute via the scheduler; updates the `operations_heartbeats`
 * row so the readiness probe and admin dashboard can detect a stalled cron.
 * Idempotent and side-effect free.
 */
class OpsHeartbeatCommand extends Command
{
    protected $signature = 'ffarena:ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat for readiness checks';

    public function handle(): int
    {
        DB::table('operations_heartbeats')->updateOrInsert(
            ['source' => 'scheduler'],
            ['last_beat_at' => now(), 'updated_at' => now()],
        );

        return self::SUCCESS;
    }
}
