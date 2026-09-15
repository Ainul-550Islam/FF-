<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 16 — create one timestamped, checksummed, integrity-verified backup.
 *
 *   php artisan ffarena:backup
 *
 * Exits non-zero on failure and never reports success it did not achieve.
 */
class BackupCreateCommand extends Command
{
    protected $signature = 'ffarena:backup';

    protected $description = 'Create a consistent, checksummed backup of the database and private files';

    public function handle(BackupService $backups): int
    {
        $this->info('Creating backup…');

        $result = $backups->create();

        if (! $result['ok']) {
            $this->error('Backup FAILED: '.($result['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Backup created: '.$result['name']);
        $this->line('  path:   '.$result['path']);
        $this->line('  size:   '.number_format((int) $result['size']).' bytes');
        $this->line('  sha256: '.$result['sha256']);

        return self::SUCCESS;
    }
}
