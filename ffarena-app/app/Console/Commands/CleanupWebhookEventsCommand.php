<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — prune old inbound webhook events.
 *
 * Inbound events hold only safe metadata plus an encrypted raw payload; they
 * are pruned after a longer retention window (default 90 days) because they
 * are the inbound audit trail for payment callbacks.
 */
class CleanupWebhookEventsCommand extends Command
{
    protected $signature = 'ffarena:cleanup:webhook-events {--days= : override the retention window}';

    protected $description = 'Delete inbound webhook events older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.webhook_events_days', 90);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = DB::table('webhook_events')->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} inbound webhook event record(s).");

        return self::SUCCESS;
    }
}
