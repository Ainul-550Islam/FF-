<?php

namespace Tests\Feature\Phase16;

use App\Services\BackupService;
use PDO;
use RuntimeException;

/**
 * GAP-10 C (finding F-19) — restoring an age-encrypted backup.
 *
 * The backup path has always been able to *store* an encrypted dump, but the
 * restore path handed the ciphertext straight to pg_restore / the SQLite
 * integrity check, which reported the misleading
 * "input file does not appear to be a valid archive". An operator following
 * the disaster-recovery runbook would have concluded the backup itself was
 * broken.
 *
 * These tests pin the behaviour that replaced it:
 *
 *  - without the age identity the run FAILS CLOSED with instructions that name
 *    the flag and the environment variable — never a false "archive is bad";
 *  - with the identity the backup round-trips, the decrypted plaintext is
 *    verified against the sha256 the manifest recorded, and the *cleartext*
 *    copy is deleted again before the call returns;
 *  - a wrong identity and a tampered ciphertext both fail closed.
 *
 * The first test needs no binaries at all (it builds an encrypted manifest by
 * hand), so it is the required-test entry and never skips. The remaining three
 * drive the real `age` / `age-keygen` binaries end to end and skip as a group
 * when they are not installed — CI installs them in the PostgreSQL and
 * integration jobs, and the ops-toolchain job proves the same toolchain.
 */
class BackupEncryptedRestoreTest extends Phase16TestCase
{
    protected string $file;

    protected string $originalDefault;

    protected string $keys;

    /**
     * @var array<string, mixed>
     */
    protected array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/phase16-encrypted-'.bin2hex(random_bytes(4)).'.sqlite');
        $this->keys = storage_path('framework/testing/phase16-keys-'.bin2hex(random_bytes(4)));

        if (! is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0775, true);
        }

        if (! is_dir($this->keys)) {
            mkdir($this->keys, 0775, true);
        }

        $this->seedScratchDatabase();

        $this->originalDefault = (string) config('database.default');

        // Dump from a file-backed SQLite database of our own, whatever profile
        // is running: the class then behaves identically on SQLite and
        // PostgreSQL instead of dumping the suite's database.
        config([
            'database.connections.phase16_encrypted' => [
                'driver' => 'sqlite',
                'database' => $this->file,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        config(['database.default' => 'phase16_encrypted']);

        $this->snapshot = $this->snapshotConfig([
            'backup.disk',
            'backup.path',
            'backup.offsite_disk',
            'backup.offsite_required',
            'backup.offsite_prefix',
            'backup.encryption_recipient',
            'backup.decryption_identity',
            'backup.notify_admins',
        ]);

        config([
            'backup.notify_admins' => false,
            'backup.offsite_disk' => null,
            'backup.offsite_required' => false,
            'backup.encryption_recipient' => null,
            'backup.decryption_identity' => null,
        ]);

        $this->cleanBackups();
    }

    protected function tearDown(): void
    {
        $this->restoreConfig($this->snapshot);

        config(['database.default' => $this->originalDefault]);

        $this->cleanBackups();

        @unlink($this->file);
        @unlink($this->file.'.age');

        $this->deletePath($this->keys);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Required test — no external binaries, runs on every profile
    // -----------------------------------------------------------------------

    public function test_an_encrypted_backup_without_an_identity_fails_closed_by_design(): void
    {
        config(['backup.decryption_identity' => null]);

        // A backup written by the encrypted path: the artifact is ciphertext,
        // so nothing can read it without the identity.
        $name = $this->writeEncryptedBackupFixture();

        $result = $this->backups()->restoreDryRun($name);

        $this->assertFalse($result['ok'], 'An encrypted backup must not be reported as restorable without the identity.');

        $error = (string) ($result['error'] ?? '');

        $this->assertStringContainsString('age-encrypted', $error);
        $this->assertStringContainsString('--identity', $error);
        $this->assertStringContainsString('BACKUP_DECRYPTION_IDENTITY', $error);
        $this->assertStringNotContainsString(
            'does not appear to be a valid archive',
            $error,
            'The message must name the real cause (a missing key), not blame the archive.',
        );
    }

    // -----------------------------------------------------------------------
    // Real end-to-end round trip (needs the age + age-keygen binaries)
    // -----------------------------------------------------------------------

    public function test_an_encrypted_backup_round_trips_and_leaves_no_cleartext_behind(): void
    {
        [$identity, $recipient] = $this->makeAgeKeypair();

        config(['backup.encryption_recipient' => $recipient]);

        $created = $this->backups()->create();

        $this->assertTrue($created['ok'], 'The encrypted backup must be created: '.json_encode($created));

        $name = (string) $created['name'];
        $manifest = $this->manifest($name);

        $this->assertTrue((bool) $manifest['encrypted']);
        $this->assertSame('database.sqlite.age', (string) $manifest['db_file']);
        $this->assertNotSame('', (string) ($manifest['encryption']['plaintext_sha256'] ?? ''));

        $dir = storage_path('app/private/backups/'.$name);

        $this->assertFileDoesNotExist($dir.'/database.sqlite', 'The cleartext dump must not be left beside the ciphertext.');

        // Without the identity: closed.
        $this->assertFalse($this->backups()->restoreDryRun($name)['ok']);

        // With the identity: the ciphertext is decrypted, checked against the
        // manifest digest, and handed to the normal restore path.
        config(['backup.decryption_identity' => $identity]);

        $result = $this->backups()->restoreDryRun($name);

        $this->assertTrue($result['ok'], 'Encrypted restore failed: '.json_encode($result));

        $target = (string) $result['target'];

        $this->assertFileExists($target);

        // The restored copy is a readable database carrying our scratch row.
        // (SQLite adds its own `sqlite_sequence` bookkeeping table, so this
        // asserts on the table we created rather than on the whole listing.)
        $this->assertContains('marker', $this->tables($target));
        $this->assertSame(1, $this->countMarkerRows($target));

        // …and the decrypted temporary file is gone again.
        $this->assertSame(
            [],
            glob($dir.'/*.restore-*.tmp') ?: [],
            'The decrypted plaintext must not survive the call.',
        );
    }

    public function test_a_wrong_identity_fails_closed(): void
    {
        [$identity, $recipient] = $this->makeAgeKeypair();

        config(['backup.encryption_recipient' => $recipient]);

        $created = $this->backups()->create();
        $this->assertTrue($created['ok'], json_encode($created));

        // A second, unrelated key: its *identity* is what we try to decrypt
        // with, and it does not match the first key's recipient.
        [$strangerIdentity] = $this->makeAgeKeypair('stranger');

        config(['backup.decryption_identity' => $strangerIdentity]);

        $result = $this->backups()->restoreDryRun((string) $created['name']);

        $this->assertFalse($result['ok'], 'A key that does not match the recipient must not restore anything.');
        $this->assertStringContainsString('could not decrypt', (string) ($result['error'] ?? ''));
    }

    public function test_a_tampered_ciphertext_fails_closed(): void
    {
        [$identity, $recipient] = $this->makeAgeKeypair();

        config(['backup.encryption_recipient' => $recipient]);

        $created = $this->backups()->create();
        $this->assertTrue($created['ok'], json_encode($created));

        $name = (string) $created['name'];
        $cipher = storage_path('app/private/backups/'.$name.'/database.sqlite.age');

        // Flip a byte in the middle of the ciphertext: age authenticates its
        // payload, so this must never produce a "restored" result.
        $bytes = (string) file_get_contents($cipher);
        $middle = (int) (strlen($bytes) / 2);
        $bytes[$middle] = $bytes[$middle] === 'A' ? 'B' : 'A';
        file_put_contents($cipher, $bytes);

        config(['backup.decryption_identity' => $identity]);

        $result = $this->backups()->restoreDryRun($name);

        $this->assertFalse($result['ok'], 'A corrupted ciphertext must fail closed.');
        $this->assertSame(
            [],
            glob(storage_path('app/private/backups/'.$name).'/*.restore-*.tmp') ?: [],
            'A failed decryption must not leave a partial plaintext behind.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Generate an age keypair; returns [identity file, recipient].
     *
     * @return array{0: string, 1: string}
     */
    protected function makeAgeKeypair(string $label = 'backup'): array
    {
        $ageKeygen = $this->binary('age-keygen');

        if ($ageKeygen === null) {
            $this->markTestSkipped('The age toolchain (age-keygen) is not installed on this machine.');
        }

        $identity = $this->keys.'/'.$label.'-identity.txt';

        $output = [];
        $code = 1;

        exec(escapeshellarg($ageKeygen).' --output '.escapeshellarg($identity).' 2>&1', $output, $code);

        if ($code !== 0 || ! is_file($identity)) {
            $this->fail('age-keygen failed: '.implode(' ', array_slice($output, 0, 3)));
        }

        $recipient = '';

        foreach (preg_split('/\R/', (string) file_get_contents($identity)) ?: [] as $line) {
            if (preg_match('/(age1[0-9a-z]{20,})/', $line, $matches) === 1) {
                $recipient = $matches[1];

                break;
            }
        }

        if ($recipient === '') {
            $this->fail('Could not read the public key out of the generated age identity.');
        }

        return [$identity, $recipient];
    }

    /**
     * A backup directory whose manifest claims an age-encrypted dump, without
     * needing the age binary to exist.
     */
    protected function writeEncryptedBackupFixture(): string
    {
        $name = 'phase16-encrypted-fixture';
        $dir = storage_path('app/private/backups/'.$name);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir.'/database.sqlite.age', 'age-encryption.org/v1'.str_repeat("\x00", 128));

        file_put_contents($dir.'/manifest.json', json_encode([
            'name' => $name,
            'created_at' => now()->toIso8601String(),
            'app_env' => 'testing',
            'db_driver' => 'sqlite',
            'db_file' => 'database.sqlite.age',
            'db_sha256' => hash('sha256', (string) file_get_contents($dir.'/database.sqlite.age')),
            'db_size' => (int) filesize($dir.'/database.sqlite.age'),
            'private_files' => 0,
            'private_size' => 0,
            'size' => (int) filesize($dir.'/database.sqlite.age'),
            'checksum' => 'sha256',
            'encrypted' => true,
            'encryption' => [
                'tool' => 'age',
                'recipient_fingerprint' => 'age-recipient:fixture',
                'plaintext_sha256' => hash('sha256', 'fixture'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $name;
    }

    /**
     * A tiny file-backed SQLite database with one marker row.
     */
    protected function seedScratchDatabase(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }

        $pdo = new PDO('sqlite:'.$this->file);
        $pdo->exec('CREATE TABLE marker (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT NOT NULL)');
        $pdo->exec("INSERT INTO marker (note) VALUES ('gap10-encrypted-restore')");
        $pdo = null;
    }

    /**
     * @return array<int, string>
     */
    protected function tables(string $sqliteFile): array
    {
        $pdo = new PDO('sqlite:'.$sqliteFile);

        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn ($name): string => (string) $name, $names));
    }

    protected function countMarkerRows(string $sqliteFile): int
    {
        $pdo = new PDO('sqlite:'.$sqliteFile);

        return (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn();
    }

    protected function binary(string $tool): ?string
    {
        $path = trim((string) @shell_exec('command -v '.escapeshellarg($tool).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    protected function backups(): BackupService
    {
        return $this->app->make(BackupService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function manifest(string $name): array
    {
        $file = storage_path('app/private/backups/'.$name.'/manifest.json');

        $this->assertFileExists($file, 'The backup manifest was not written.');

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The backup manifest is not valid JSON.');
        }

        return $decoded;
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
}
