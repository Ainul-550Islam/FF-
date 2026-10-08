<?php

namespace Tests\Feature\Phase16;

use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 16 / GAP-10 C — offsite mirror (copy + checksum + fail-closed) and
 * the automated restore drill.
 *
 * Driver-aware like BackupTest: on SQLite the suite points the default
 * connection at a temp file; on PostgreSQL the default connection is already
 * a real database.
 */
class BackupOffsiteAndDrillTest extends Phase16TestCase
{
    protected string $file;

    protected string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/phase16-offsite-'.bin2hex(random_bytes(4)).'.sqlite');
        $this->originalDefault = (string) config('database.default');

        File::ensureDirectoryExists(dirname($this->file));
        touch($this->file);

        // Each test starts with a clean backup directory.
        File::cleanDirectory(storage_path('app/private/backups'));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        File::cleanDirectory(storage_path('app/private/backups'));

        $this->restoreDatabase();

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
        config(['database.connections.phase16_offsite' => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'phase16_offsite']);

        DB::connection('phase16_offsite')->getPdo()->exec(
            'CREATE TABLE phase16_seed (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
        DB::connection('phase16_offsite')->insert('INSERT INTO phase16_seed (name) VALUES (?)', ['hello']);
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

    protected function restoreDatabase(): void
    {
        config(['database.default' => $this->originalDefault]);
        DB::disconnect('phase16_offsite');
    }

    public function test_offsite_mirror_copies_and_verifies_every_file(): void
    {
        Storage::fake('backup-offsite');
        config(['backup.offsite_disk' => 'backup-offsite', 'backup.offsite_required' => true]);
        $this->useRealDatabase();

        try {
            $result = app(BackupService::class)->create();

            $this->assertTrue($result['ok'], (string) ($result['error'] ?? 'create failed'));
            $name = (string) $result['name'];

            $offsite = Storage::disk('backup-offsite');
            $localDir = storage_path('app/private/backups/'.$name);

            foreach (File::allFiles($localDir) as $file) {
                $relative = ltrim(substr($file->getPathname(), strlen($localDir)), '/');
                $key = 'backups/'.$name.'/'.$relative;

                $this->assertTrue($offsite->exists($key), 'missing offsite copy: '.$key);
                $this->assertSame(
                    hash_file('sha256', $file->getPathname()),
                    hash('sha256', (string) $offsite->get($key)),
                    'offsite copy differs: '.$key
                );
            }
        } finally {
            $this->restoreDatabase();
        }
    }

    public function test_required_offsite_failure_fails_the_backup_closed(): void
    {
        config(['backup.offsite_disk' => 'phase16-no-such-disk', 'backup.offsite_required' => true]);
        $this->useRealDatabase();

        try {
            $result = app(BackupService::class)->create();

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('Offsite', (string) ($result['error'] ?? ''));
            // Fail-closed on the verdict, but the verified local backup is
            // kept (runbook §7): deleting it would destroy the only copy.
            $this->assertCount(1, app(BackupService::class)->list());
        } finally {
            $this->restoreDatabase();
        }
    }

    public function test_optional_offsite_failure_keeps_the_local_backup(): void
    {
        config(['backup.offsite_disk' => 'phase16-no-such-disk', 'backup.offsite_required' => false]);
        $this->useRealDatabase();

        try {
            $result = app(BackupService::class)->create();

            $this->assertTrue($result['ok'], (string) ($result['error'] ?? 'create failed'));
            $this->assertCount(1, app(BackupService::class)->list());
        } finally {
            $this->restoreDatabase();
        }
    }

    public function test_offsite_prune_enforces_retention(): void
    {
        Storage::fake('backup-offsite');
        config(['backup.offsite_disk' => 'backup-offsite', 'backup.offsite_required' => true, 'backup.retention' => 1]);
        $this->useRealDatabase();

        try {
            $backups = app(BackupService::class);

            $first = $backups->create();
            $this->assertTrue($first['ok']);

            sleep(1); // ensure distinct timestamped names

            $second = $backups->create();
            $this->assertTrue($second['ok']);

            $sets = Storage::disk('backup-offsite')->directories('backups');
            $this->assertCount(1, $sets);
            $this->assertSame('backups/'.$second['name'], $sets[0]);
        } finally {
            $this->restoreDatabase();
        }
    }

    public function test_drill_passes_a_full_restore_on_the_newest_backup(): void
    {
        if ($this->driver() === 'pgsql' && trim((string) shell_exec('command -v pg_restore 2>/dev/null')) === '') {
            $this->markTestSkipped('pg_restore not available.');
        }

        $this->useRealDatabase();

        try {
            $created = app(BackupService::class)->create();
            $this->assertTrue($created['ok'], (string) ($created['error'] ?? 'create failed'));

            $drill = app(BackupService::class)->drill();

            $this->assertTrue($drill['ok'], (string) ($drill['error'] ?? 'drill failed'));
            $this->assertSame('full', $drill['mode']);
            $this->assertSame($created['name'], $drill['backup']);
            $this->assertFileExists((string) $drill['target']);
        } finally {
            $this->restoreDatabase();
        }
    }

    public function test_drill_reports_blob_only_for_encrypted_backups_without_identity(): void
    {
        config(['backup.decryption_identity' => null]);

        $name = 'ffarena-20000101-000000';
        $dir = storage_path('app/private/backups/'.$name);
        File::ensureDirectoryExists($dir);

        // Ciphertext the service cannot read without age/a key — the drill
        // must verify the blob and say so, not fail and not decrypt.
        $ciphertext = 'not-a-real-ciphertext';
        File::put($dir.'/database.sqlite.age', $ciphertext);
        File::put($dir.'/manifest.json', json_encode([
            'name' => $name,
            'created_at' => '2000-01-01T00:00:00+00:00',
            'db_driver' => 'sqlite',
            'db_file' => 'database.sqlite.age',
            'db_sha256' => hash('sha256', $ciphertext),
            'db_size' => strlen($ciphertext),
            'encrypted' => true,
            'encryption' => ['tool' => 'age', 'recipient_fingerprint' => 'age-recipient:fixture', 'plaintext_sha256' => 'fictional'],
        ]));

        $drill = app(BackupService::class)->drill();

        $this->assertTrue($drill['ok']);
        $this->assertSame('blob-only', $drill['mode']);
        $this->assertSame($name, $drill['backup']);
    }
}
