<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

/**
 * Phase 16 — prune read notifications past the retention window.
 *
 * Only READ notifications older than the window (default 180 days) are
 * removed; unread notifications are preserved so users never silently lose
 * an unread message.
 */
class CleanupNotificationsCommand extends Command
{
    protected $signature = 'ffarena:cleanup:notifications {--days= : override the retention window}';

    protected $description = 'Delete old, already-read notifications';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.notifications_days', 180);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = Notification::whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} read notification(s).");

        return self::SUCCESS;
    }
}
