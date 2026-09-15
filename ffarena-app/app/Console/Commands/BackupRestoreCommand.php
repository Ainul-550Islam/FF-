<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 16 — non-destructive restore dry-run.
 *
 *   php artisan ffarena:backup:restore ffarena-20260909-120000
 *   php artisan ffarena:backup:restore ffarena-20260909-120000 --target=/tmp/restored.sqlite
 *
 * Copies a backup's database snapshot to an isolated target (never the live
 * database) and verifies it is readable. Restoring over the live database is
 * a manual, documented procedure (docs/DISASTER_RECOVERY.md) — this command
 * will not do it.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'ffarena:backup:restore {name : the backup name} {--target= : restore target path (default: inside the backup dir)}';

    protected $description = 'Restore a backup into an isolated target and verify it is readable';

    public function handle(BackupService $backups): int
    {
        $result = $backups->restoreDryRun((string) $this->argument('name'), $this->option('target') ?: null);

        if (! $result['ok']) {
            $this->error('Restore dry-run FAILED: '.($result['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Restored (dry-run) to: '.$result['target']);
        $this->line('Integrity check passed. The live database was NOT touched.');

        return self::SUCCESS;
    }
}
