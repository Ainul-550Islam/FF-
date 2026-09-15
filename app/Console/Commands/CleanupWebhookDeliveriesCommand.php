<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — prune old outbound webhook deliveries.
 *
 * Deliveries are operational records (attempt history); they are safe to
 * prune after the retention window. Inbound webhook_events are governed by a
 * separate, longer window and are never touched here.
 */
class CleanupWebhookDeliveriesCommand extends Command
{
    protected $signature = 'ffarena:cleanup:webhooks {--days= : override the retention window}';

    protected $description = 'Delete outbound webhook deliveries older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.webhook_deliveries_days', 30);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = DB::table('webhook_deliveries')->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} webhook delivery record(s).");

        return self::SUCCESS;
    }
}
