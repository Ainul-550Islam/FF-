<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
 * achieve, and never writes outside the private disk.
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
     * @return array{ok: bool, name?: string, path?: string, size?: int, sha256?: string, error?: string}
     */
    public function create(): array
    {
        $name = 'ffarena-'.now()->format('Ymd-His');
        $dir = $this->disk()->path($this->path($name));

        try {
            $this->disk()->makeDirectory($this->path($name));

            $db = $this->dumpDatabase($dir);

            $private = $this->copyPrivateFiles($dir);

            $manifest = $this->writeManifest($dir, $name, $db, $private);

            if (config('backup.integrity_check', true) && $db['driver'] === 'sqlite') {
                if (! $this->integrityCheck($db['path'])) {
                    throw new \RuntimeException('SQLite integrity check failed on the freshly written snapshot.');
                }
            }

            $this->prune();

            $this->audit->recordQuietly(null, 'ops.backup_created', 'backup', null, [
                'metadata' => ['name' => $name, 'db_driver' => $db['driver'], 'sha256' => $manifest['db_sha256']],
            ]);

            return [
                'ok' => true,
                'name' => $name,
                'path' => $dir,
                'size' => $manifest['size'],
                'sha256' => $manifest['db_sha256'],
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

        if (($manifest['db_driver'] ?? '') === 'sqlite' && is_file($dbFile)) {
            $checks[] = ['check' => 'integrity', 'ok' => $this->integrityCheck($dbFile)];
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
     * @return array{ok: bool, target?: string, validated?: bool, error?: string}
     */
    public function restoreDryRun(string $name, ?string $targetPath = null): array
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
        ];

        File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Tighten permissions on the whole backup directory.
        chmod($dir, 0700);
        chmod($dir.'/manifest.json', 0600);

        if (isset($db['path']) && is_file($db['path'])) {
            chmod($db['path'], 0600);
        }

        return $manifest;
    }

    protected function prune(): void
    {
        $retention = (int) config('backup.retention', 14);
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
