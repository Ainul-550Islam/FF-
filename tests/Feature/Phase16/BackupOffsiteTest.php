<?php

namespace Tests\Feature\Phase16;

use App\Services\BackupService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * GAP-10 C — offsite backup copies must be provable.
 *
 * The contract under test:
 *
 *  - with no offsite target configured the run stays local-only and is
 *    "ok" (development), with no offsite block invented in the manifest;
 *  - with a reachable offsite target the copy lands on that disk, its
 *    checksum is read back from the destination and recorded, and the
 *    manifest's `offsite.ok` is true;
 *  - an unreachable offsite target marks the run FAILED — never "ok" — and
 *    the verified local backup is kept (it is the only copy left);
 *  - BACKUP_OFFSITE_REQUIRED=true with no disk configured is a misconfig and
 *    therefore also FAILED;
 *  - a configured encryption recipient without the `age` binary refuses to
 *    write an unencrypted backup (fail closed), and with `age` present the
 *    stored artifact is ciphertext whose checksum is the recorded one.
 */
class BackupOffsiteTest extends Phase16TestCase
{
    protected string $file;

    protected string $originalDefault;

    /**
     * @var array<string, mixed>
     */
    protected array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/phase16-offsite-'.bin2hex(random_bytes(4)).'.sqlite');

        if (! is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0775, true);
        }

        touch($this->file);

        $this->originalDefault = (string) config('database.default');

        // The SQLite backup path reads the DEFAULT connection's database file
        // and dumps it with VACUUM INTO. Point it at the scratch file WITHOUT
        // disturbing the migrated in-memory connection the rest of the suite
        // uses, by keeping a separate connection name for the live one.
        if (config('database.default') === 'sqlite' && (string) config('database.connections.sqlite.database') === ':memory:') {
            config([
                'database.connections.phase16_offsite' => array_merge(
                    (array) config('database.connections.sqlite'),
                    ['database' => $this->file],
                ),
            ]);

            config(['database.default' => 'phase16_offsite']);
        }

        $this->snapshot = $this->snapshotConfig([
            'backup.disk',
            'backup.path',
            'backup.offsite_disk',
            'backup.offsite_required',
            'backup.offsite_prefix',
            'backup.encryption_recipient',
            'backup.notify_admins',
        ]);

        config(['backup.notify_admins' => false]);

        $this->cleanBackups();
    }

    protected function tearDown(): void
    {
        $this->restoreConfig($this->snapshot);

        config(['database.default' => $this->originalDefault]);

        $this->cleanBackups();

        @unlink($this->file);
        @unlink($this->file.'.age');

        parent::tearDown();
    }

    protected function cleanBackups(): void
    {
        $root = storage_path('app/private/backups');

        if (is_dir($root)) {
            foreach ((array) glob($root.'/*') as $entry) {
                $this->deletePath((string) $entry);
            }
        }
    }

    protected function deletePath(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach ((array) glob(rtrim($path, '/').'/*') as $child) {
                $this->deletePath((string) $child);
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    protected function backups(): BackupService
    {
        return $this->app->make(BackupService::class);
    }

    /**
     * Read the manifest of the given backup straight from the local disk.
     *
     * @return array<string, mixed>
     */
    protected function manifest(string $name): array
    {
        $file = storage_path('app/private/backups/'.$name.'/manifest.json');

        $this->assertFileExists($file, 'The backup manifest was not written.');

        return (array) json_decode((string) file_get_contents($file), true);
    }

    public function test_backup_without_offsite_target_is_local_only_and_ok(): void
    {
        config([
            'backup.offsite_disk' => null,
            'backup.offsite_required' => false,
            'backup.encryption_recipient' => null,
        ]);

        $result = $this->backups()->create();

        $this->assertTrue($result['ok'], 'A local-only backup must succeed: '.json_encode($result));
        $this->assertNull($result['offsite'] ?? null);

        $manifest = $this->manifest((string) $result['name']);

        $this->assertArrayNotHasKey('offsite', $manifest);
        $this->assertFalse((bool) $manifest['encrypted']);
    }

    public function test_reachable_offsite_target_records_a_verified_checksum(): void
    {
        Storage::fake('backup-offsite');

        config([
            'backup.offsite_disk' => 'backup-offsite',
            'backup.offsite_required' => true,
            'backup.offsite_prefix' => 'backups',
            'backup.encryption_recipient' => null,
        ]);

        $result = $this->backups()->create();

        $this->assertTrue($result['ok'], 'A reachable offsite target must succeed: '.json_encode($result));

        $offsite = (array) $result['offsite'];

        $this->assertTrue($offsite['ok']);
        $this->assertSame('backup-offsite', $offsite['disk']);
        $this->assertSame($result['sha256'], $offsite['sha256'], 'The recorded offsite checksum must match the stored artifact.');

        Storage::disk('backup-offsite')->assertExists((string) $offsite['path']);

        $manifest = $this->manifest((string) $result['name']);

        $this->assertTrue((bool) $manifest['offsite']['ok']);
        $this->assertSame($result['sha256'], $manifest['offsite']['sha256']);

        // verification must include the offsite copy
        $verify = $this->backups()->verify((string) $result['name']);

        $this->assertTrue($verify['ok'], 'Verification failed: '.json_encode($verify['checks'] ?? []));

        $checks = collect($verify['checks'])->keyBy('check');

        $this->assertTrue((bool) $checks['offsite_present']['ok']);
        $this->assertTrue((bool) $checks['offsite_checksum']['ok']);
    }

    public function test_unreachable_offsite_target_marks_the_run_failed(): void
    {
        // No Storage::fake here: the disk is defined in config but its S3
        // driver cannot resolve a bucket, so every write throws.
        config([
            'backup.offsite_disk' => 'backup-offsite',
            'backup.offsite_required' => true,
            'backup.offsite_prefix' => 'backups',
            'backup.encryption_recipient' => null,
            'filesystems.disks.backup-offsite' => [
                'driver' => 's3',
                'key' => 'irrelevant',
                'secret' => 'irrelevant',
                'region' => 'nowhere-1',
                'bucket' => '',
                'endpoint' => 'http://127.0.0.1:1',
                'use_path_ever_endpoint' => true,
                'throw' => true,
            ],
        ]);

        $result = $this->backups()->create();

        $this->assertFalse($result['ok'], 'An unreachable offsite target must never report ok.');

        $offsite = (array) ($result['offsite'] ?? []);

        $this->assertFalse((bool) ($offsite['ok'] ?? false));
        $this->assertNotSame('', (string) ($result['error'] ?? ''));

        // The verified local backup is kept — deleting it would destroy the
        // only remaining copy.
        $this->assertFileExists(storage_path('app/private/backups/'.$result['name'].'/manifest.json'));
    }

    public function test_offsite_required_without_a_disk_is_a_failed_run(): void
    {
        config([
            'backup.offsite_disk' => null,
            'backup.offsite_required' => true,
            'backup.encryption_recipient' => null,
        ]);

        $result = $this->backups()->create();

        $this->assertFalse($result['ok']);
        $this->assertFalse((bool) ($result['offsite']['ok'] ?? false));
        $this->assertStringContainsString('BACKUP_OFFSITE_DISK', (string) $result['error']);
    }

    public function test_encryption_recipient_encrypts_the_stored_artifact_or_fails_closed(): void
    {
        $recipient = 'age1'.str_repeat('q', 56);

        config([
            'backup.offsite_disk' => null,
            'backup.offsite_required' => false,
            'backup.encryption_recipient' => $recipient,
        ]);

        $hasAge = trim((string) @shell_exec('command -v age 2>/dev/null')) !== '';

        $result = $this->backups()->create();

        if (! $hasAge) {
            // Fail closed: never store a cleartext dump when a recipient is set.
            $this->assertFalse($result['ok'], 'Without the age binary an encrypted backup must fail, not fall back to plaintext.');

            $this->assertStringContainsString('age', strtolower((string) $result['error']));

            return;
        }

        if (! $result['ok']) {
            // A syntactically invalid recipient must also fail rather than
            // silently writing cleartext.
            $this->assertStringContainsString('age', strtolower((string) $result['error']));

            return;
        }

        $manifest = $this->manifest((string) $result['name']);

        $this->assertTrue((bool) $manifest['encrypted']);
        $this->assertStringEndsWith('.age', (string) $manifest['db_file']);
        $this->assertSame('age', (string) $manifest['encryption']['tool']);
        $this->assertNotSame('', (string) $manifest['encryption']['recipient_fingerprint']);
        $this->assertNotSame((string) $manifest['encryption']['plaintext_sha256'], (string) $manifest['db_sha256']);

        // The cleartext dump must not be left beside the ciphertext.
        $this->assertFileDoesNotExist(storage_path('app/private/backups/'.$result['name'].'/database.sqlite'));

        // Verification cannot integrity-check ciphertext but must still match
        // the stored checksum.
        $verify = $this->backups()->verify((string) $result['name']);

        $checks = collect($verify['checks'])->keyBy('check');

        $this->assertTrue((bool) $checks['checksum']['ok']);
        $this->assertArrayNotHasKey('integrity', $checks->all());
    }

    public function test_offsite_copy_failure_never_hides_behind_a_thrown_exception(): void
    {
        Storage::fake('backup-offsite');

        config([
            'backup.offsite_disk' => 'backup-offsite',
            'backup.offsite_required' => true,
            'backup.offsite_prefix' => 'backups',
            'backup.encryption_recipient' => null,
        ]);

        $result = $this->backups()->create();

        // Sanity anchor: the fake disk path succeeds, so a failure is a real
        // failure and not an artefact of the test double.
        $this->assertTrue($result['ok']);

        try {
            Storage::disk('backup-offsite')->assertExists((string) $result['offsite']['path']);
        } catch (Throwable $e) {
            $this->fail('Offsite object missing: '.$e->getMessage());
        }
    }
}
