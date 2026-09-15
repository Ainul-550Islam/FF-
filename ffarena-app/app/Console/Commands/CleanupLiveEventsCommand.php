<?php

namespace App\Console\Commands;

use App\Models\LiveEvent;
use Illuminate\Console\Command;

/**
 * Phase 16 — prune old realtime live events.
 *
 * Live events are the realtime feed; they are safe to prune after the
 * retention window (default 30 days) once no client can reasonably replay
 * that far back.
 */
class CleanupLiveEventsCommand extends Command
{
    protected $signature = 'ffarena:cleanup:live-events {--days= : override the retention window}';

    protected $description = 'Delete live events older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.live_events_days', 30);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = LiveEvent::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} live event record(s).");

        return self::SUCCESS;
    }
}
