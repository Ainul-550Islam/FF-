<?php

namespace App\Console\Commands;

use App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * Phase 16 — queue health for operators.
 *
 *   php artisan ffarena:queue:health
 *
 * Reports backlog, failed-job count, oldest pending age and scheduler
 * heartbeat age. Exits non-zero when the queue table is unreachable.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'ffarena:queue:health';

    protected $description = 'Report queue backlog, failed jobs and worker/scheduler heartbeat';

    public function handle(HealthService $health): int
    {
        $stats = $health->queueStats();

        if (! $stats['reachable']) {
            $this->error('Queue table is unreachable.');

            return self::FAILURE;
        }

        $this->line('Queue health:');
        $this->line('  pending jobs:  '.$stats['pending_jobs']);
        $this->line('  failed jobs:   '.$stats['failed_jobs']);
        $this->line('  oldest pending: '.($stats['oldest_pending_seconds'] === null ? 'n/a' : $stats['oldest_pending_seconds'].'s'));
        $this->line('  scheduler heartbeat: '.($stats['scheduler_heartbeat_seconds_ago'] === null ? 'n/a' : $stats['scheduler_heartbeat_seconds_ago'].'s ago'));

        return self::SUCCESS;
    }
}
