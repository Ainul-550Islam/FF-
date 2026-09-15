<?php

namespace App\Console\Commands;

use App\Models\OtpChallenge;
use Illuminate\Console\Command;

/**
 * Phase 16 — prune expired OTP challenges.
 *
 * OTP codes are single-use and short-lived; challenges older than the
 * retention window (default 1 day) are pure garbage. Deleting them never
 * affects business records.
 */
class CleanupOtpCommand extends Command
{
    protected $signature = 'ffarena:cleanup:otp {--days= : override the retention window}';

    protected $description = 'Delete expired OTP challenges';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('observability.retention.otp_challenges_days', 1);

        $cutoff = now()->subDays(max(0, $days));

        $deleted = OtpChallenge::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} expired OTP challenge(s).");

        return self::SUCCESS;
    }
}
