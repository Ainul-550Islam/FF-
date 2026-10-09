<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/**
 * Phase 16 — local backup engine.
 *
 * Creates consistent, timestamped, checksummed backups of the database (and,
 * optionally, private user files) inside the private filesystem, verifies
 * them, enforces retention and supports a non-destructive restore dry-run.
 *
 * SQLite uses "VACUUM INTO" for a crash-consistent snapshot (works while the
 * app is live); MySQL/PostgreSQL delegates to mysqldump/pg_dump when present
 * and fails honestly otherwise. A backup never reports success it did not
 * achieve, and never writes outside the private disk except to the configured
 * offsite mirror (GAP-10 C).
 */
class BackupService
{
    public function __construct(
        protected AuditLogService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Create one backup.
     *
     * @return array{ok: bool, name?: string, path?: string, size?: int, sha256?: string, error?: string, offsite?: array<string, mixed>|null}
     */
    public function create(): array
    {
        $name = 'ffarena-'.now()->format('Ymd-His');
        $dir = $this->disk()->path($this->path($name));

        try {
            $this->disk()->makeDirectory($this->path($name));

            $db = $this->dumpDatabase($dir);

            $private = $this->copyPrivateFiles($dir);

            // The integrity check runs on the CLEARTEXT dump. After this point
            // the database file may be age-encrypted (GAP-10 C), and running
            // PRAGMA integrity_check on ciphertext would fail a healthy backup.
            if (config('backup.integrity_check', true) && $db['driver'] === 'sqlite') {
                if (! $this->integrityCheck($db['path'])) {
                    throw new \RuntimeException('SQLite integrity check failed on the freshly written snapshot.');
                }
            }

            // GAP-10 C — encrypt the database file when a recipient is
            // configured. On success the cleartext is deleted and the manifest
            // records the ciphertext; cleartext never leaves this block.
            $db = $this->maybeEncryptDatabase($db);

            $manifest = $this->writeManifest($dir, $name, $db, $private);

            // GAP-10 C / AUDIT FIX (2026-10-08, GAPS-13/14) — mirror the
            // finished backup off-host. The mirror now RETURNS A PROVEN REPORT
            // instead of being a fire-and-forget side effect, and the report
            // is recorded in the manifest (locally AND on the offsite copy, so
            // the two manifest.json files stay byte-identical). When the
            // mirror is required, a failed copy fails the whole backup —
            // but the verified local directory is KEPT: it is the only copy
            // left, and the old behaviour (throw into the outer catch, which
            // deletes the half-written directory) destroyed exactly the data
            // the operator needs to recover from.
            $offsite = $this->mirrorOffsite($name, $dir, $manifest);

            if (is_array($offsite)) {
                $manifest['offsite'] = $offsite;
                $bytes = $this->putManifest($dir, $manifest);

                if (! empty($offsite['ok']) && ! empty($offsite['manifest_key'])) {
                    try {
                        Storage::disk((string) $offsite['disk'])->put((string) $offsite['manifest_key'], $bytes);
                    } catch (Throwable $e) {
                        $offsite['ok'] = false;
                        $offsite['error'] = 'Offsite manifest refresh failed: '.substr($e->getMessage(), 0, 200);
                        $manifest['offsite'] = $offsite;
                        $this->putManifest($dir, $manifest);
                    }
                }
            }

            if (is_array($offsite) && ! (bool) ($offsite['ok'] ?? false) && ! empty($offsite['required'])) {
                $error = 'Offsite mirror failed (BACKUP_OFFSITE_REQUIRED=true): '.substr((string) ($offsite['error'] ?? 'unknown error'), 0, 200);

                $this->notifyAdmins('backup.failed', 'Backup offsite mirror failed: '.$name, substr($error, 0, 255));

                $this->audit->recordQuietly(null, 'ops.backup_created', 'backup', null, [
                    'metadata' => ['name' => $name, 'failed' => true, 'stage' => 'offsite', 'error' => substr($error, 0, 200)],
                ]);

                return [
                    'ok' => false,
                    'name' => $name,
                    'path' => $dir,
                    'sha256' => (string) $manifest['db_sha256'],
                    'error' => substr($error, 0, 255),
                    'offsite' => $offsite,
                ];
            }

            $this->prune();

            $this->audit->recordQuietly(null, 'ops.backup_created', 'backup', null, [
                'metadata' => ['name' => $name, 'db_driver' => $db['driver'], 'sha256' => $manifest['db_sha256'], 'encrypted' => isset($db['encryption']), 'offsite' => $offsite === null ? 'none' : (($offsite['ok'] ?? false) ? 'ok' : 'failed')],
            ]);

            return [
                'ok' => true,
                'name' => $name,
                'path' => $dir,
                'size' => $manifest['size'],
                'sha256' => $manifest['db_sha256'],
                'offsite' => $offsite,
            ];
        } catch (Throwable $e) {
            // Never leave a half-written backup behind.
            try {
                File::deleteDirectory($dir);
            } catch (Throwable) {
            }

            $this->notifyAdmins('backup.failed', 'Backup failed: '.$name, 'Backup creation failed: '.substr($e->getMessage(), 0, 255));

            $this->audit->recordQuietly(null, 'ops.backup_created', 'backup', null, [
                'metadata' => ['name' => $name, 'failed' => true, 'error' => substr($e::class, 0, 80)],
            ]);

            return ['ok' => false, 'name' => $name, 'error' => substr($e->getMessage(), 0, 255)];
        }
    }

    /**
     * Verify the latest backup (or a specific one by name).
     *
     * @return array{ok: bool, name?: string, checks?: array<int, array{check: string, ok: bool}>, error?: string}
     */
    public function verify(?string $name = null): array
    {
        $backups = $this->list();

        if ($name === null) {
            $name = $backups[0]['name'] ?? null;
        }

        if ($name === null) {
            return ['ok' => false, 'error' => 'No backups found.'];
        }

        $target = null;

        foreach ($backups as $backup) {
            if ($backup['name'] === $name) {
                $target = $backup;

                break;
            }
        }

        if ($target === null) {
            return ['ok' => false, 'error' => "Backup [{$name}] not found."];
        }

        $dir = $this->disk()->path($this->path($name));
        $manifestFile = $dir.'/manifest.json';

        if (! is_file($manifestFile)) {
            return ['ok' => false, 'name' => $name, 'error' => 'Manifest missing.'];
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (! is_array($manifest)) {
            return ['ok' => false, 'name' => $name, 'error' => 'Manifest unreadable.'];
        }

        $checks = [];

        $dbFile = $dir.'/'.($manifest['db_file'] ?? 'database.sqlite');

        $checks[] = ['check' => 'exists', 'ok' => is_file($dbFile)];
        $checks[] = ['check' => 'non_empty', 'ok' => is_file($dbFile) && filesize($dbFile) > 0];

        $sha = is_file($dbFile) ? hash_file('sha256', $dbFile) : null;
        $checks[] = ['check' => 'checksum', 'ok' => $sha !== null && hash_equals((string) ($manifest['db_sha256'] ?? ''), (string) $sha)];

        // Encrypted database files are ciphertext: the checksum above already
        // proves the blob is intact, and PRAGMA integrity_check on ciphertext
        // would fail a healthy backup. Plaintext integrity for encrypted
        // backups is proven by a restore dry-run with the age identity.
        if (($manifest['db_driver'] ?? '') === 'sqlite' && is_file($dbFile) && empty($manifest['encrypted'])) {
            $checks[] = ['check' => 'integrity', 'ok' => $this->integrityCheck($dbFile)];
        }

        // AUDIT FIX (2026-10-08, GAPS-14): when the manifest claims an offsite
        // copy, verification proves the claim — a backup that "was mirrored"
        // to a bucket that has since been wiped must not verify as healthy.
        $offsite = is_array($manifest['offsite'] ?? null) ? $manifest['offsite'] : null;

        if ($offsite !== null) {
            $offsiteExists = false;
            $offsiteChecksum = false;

            $diskName = trim((string) ($offsite['disk'] ?? ''));
            $remotePath = trim((string) ($offsite['path'] ?? ''));

            if ($diskName !== '' && $remotePath !== '') {
                try {
                    $offsiteDisk = Storage::disk($diskName);
                    $offsiteExists = $offsiteDisk->exists($remotePath);

                    if ($offsiteExists) {
                        $remoteSha = hash('sha256', (string) $offsiteDisk->get($remotePath));
                        $offsiteChecksum = hash_equals((string) ($offsite['sha256'] ?? ''), (string) $remoteSha);
                    }
                } catch (Throwable $e) {
                    // Unresolvable disk: both checks fail; the reason is
                    // surfaced through the failing check, never as a crash.
                    Log::info('Backup verification could not reach the offsite disk.', [
                        'disk' => $diskName,
                        'error' => substr($e->getMessage(), 0, 200),
                    ]);
                }
            }

            $checks[] = ['check' => 'offsite_present', 'ok' => $offsiteExists];
            $checks[] = ['check' => 'offsite_checksum', 'ok' => $offsiteChecksum];
        }

        $allOk = true;

        foreach ($checks as $check) {
            if (! $check['ok']) {
                $allOk = false;

                break;
            }
        }

        if (! $allOk) {
            $this->notifyAdmins('backup.verify_failed', 'Backup verification failed: '.$name, 'One or more checks failed for backup '.$name);
        }

        $this->audit->recordQuietly(null, 'ops.backup_verified', 'backup', null, [
            'metadata' => ['name' => $name, 'ok' => $allOk],
        ]);

        return ['ok' => $allOk, 'name' => $name, 'checks' => $checks];
    }

    /**
     * Non-destructive restore dry-run. For SQLite, copy the snapshot to a
     * target file (never the live database) and verify it is readable. For
     * PostgreSQL, validate the custom-format dump with `pg_restore --list`
     * (no data is actually restored). Never touches the live database.
     *
     * When the backup is age-encrypted the database file is decrypted to a
     * scratch file (always deleted afterwards) before validation; without an
     * identity the dry-run fails closed.
     *
     * @return array{ok: bool, target?: string, validated?: bool, reason?: string, error?: string}
     */
    public function restoreDryRun(string $name, ?string $targetPath = null, ?string $identityPath = null): array
    {
        $backups = $this->list();
        $found = null;

        foreach ($backups as $backup) {
            if ($backup['name'] === $name) {
                $found = $backup;

                break;
            }
        }

        if ($found === null) {
            return ['ok' => false, 'error' => "Backup [{$name}] not found."];
        }

        $dir = $this->disk()->path($this->path($name));
        $manifestFile = $dir.'/manifest.json';

        if (! is_file($manifestFile)) {
            return ['ok' => false, 'error' => 'Manifest missing.'];
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $driver = (string) ($manifest['db_driver'] ?? 'sqlite');
        $dbFile = $dir.'/'.($manifest['db_file'] ?? 'database.sqlite');

        if (! is_file($dbFile)) {
            return ['ok' => false, 'error' => 'Database snapshot missing.'];
        }

        // GAP-10 C — encrypted backups decrypt to a scratch file inside the
        // backup directory; the finally block always removes it so cleartext
        // never persists on the host.
        $scratchFile = null;

        if (is_array($manifest) && ! empty($manifest['encrypted'])) {
            $decrypted = $this->decryptDatabaseFile($manifest, $dbFile, $dir, $identityPath);

            if (! ($decrypted['ok'] ?? false)) {
                return $decrypted;
            }

            $scratchFile = (string) $decrypted['path'];
            $dbFile = $scratchFile;
        }

        try {
            return $this->validateRestoredDatabase($driver, $name, $dir, $dbFile, $targetPath);
        } finally {
            if ($scratchFile !== null) {
                @unlink($scratchFile);
            }
        }
    }

    /**
     * Run an automated restore drill against the newest backup: a full
     * non-destructive dry-run, exactly as an operator would run it.
     *
     * Encrypted backups without an identity on the host cannot be decrypted
     * by design, so the drill falls back to blob verification (checksum) and
     * reports mode `blob-only` instead of failing.
     *
     * @return array{ok: bool, mode?: string, backup?: string, target?: string, validated?: bool, note?: string, error?: string}
     */
    public function drill(): array
    {
        $backups = $this->list();

        if ($backups === []) {
            return ['ok' => false, 'error' => 'No backups found — nothing to drill.'];
        }

        $name = (string) $backups[0]['name'];
        $result = $this->restoreDryRun($name);

        if (($result['reason'] ?? null) === 'encrypted_no_identity') {
            $verify = $this->verify($name);

            return [
                'ok' => (bool) ($verify['ok'] ?? false),
                'mode' => 'blob-only',
                'backup' => $name,
                'note' => 'Backup is age-encrypted and no identity is configured on this host; blob checksum verified, the decryption drill needs an operator with the key.',
            ];
        }

        $result['mode'] = 'full';
        $result['backup'] = $name;

        if (! ($result['ok'] ?? false)) {
            $this->notifyAdmins('backup.drill_failed', 'Restore drill failed: '.$name, 'Automated restore drill failed: '.substr((string) ($result['error'] ?? 'unknown'), 0, 200));
        }

        return $result;
    }

    /**
     * Validate a cleartext database file for a restore dry-run. Split out of
     * restoreDryRun so encrypted backups can be decrypted to scratch first
     * and then take the exact same validation path as unencrypted ones.
     *
     * @return array{ok: bool, target?: string, validated?: bool, error?: string}
     */
    protected function validateRestoredDatabase(string $driver, string $name, string $dir, string $dbFile, ?string $targetPath): array
    {
        if ($driver === 'pgsql') {
            return $this->validatePgsqlDump($dbFile, $name, $dir);
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Plain-SQL dumps from mysqldump; readability is a non-empty file.
            $this->audit->recordQuietly(null, 'ops.backup_restored', 'backup', null, [
                'metadata' => ['name' => $name, 'target' => basename($dbFile), 'dry_run' => true],
            ]);

            return ['ok' => true, 'target' => $dbFile];
        }

        // SQLite: copy + integrity check (unchanged behaviour).
        $target = $targetPath ?? ($dir.'/restore-target.sqlite');

        if (is_file($target)) {
            File::delete($target);
        }

        File::copy($dbFile, $target);

        if (! $this->integrityCheck($target)) {
            File::delete($target);

            return ['ok' => false, 'error' => 'Restored copy failed the integrity check.'];
        }

        $this->audit->recordQuietly(null, 'ops.backup_restored', 'backup', null, [
            'metadata' => ['name' => $name, 'target' => basename($target), 'dry_run' => true],
        ]);

        return ['ok' => true, 'target' => $target];
    }

    /**
     * Validate a pg_dump custom-format archive without restoring it.
     *
     * @return array{ok: bool, target?: string, validated?: bool, error?: string}
     */
    protected function validatePgsqlDump(string $dumpFile, string $name, string $dir): array
    {
        $bin = $this->findBinary('pg_restore');

        if ($bin === null) {
            return ['ok' => false, 'error' => 'pg_restore not found on PATH — cannot validate the dump.'];
        }

        exec(escapeshellarg($bin).' --list '.escapeshellarg($dumpFile).' 2>&1', $output, $code);

        if ($code !== 0) {
            return ['ok' => false, 'error' => 'pg_restore could not read the dump: '.implode(' ', array_slice($output, 0, 3))];
        }

        $this->audit->recordQuietly(null, 'ops.backup_restored', 'backup', null, [
            'metadata' => ['name' => $name, 'target' => basename($dumpFile), 'dry_run' => true, 'validated' => true],
        ]);

        return ['ok' => true, 'target' => $dumpFile, 'validated' => true];
    }

    /**
     * Backup directories, newest first.
     *
     * @return array<int, array{name: string, created_at: string|null, db_driver: string|null, size: int, sha256: string|null}>
     */
    public function list(): array
    {
        $root = $this->disk()->path($this->path(''));

        if (! is_dir($root)) {
            return [];
        }

        $entries = [];

        foreach (File::directories($root) as $dir) {
            $name = basename($dir);
            $manifestFile = $dir.'/manifest.json';
            $manifest = is_file($manifestFile)
                ? json_decode((string) file_get_contents($manifestFile), true)
                : null;

            if (! is_array($manifest)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'created_at' => $manifest['created_at'] ?? null,
                'db_driver' => $manifest['db_driver'] ?? null,
                'size' => (int) ($manifest['size'] ?? 0),
                'sha256' => $manifest['db_sha256'] ?? null,
            ];
        }

        usort($entries, fn ($a, $b) => strcmp((string) $b['name'], (string) $a['name']));

        return $entries;
    }

    /**
     * Dump the database to the backup directory.
     *
     * @return array{driver: string, file: string, path: string, sha256: string, size: int}
     */
    protected function dumpDatabase(string $dir): array
    {
        $connectionName = (string) config('database.default', 'sqlite');
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);
        $driver = (string) ($connectionConfig['driver'] ?? $connectionName);

        if ($driver === 'sqlite') {
            return $this->dumpSqlite($dir);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->dumpWithTool($dir, $driver, 'mysqldump', $connectionConfig);
        }

        if ($driver === 'pgsql') {
            return $this->dumpWithTool($dir, $driver, 'pg_dump', $connectionConfig);
        }

        throw new \RuntimeException("Unsupported database driver for backups: {$driver}.");
    }

    /**
     * @return array{driver: string, file: string, path: string, sha256: string, size: int}
     */
    protected function dumpSqlite(string $dir): array
    {
        // Resolve the live database path from the DEFAULT connection so the
        // service works regardless of which named connection is active.
        $connectionName = (string) config('database.default', 'sqlite');
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);
        $live = (string) ($connectionConfig['database'] ?? '');

        $file = 'database.sqlite';
        $target = $dir.'/'.$file;

        if ($live === ':memory:' || $live === '') {
            throw new \RuntimeException('SQLite database is in-memory or unset — cannot back up. Configure a file database.');
        }

        if (! is_file($live)) {
            throw new \RuntimeException("SQLite database file not found at [{$live}].");
        }

        // VACUUM INTO produces a transactionally-consistent snapshot that is
        // safe to take while the application is serving writes.
        $quoted = str_replace("'", "''", $target);

        try {
            DB::connection()->getPdo()->exec("VACUUM INTO '{$quoted}'");
        } catch (Throwable $e) {
            // Older SQLite builds: fall back to a plain file copy + verify.
            File::copy($live, $target);
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new \RuntimeException('Database snapshot was not written.');
        }

        return [
            'driver' => 'sqlite',
            'file' => $file,
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'size' => (int) filesize($target),
        ];
    }

    /**
     * @param  array<string, mixed>  $conn  the resolved connection config
     * @return array{driver: string, file: string, path: string, sha256: string, size: int}
     */
    protected function dumpWithTool(string $dir, string $driver, string $tool, array $conn): array
    {
        $bin = $this->findBinary($tool);

        if ($bin === null) {
            throw new \RuntimeException("{$tool} not found on PATH — install it or use managed database backups.");
        }

        $file = 'database.sql';
        $target = $dir.'/'.$file;

        $cmd = $this->dumpCommand($tool, $bin, $conn, $target);

        exec($cmd.' 2>&1', $output, $code);

        if ($code !== 0 || ! is_file($target) || filesize($target) === 0) {
            throw new \RuntimeException('Database dump failed: '.implode(' ', array_slice($output, 0, 3)));
        }

        return [
            'driver' => $driver,
            'file' => $file,
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'size' => (int) filesize($target),
        ];
    }

    /**
     * Build a dump command from config values without interpolating them into
     * shell-unsafe positions. Credentials are passed via environment so they
     * never appear in the process list.
     */
    protected function dumpCommand(string $tool, string $bin, array $conn, string $target): string
    {
        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (string) ($conn['port'] ?? ($tool === 'mysqldump' ? '3306' : '5432'));
        $database = (string) ($conn['database'] ?? '');
        $user = (string) ($conn['username'] ?? '');
        $password = (string) ($conn['password'] ?? '');

        if ($tool === 'mysqldump') {
            return sprintf(
                'MYSQL_PWD=%s %s --host=%s --port=%s --user=%s --single-transaction --routines --triggers %s > %s',
                escapeshellarg($password),
                escapeshellarg($bin),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($database),
                escapeshellarg($target),
            );
        }

        return sprintf(
            'PGPASSWORD=%s %s --host=%s --port=%s --username=%s --format=custom --file=%s %s',
            escapeshellarg($password),
            escapeshellarg($bin),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($target),
            escapeshellarg($database),
        );
    }

    /**
     * Copy private files into the backup, excluding the backups directory
     * itself to prevent unbounded recursion.
     *
     * @return array{count: int, size: int}
     */
    protected function copyPrivateFiles(string $dir): array
    {
        if (! config('backup.include_private_files', true)) {
            return ['count' => 0, 'size' => 0];
        }

        $source = (string) config('backup.private_dir', storage_path('app/private'));
        $dest = $dir.'/private-files';

        if (! is_dir($source)) {
            return ['count' => 0, 'size' => 0];
        }

        $backupRoot = $this->disk()->path($this->path(''));
        $count = 0;
        $size = 0;

        foreach (File::allFiles($source) as $file) {
            $full = $file->getRealPath();

            // Skip anything inside the backups directory.
            if ($backupRoot !== '' && str_starts_with((string) $full, $backupRoot)) {
                continue;
            }

            $relative = substr((string) $full, strlen($source) + 1);
            $target = $dest.'/'.$relative;

            File::ensureDirectoryExists(dirname($target));
            File::copy($full, $target);

            $count++;
            $size += (int) ($file->getSize() ?? 0);
        }

        return ['count' => $count, 'size' => $size];
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  array{count: int, size: int}  $private
     * @return array<string, mixed>
     */
    protected function writeManifest(string $dir, string $name, array $db, array $private): array
    {
        $manifest = [
            'name' => $name,
            'created_at' => now()->toIso8601String(),
            'app_env' => (string) config('app.env'),
            'db_driver' => $db['driver'],
            'db_file' => $db['file'],
            'db_sha256' => $db['sha256'],
            'db_size' => $db['size'],
            'private_files' => $private['count'],
            'private_size' => $private['size'],
            'size' => $db['size'] + $private['size'],
            'checksum' => (string) config('backup.checksum', 'sha256'),
            // AUDIT FIX (2026-10-08, GAPS-13): `encrypted` is ALWAYS present.
            // Verification and restore tooling branch on it ("skip the
            // integrity probe on ciphertext"); a missing key made plaintext
            // blobs and forgotten blobs indistinguishable and forced every
            // reader to invent its own default. An explicit false is a
            // positive statement: this artifact was written without
            // encryption, deliberately.
            'encrypted' => isset($db['encryption']) && is_array($db['encryption']),
        ];

        if (isset($db['encryption']) && is_array($db['encryption'])) {
            $manifest['encryption'] = $db['encryption'];
        }

        $this->putManifest($dir, $manifest);

        // Tighten permissions on the whole backup directory.
        chmod($dir, 0700);

        if (isset($db['path']) && is_file($db['path'])) {
            chmod($db['path'], 0600);
        }

        return $manifest;
    }

    /**
     * Serialize the manifest to disk with locked-down permissions and return
     * the exact bytes written (the offsite mirror writes the same bytes so
     * both copies of manifest.json stay byte-identical).
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function putManifest(string $dir, array $manifest): string
    {
        $bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        File::put($dir.'/manifest.json', $bytes);
        chmod($dir.'/manifest.json', 0600);

        return (string) $bytes;
    }

    /**
     * GAP-10 C — encrypt the dumped database file with age when a recipient
     * is configured. Returns the dump descriptor with the ciphertext file,
     * hash and size plus an `encryption` block for the manifest. Without a
     * recipient the descriptor passes through untouched.
     *
     * @param  array{driver: string, file: string, path: string, sha256: string, size: int}  $db
     * @return array{driver: string, file: string, path: string, sha256: string, size: int, encryption?: array{tool: string, recipient_fingerprint: string, plaintext_sha256: string}}
     */
    protected function maybeEncryptDatabase(array $db): array
    {
        $recipient = trim((string) config('backup.encryption_recipient', ''));

        if ($recipient === '') {
            return $db;
        }

        $bin = $this->findBinary('age');

        if ($bin === null) {
            throw new \RuntimeException('BACKUP_ENCRYPTION_RECIPIENT is set but the age binary was not found on PATH — refusing to write an unencrypted backup.');
        }

        $cipherPath = $db['path'].'.age';

        $cmd = sprintf(
            '%s --encrypt --recipient %s --output %s %s',
            escapeshellarg($bin),
            escapeshellarg($recipient),
            escapeshellarg($cipherPath),
            escapeshellarg($db['path'])
        );

        exec($cmd.' 2>&1', $output, $code);

        if ($code !== 0 || ! is_file($cipherPath) || filesize($cipherPath) === 0) {
            @unlink($cipherPath);

            throw new \RuntimeException('age encryption failed: '.implode(' ', array_slice($output, 0, 3)));
        }

        $plaintextSha = $db['sha256'];

        // Cleartext must not survive next to the ciphertext.
        @unlink($db['path']);

        $db['file'] = $db['file'].'.age';
        $db['path'] = $cipherPath;
        $db['sha256'] = (string) hash_file('sha256', $cipherPath);
        $db['size'] = (int) filesize($cipherPath);
        $db['encryption'] = [
            'tool' => 'age',
            'recipient_fingerprint' => 'age-recipient:'.substr(hash('sha256', $recipient), 0, 16),
            'plaintext_sha256' => $plaintextSha,
        ];

        return $db;
    }

    /**
     * GAP-10 C — decrypt an encrypted database file to a scratch file inside
     * the backup directory. The caller must delete the scratch file. Fails
     * closed: no identity, no age binary, a wrong identity or tampered
     * ciphertext all return ok:false, never cleartext.
     *
     * @return array{ok: bool, path?: string, reason?: string, error?: string}
     */
    protected function decryptDatabaseFile(array $manifest, string $dbFile, string $dir, ?string $identityPath): array
    {
        $identity = trim($identityPath ?? (string) config('backup.decryption_identity', ''));

        if ($identity === '') {
            return [
                'ok' => false,
                'reason' => 'encrypted_no_identity',
                'error' => 'Backup is age-encrypted and no decryption identity was provided. Re-run with --identity=/path/to/age-key.txt or set BACKUP_DECRYPTION_IDENTITY.',
            ];
        }

        $bin = $this->findBinary('age');

        if ($bin === null) {
            return ['ok' => false, 'error' => 'Backup is age-encrypted but the age binary was not found on PATH — install age to restore it.'];
        }

        $scratch = $dir.'/database.restore-'.uniqid('', true).'.tmp';

        $cmd = sprintf(
            '%s --decrypt --identity %s --output %s %s',
            escapeshellarg($bin),
            escapeshellarg($identity),
            escapeshellarg($scratch),
            escapeshellarg($dbFile)
        );

        exec($cmd.' 2>&1', $output, $code);

        if ($code !== 0 || ! is_file($scratch)) {
            @unlink($scratch);

            return ['ok' => false, 'error' => 'could not decrypt the backup (wrong identity or corrupted ciphertext): '.implode(' ', array_slice($output, 0, 2))];
        }

        $expected = (string) ($manifest['encryption']['plaintext_sha256'] ?? '');

        if ($expected === '' || ! hash_equals($expected, (string) hash_file('sha256', $scratch))) {
            @unlink($scratch);

            return ['ok' => false, 'error' => 'Decrypted plaintext does not match the manifest checksum — manifest and ciphertext disagree.'];
        }

        return ['ok' => true, 'path' => $scratch];
    }

    /**
     * GAP-10 C — mirror a finished backup to the offsite disk and verify the
     * copy by checksum. When the mirror is required a failure throws (failing
     * the backup, fail closed); otherwise the miss is logged and the local
     * backup still counts.
     */
    /**
     * AUDIT FIX (2026-10-08, GAPS-14): the mirror now returns a proven
     * report instead of void. create() records it in the manifest, decides
     * the fail-closed verdict on it, and hands it to the caller — a silent
     * "we think we copied it" was the whole failure mode: operators read
     * `ok: true` and had no offsite copy at all.
     *
     * Null is returned ONLY when offsite is not part of this deployment at
     * all (no disk and nothing required). A configured-but-broken mirror
     * always yields a report, ok:false included, so the misconfiguration is
     * inspectable instead of inferred.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, required: bool, disk: string, path: string, manifest_key: string, sha256: string, error?: string}|null
     */
    protected function mirrorOffsite(string $name, string $dir, array $manifest): ?array
    {
        $diskName = trim((string) config('backup.offsite_disk', ''));
        $required = (bool) config('backup.offsite_required', false);

        if ($diskName === '') {
            if (! $required) {
                return null;
            }

            return [
                'ok' => false,
                'required' => true,
                'disk' => '',
                'path' => '',
                'manifest_key' => '',
                'sha256' => (string) ($manifest['db_sha256'] ?? ''),
                'error' => 'BACKUP_OFFSITE_DISK is not configured while BACKUP_OFFSITE_REQUIRED=true.',
            ];
        }

        $prefix = trim((string) config('backup.offsite_prefix', 'backups'), '/').'/'.trim($name, '/');

        $report = [
            'ok' => false,
            'required' => $required,
            'disk' => $diskName,
            'path' => $prefix.'/'.(string) ($manifest['db_file'] ?? 'database.sqlite'),
            'manifest_key' => $prefix.'/manifest.json',
            'sha256' => (string) ($manifest['db_sha256'] ?? ''),
        ];

        try {
            $offsite = Storage::disk($diskName);

            foreach (File::allFiles($dir) as $file) {
                $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
                $offsite->put($prefix.'/'.$relative, file_get_contents($file->getPathname()));
            }

            // Read the copy back and prove it matches, file by file.
            foreach (File::allFiles($dir) as $file) {
                $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
                $key = $prefix.'/'.$relative;

                if (! $offsite->exists($key)) {
                    throw new \RuntimeException("Offsite copy is missing [{$key}].");
                }

                $localSha = hash_file('sha256', $file->getPathname());
                $remoteSha = hash('sha256', (string) $offsite->get($key));

                if (! hash_equals((string) $localSha, $remoteSha)) {
                    throw new \RuntimeException("Offsite copy checksum mismatch on [{$key}].");
                }
            }

            // The DB artifact itself re-proved: the recorded offsite checksum
            // must match what the report promises to verify().
            $remoteDb = $offsite->get($report['path']);

            if ($remoteDb === null || ! hash_equals((string) $report['sha256'], hash('sha256', (string) $remoteDb))) {
                throw new \RuntimeException('Offsite database artifact failed the final checksum proof.');
            }

            $report['ok'] = true;

            return $report;
        } catch (Throwable $e) {
            $report['error'] = substr($e->getMessage(), 0, 255);

            if ($required) {
                Log::error('Backup offsite mirror failed; the run is marked failed and the local copy is retained.', [
                    'backup' => $name,
                    'disk' => $diskName,
                    'error' => $report['error'],
                ]);
            } else {
                Log::warning('Backup offsite mirror failed; local backup retained.', [
                    'backup' => $name,
                    'disk' => $diskName,
                    'error' => $report['error'],
                ]);
            }

            return $report;
        }
    }

    /**
     * GAP-10 C — apply the same retention to the offsite mirror. Best effort:
     * a mirror that cannot be pruned is logged, never fatal to the backup.
     */
    protected function pruneOffsite(int $retention): void
    {
        $diskName = trim((string) config('backup.offsite_disk', ''));

        if ($diskName === '') {
            return;
        }

        try {
            $offsite = Storage::disk($diskName);
            $prefix = trim((string) config('backup.offsite_prefix', 'backups'), '/');

            $sets = $offsite->directories($prefix);
            rsort($sets);

            foreach (array_slice($sets, $retention) as $stale) {
                $offsite->deleteDirectory($stale);
            }
        } catch (Throwable $e) {
            Log::warning('Backup offsite prune failed; stale mirrors retained.', [
                'disk' => $diskName,
                'error' => substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    protected function prune(): void
    {
        $retention = (int) config('backup.retention', 14);

        // Offsite retention is enforced on every run, not only when the local
        // directory overflows — a no-op when no offsite disk is configured.
        $this->pruneOffsite($retention);

        $backups = $this->list();

        if (count($backups) <= $retention) {
            return;
        }

        foreach (array_slice($backups, $retention) as $stale) {
            File::deleteDirectory($this->disk()->path($this->path($stale['name'])));
        }
    }

    protected function integrityCheck(string $sqliteFile): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$sqliteFile);
            $result = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();

            return $result === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    protected function findBinary(string $tool): ?string
    {
        $which = @shell_exec('command -v '.escapeshellarg($tool).' 2>/dev/null');

        return is_string($which) && trim($which) !== '' ? trim($which) : null;
    }

    protected function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.disk', 'local'));
    }

    protected function path(string $suffix): string
    {
        return trim((string) config('backup.path', 'backups'), '/').($suffix === '' ? '' : '/'.$suffix);
    }

    protected function notifyAdmins(string $type, string $title, string $body): void
    {
        if (! config('backup.notify_admins', true)) {
            return;
        }

        try {
            $admins = User::where('role', 'admin')->get();

            if ($admins->isNotEmpty()) {
                $this->notifications->sendToMany($admins, $type, $title, $body);
            }
        } catch (Throwable) {
            // Notifications must never fail a backup.
        }
    }
}
