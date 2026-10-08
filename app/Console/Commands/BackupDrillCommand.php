<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 16 — automated restore drill against the newest backup.
 *
 *   php artisan ffarena:backup:drill
 *
 * Runs the same non-destructive dry-run an operator would run, so a backup
 * that can no longer be restored pages the on-call instead of waiting for an
 * incident. Encrypted backups without an identity on the host report
 * `blob-only` (checksum verified, decryption untested) instead of failing.
 *
 * Exits non-zero when the drill fails.
 */
class BackupDrillCommand extends Command
{
    protected $signature = 'ffarena:backup:drill';

    protected $description = 'Restore-drill the newest backup (non-destructive dry-run)';

    public function handle(BackupService $backups): int
    {
        $result = $backups->drill();

        if (! ($result['ok'] ?? false)) {
            $this->error('Restore drill FAILED: '.($result['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info('Restore drill passed ('.($result['mode'] ?? 'full').'): '.($result['backup'] ?? 'unknown'));

        if (isset($result['target'])) {
            $this->line('  target: '.$result['target']);
        }

        if (isset($result['note'])) {
            $this->line('  note: '.$result['note']);
        }

        return self::SUCCESS;
    }
}
