<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use Illuminate\Console\Command;

/**
 * Phase 16 — prune expired idempotency keys.
 *
 * Idempotency keys only guard against replays within their TTL; rows older
 * than the retention window (default 2 days) can be deleted safely.
 */
class CleanupIdempotencyCommand extends Command
{
    protected $signature = 'ffarena:cleanup:idempotency {--days= : override the retention window}';

    protected $description = 'Delete expired API idempotency keys';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.idempotency_keys_days', 2);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = ApiIdempotencyKey::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
