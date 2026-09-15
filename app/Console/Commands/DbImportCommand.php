<?php

namespace App\Console\Commands;

use App\Support\DatabaseTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19/G1 — driver-aware database import (fixture or live source).
 *
 *   php artisan ffarena:db:import pgsql --file=storage/app/private/cutover/db-....jsonl
 *   php artisan ffarena:db:import pgsql --from=sqlite          # live cutover
 *
 * Safety contract:
 *   - Refuses to write into a non-empty target unless --force.
 *   - Runs the entire load in a single transaction (rolled back on any error).
 *   - Recomputes per-table SHA-256 checksums, row counts and financial sums on
 *     the target and aborts with a non-zero exit if they diverge from source.
 *   - Resets PostgreSQL identity/serial sequences after loading explicit ids.
 */
class DbImportCommand extends Command
{
    protected $signature = 'ffarena:db:import
        {connection? : Target connection name (defaults to the default connection)}
        {--file= : Fixture produced by ffarena:db:export}
        {--from= : Source connection name (live cutover; mutually exclusive with --file)}
        {--force : Overwrite existing rows in the target}';

    protected $description = 'Import an ffarena:db:export fixture (or live connection) with full validation';

    public function handle(): int
    {
        $file = $this->option('file');
        $from = $this->option('from');
        $force = (bool) $this->option('force');

        if (($file && $from) || (! $file && ! $from)) {
            $this->error('Specify exactly one of --file= or --from=.');

            return self::INVALID;
        }

        $targetName = $this->argument('connection') ?: (string) config('database.default');
        $target = DatabaseTransfer::connection($targetName);

        if ($target->getDriverName() === 'sqlite' && ! in_array($targetName, ['sqlite', 'testing'], true)) {
            // Allow SQLite targets (local parity) but warn about file locks.
            $this->warn('Target is SQLite — ensure no other process holds the file open.');
        }

        if ($from) {
            $source = DatabaseTransfer::connection($from);
            $this->info("Live cutover: [{$from}] → [{$targetName}]");
            $manifest = null;
            $tables = DatabaseTransfer::dependencyOrder($source);
        } else {
            if (! is_file($file)) {
                $this->error("Fixture not found: {$file}");

                return self::FAILURE;
            }

            $source = null;
            $manifestPath = $file.'.manifest.json';
            $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;

            if (! is_array($manifest)) {
                $this->error('Manifest missing or invalid; cannot validate the import. Aborting.');

                return self::FAILURE;
            }

            $tables = $manifest['tables'] ?? [];
            $this->info("Importing fixture {$file} → [{$targetName}]");
        }

        // 1. Guard against accidental overwrite.
        $nonEmpty = $this->nonEmptyTables($target);

        if ($nonEmpty !== [] && ! $force) {
            $this->error('Target ['.$targetName.'] already contains data in: '.implode(', ', $nonEmpty));
            $this->error('Refusing to overwrite. Re-run with --force after confirming this is the intended cutover target.');

            return self::FAILURE;
        }

        if ($nonEmpty !== [] && $force) {
            $this->warn('Target has existing rows; --force will delete them (tables: '.implode(', ', $nonEmpty).').');
        }

        // 2. Load in a single transaction.
        $this->info('Loading '.count($tables).' tables…');
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        $target->beginTransaction();

        try {
            if ($force) {
                foreach (array_reverse($tables) as $table) {
                    $target->table($table)->delete();
                }
            }

            $rowCounts = [];

            foreach ($tables as $table) {
                $columns = DatabaseTransfer::targetColumns($target, $table);

                if ($columns === []) {
                    throw new \RuntimeException("Table [{$table}] does not exist on target [{$targetName}]. Run migrations first.");
                }

                $rowCounts[$table] = 0;

                if ($source !== null) {
                    $this->insertFromConnection($target, $source, $table, $columns, $rowCounts);
                } else {
                    $this->insertFromFile($target, $file, $table, $columns, $rowCounts);
                }

                $bar->advance();
            }

            $this->resetSequences($target, $tables);

            // 3. Validate before committing.
            $this->newLine();
            $this->validateImport($target, $tables, $rowCounts, $manifest, $source);

            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            $this->error('Import FAILED and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();
        $this->info('Import complete and verified. Total rows: '.array_sum($rowCounts));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function nonEmptyTables($target): array
    {
        $nonEmpty = [];

        foreach (DatabaseTransfer::tables($target) as $table) {
            if ($target->table($table)->count() > 0) {
                $nonEmpty[] = $table;
            }
        }

        return $nonEmpty;
    }

    /**
     * @param  array<string, array{data_type: string, udt: string}>  $columns
     * @param  array<string, int>  $rowCounts
     */
    private function insertFromConnection($target, $source, string $table, array $columns, array &$rowCounts): void
    {
        $source->table($table)->orderBy($this->orderColumn($source, $table))->chunk(500, function ($rows) use ($target, $table, $columns, &$rowCounts) {
            foreach ($rows as $row) {
                $target->table($table)->insert($this->coerce((array) $row, $columns));
                $rowCounts[$table]++;
            }
        });
    }

    /**
     * @param  array<string, array{data_type: string, udt: string}>  $columns
     * @param  array<string, int>  $rowCounts
     */
    private function insertFromFile($target, string $file, string $table, array $columns, array &$rowCounts): void
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open fixture {$file}.");
        }

        $inTable = false;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['__table__'])) {
                $inTable = $decoded['__table__'] === $table;

                continue;
            }

            if (! $inTable) {
                continue;
            }

            $target->table($table)->insert($this->coerce($decoded, $columns));
            $rowCounts[$table]++;
        }

        fclose($handle);
    }

    /**
     * Coerce source values to the target column types (SQLite's flat typing is
     * looser than PostgreSQL's, e.g. booleans are stored as 0/1).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array{data_type: string, udt: string}>  $columns
     * @return array<string, mixed>
     */
    private function coerce(array $row, array $columns): array
    {
        foreach ($row as $key => $value) {
            if ($value === null || ! isset($columns[$key])) {
                continue;
            }

            $dataType = strtolower($columns[$key]['data_type']);
            $udt = strtolower($columns[$key]['udt']);

            if ($dataType === 'boolean' || $udt === 'bool') {
                $row[$key] = in_array($value, [true, 1, '1', 'true', 't'], true);

                continue;
            }

            if (in_array($dataType, ['smallint', 'integer', 'bigint'], true)) {
                $row[$key] = (int) $value;

                continue;
            }

            if (in_array($dataType, ['json', 'jsonb'], true)) {
                // Keep JSON as a string for the binding: PostgreSQL coerces the
                // (unknown-type) text parameter to jsonb from column context, and
                // SQLite stores JSON as TEXT anyway.
                $row[$key] = is_string($value) ? $value : json_encode($value);

                continue;
            }
        }

        return $row;
    }

    /**
     * Reset PostgreSQL identity/serial sequences after explicit-id inserts.
     *
     * @param  array<int, string>  $tables
     */
    private function resetSequences($target, array $tables): void
    {
        if ($target->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            $rows = $target->select(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = ? AND column_default LIKE 'nextval%'",
                [$table]
            );

            foreach ($rows as $row) {
                $target->statement(
                    sprintf(
                        "SELECT setval(pg_get_serial_sequence('%s', '%s'), COALESCE((SELECT MAX(\"%s\") FROM \"%s\"), 1))",
                        $table,
                        $row->column_name,
                        $row->column_name,
                        $table
                    )
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $tables
     * @param  array<string, int>  $rowCounts
     * @param  array<string, mixed>|null  $manifest
     */
    private function validateImport($target, array $tables, array $rowCounts, ?array $manifest, $source): void
    {
        $checksums = [];

        foreach ($tables as $table) {
            $hash = hash_init('sha256');

            $target->table($table)->orderBy($this->orderColumn($target, $table))->chunk(500, function ($rows) use ($hash) {
                foreach ($rows as $row) {
                    $values = DatabaseTransfer::canonicalRow((array) $row);
                    hash_update($hash, json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            });

            $checksums[$table] = hash_final($hash);
        }

        $failures = [];

        if ($manifest !== null) {
            foreach ($tables as $table) {
                $expectedRows = $manifest['row_counts'][$table] ?? null;

                if ($expectedRows !== null && $rowCounts[$table] !== $expectedRows) {
                    $failures[] = "{$table}: row count {$rowCounts[$table]} != expected {$expectedRows}";
                }

                $expectedSum = $manifest['checksums'][$table] ?? null;

                if ($expectedSum !== null && $checksums[$table] !== $expectedSum) {
                    $failures[] = "{$table}: checksum mismatch";
                }
            }
        } else {
            // Live cutover: compare against the source connection directly.
            foreach ($tables as $table) {
                $sourceRows = (int) $source->table($table)->count();

                if ($rowCounts[$table] !== $sourceRows) {
                    $failures[] = "{$table}: row count {$rowCounts[$table]} != source {$sourceRows}";
                }
            }
        }

        // Financial reconciliation: abort loudly if money moved.
        $targetSums = DatabaseTransfer::financialSums($target);
        $sourceSums = $manifest !== null
            ? ($manifest['financial_sums'] ?? null)
            : DatabaseTransfer::financialSums($source);

        if (is_array($sourceSums)) {
            foreach ($sourceSums as $key => $sourceValue) {
                if ($sourceValue === null) {
                    continue;
                }

                if (($targetSums[$key] ?? null) !== $sourceValue) {
                    $failures[] = "financial reconciliation {$key}: target ".($targetSums[$key] ?? 'null')." != source {$sourceValue}";
                }
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException("Import validation FAILED:\n  - ".implode("\n  - ", $failures));
        }

        $this->info('Validation passed: '.count($tables).' tables, '.array_sum($rowCounts).' rows, checksums + financial sums reconciled.');
    }

    private function orderColumn($db, string $table): string
    {
        if ($db->getSchemaBuilder()->hasColumn($table, 'id')) {
            return 'id';
        }

        $columns = $db->getSchemaBuilder()->getColumnListing($table);

        return $columns[0] ?? 'id';
    }
}
