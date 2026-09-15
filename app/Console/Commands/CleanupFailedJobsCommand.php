<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — prune old failed-job records.
 *
 * Failed jobs are operational records; after the retention window (default
 * 30 days) the stack trace and payload are stale and safe to drop. Business
 * state (payments, scores, etc.) is never touched.
 */
class CleanupFailedJobsCommand extends Command
{
    protected $signature = 'ffarena:cleanup:failed-jobs {--days= : override the retention window}';

    protected $description = 'Delete failed-job records older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.failed_jobs_days', 30);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = DB::table('failed_jobs')->where('failed_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} failed-job record(s).");

        return self::SUCCESS;
    }
}
