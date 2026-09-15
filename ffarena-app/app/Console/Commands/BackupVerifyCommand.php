<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 16 — verify backups (existence, size, checksum, SQLite integrity).
 *
 *   php artisan ffarena:backup:verify            # latest backup
 *   php artisan ffarena:backup:verify --name=... # specific backup
 *   php artisan ffarena:backup:verify --all      # every backup
 *
 * Exits non-zero if any verified backup fails.
 */
class BackupVerifyCommand extends Command
{
    protected $signature = 'ffarena:backup:verify {--name= : verify a specific backup by name} {--all : verify every backup}';

    protected $description = 'Verify backup integrity (checksum + database integrity)';

    public function handle(BackupService $backups): int
    {
        if ($this->option('all')) {
            $exit = self::SUCCESS;

            foreach ($backups->list() as $backup) {
                $result = $backups->verify($backup['name']);
                $this->printResult($result);

                if (! $result['ok']) {
                    $exit = self::FAILURE;
                }
            }

            return $exit;
        }

        $result = $backups->verify($this->option('name'));
        $this->printResult($result);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function printResult(array $result): void
    {
        if (! $result['ok']) {
            $this->error(($result['name'] ?? 'backup').' verification FAILED: '.($result['error'] ?? 'unknown'));

            return;
        }

        $this->info('Verified: '.$result['name']);

        foreach (($result['checks'] ?? []) as $check) {
            $this->line('  ['.($check['ok'] ? '✓' : '✗').'] '.$check['check']);
        }
    }
}
