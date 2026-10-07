<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 16 — non-destructive restore dry-run.
 *
 *   php artisan ffarena:backup:restore ffarena-20260909-120000
 *   php artisan ffarena:backup:restore ffarena-20260909-120000 --target=/tmp/restored.sqlite
 *   php artisan ffarena:backup:restore ffarena-20260909-120000 --identity=/mnt/escrow/backup.key
 *
 * Copies a backup's database snapshot to an isolated target (never the live
 * database) and verifies it is readable. Restoring over the live database is
 * a manual, documented procedure (docs/DISASTER_RECOVERY.md) — this command
 * will not do it.
 *
 * GAP-10 C (finding F-19): when the backup was stored encrypted (age), the
 * dump is decrypted first — using --identity, or BACKUP_DECRYPTION_IDENTITY —
 * and the plaintext is checked against the sha256 the manifest recorded before
 * it is handed to pg_restore. Without an identity the command fails closed
 * with instructions instead of reporting an unreadable archive. The decrypted
 * copy is deleted again before the command returns.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'ffarena:backup:restore
        {name : the backup name}
        {--target= : restore target path (default: inside the backup dir)}
        {--identity= : age identity (private key) file when the backup is encrypted; falls back to BACKUP_DECRYPTION_IDENTITY}';

    protected $description = 'Restore a backup into an isolated target and verify it is readable';

    public function handle(BackupService $backups): int
    {
        $identity = $this->option('identity');
        $identity = is_string($identity) && trim($identity) !== '' ? trim($identity) : null;

        $result = $backups->restoreDryRun(
            (string) $this->argument('name'),
            $this->option('target') ?: null,
            $identity,
        );

        if (! $result['ok']) {
            $this->error('Restore dry-run FAILED: '.($result['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        if (($result['validated'] ?? false) === true) {
            // A PostgreSQL backup is validated in place: pg_restore reads the
            // archive's table of contents, so there is no restore target to
            // report (and for an encrypted backup the decrypted copy is gone
            // by now, on purpose).
            $this->info('Archive validated: the dump is readable by pg_restore.');
        } else {
            $this->info('Restored (dry-run) to: '.$result['target']);
        }

        $this->line('Integrity check passed. The live database was NOT touched.');

        if ($identity !== null) {
            $this->line('The decrypted copy used for this check was deleted again.');
        }

        return self::SUCCESS;
    }
}
