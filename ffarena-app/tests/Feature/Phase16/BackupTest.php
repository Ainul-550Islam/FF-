<?php

namespace Tests\Feature\Phase16;

use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Phase 16 — backup creation, verification, restore dry-run, retention and
 * honest failure reporting.
 *
 * Driver-aware (Phase 19/G1): the SQLite tests exercise the VACUUM INTO path
 * and the :memory: refusal; the PostgreSQL tests exercise the pg_dump path
 * and a connect-failure refusal. The same behaviour contract holds on both.
 */
class BackupTest extends Phase16TestCase
{
    protected string $file;

    protected string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/phase16-backup-'.bin2hex(random_bytes(4)).'.sqlite');
        $this->originalDefault = (string) config('database.default');

        File::ensureDirectoryExists(dirname($this->file));
        touch($this->file);

        // Each test starts with a clean backup directory.
        File::cleanDirectory(storage_path('app/private/backups'));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->file.'.restored');
        File::cleanDirectory(storage_path('app/private/backups'));

        config(['database.default' => $this->originalDefault]);
        DB::disconnect('phase16_backup');

        parent::tearDown();
    }

    protected function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    /**
     * Point the DEFAULT connection at a real SQLite file WITHOUT purging the
     * migrated in-memory connection the rest of the suite depends on. Only
     * used when the suite runs on SQLite.
     */
    protected function useFileDatabase(): void
    {
        config(['database.connections.phase16_backup' => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'phase16_backup']);

        DB::connection('phase16_backup')->getPdo()->exec(
            'CREATE TABLE phase16_seed (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
        DB::connection('phase16_backup')->insert('INSERT INTO phase16_seed (name) VALUES (?)', ['hello']);
    }

    /**
     * Point the DEFAULT connection at an unreachable PostgreSQL connection so
     * the pg_dump path fails honestly. Only used when the suite runs on
     * PostgreSQL.
     */
    protected function useUnreachablePgsql(): void
    {
        config(['database.connections.phase16_broken' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'unreachable',
            'username' => 'nobody',
            'password' => 'nobody',
        ]]);
        config(['database.default' => 'phase16_broken']);
    }

    /**
     * Select a real, file-backed database for the current driver. On SQLite
     * that is the temp file above; on PostgreSQL the default connection is
     * already a real database, so nothing is needed.
     */
    protected function useRealDatabase(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->useFileDatabase();
        }
    }

    public function test_backup_fails_honestly_on_in_memory_database(): void
    {
        if ($this->driver() !== 'sqlite') {
            $this->markTestSkipped('In-memory refusal is a SQLite-specific scenario.');
        }

        // Default test env uses :memory: — BackupService must refuse, not
        // pretend to succeed.
        $result = app(BackupService::class)->create();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_backup_fails_honestly_when_database_unreachable(): void
    {
        if ($this->driver() !== 'pgsql') {
            $this->markTestSkipped('pg_dump connect-failure is a PostgreSQL-specific scenario.');
        }

        $this->useUnreachablePgsql();

        try {
            $result = app(BackupService::class)->create();

            $this->assertFalse($result['ok']);
            $this->assertArrayHasKey('error', $result);
        } finally {
            config(['database.default' => $this->originalDefault]);
            DB::disconnect('phase16_broken');
        }
    }

    public function test_backup_create_verify_and_restore_dry_run(): void
    {
        $this->useRealDatabase();

        try {
            $backups = app(BackupService::class);

            $result = $backups->create();

            $this->assertTrue($result['ok'], $result['error'] ?? '');
            $this->assertArrayHasKey('sha256', $result);
            $this->assertGreaterThan(0, $result['size']);

            $verify = $backups->verify($result['name']);
            $this->assertTrue($verify['ok'], json_encode($verify['checks'] ?? []));

            $restore = $backups->restoreDryRun($result['name']);
            $this->assertTrue($restore['ok'], $restore['error'] ?? '');
            $this->assertFileExists($restore['target']);
        } finally {
            config(['database.default' => $this->originalDefault]);
            DB::disconnect('phase16_backup');
        }
    }

    public function test_backup_verification_detects_corrupted_manifest(): void
    {
        $this->useRealDatabase();

        try {
            $backups = app(BackupService::class);

            $created = $backups->create();
            $this->assertTrue($created['ok']);

            // Corrupt the manifest to simulate a damaged backup.
            $manifestPath = storage_path('app/private/backups/'.$created['name'].'/manifest.json');
            File::put($manifestPath, '{"broken": true');

            $verify = $backups->verify($created['name']);
            $this->assertFalse($verify['ok']);
        } finally {
            config(['database.default' => $this->originalDefault]);
            DB::disconnect('phase16_backup');
        }
    }

    public function test_backup_retention_prunes_oldest(): void
    {
        $this->useRealDatabase();

        try {
            config(['backup.retention' => 1]);

            $backups = app(BackupService::class);

            $first = $backups->create();
            $this->assertTrue($first['ok']);

            sleep(1); // ensure distinct timestamped names

            $second = $backups->create();
            $this->assertTrue($second['ok']);

            $list = $backups->list();
            $this->assertCount(1, $list);
            $this->assertSame($second['name'], $list[0]['name']);
        } finally {
            config(['database.default' => $this->originalDefault]);
            DB::disconnect('phase16_backup');
        }
    }

    public function test_backup_list_is_empty_without_backups(): void
    {
        $this->assertSame([], app(BackupService::class)->list());
    }

    public function test_verify_unknown_backup_fails_cleanly(): void
    {
        $result = app(BackupService::class)->verify('does-not-exist');

        $this->assertFalse($result['ok']);
    }
}
