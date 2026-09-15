<?php

namespace App\Console\Commands;

use App\Support\DatabaseTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19/G1 — deterministic, driver-agnostic database export.
 *
 *   php artisan ffarena:db:export
 *   php artisan ffarena:db:export sqlite --output=storage/app/private/cutover.sqlite.jsonl
 *
 * Streams every user table (foreign-key order) as JSONL plus a manifest
 * (row counts, per-table SHA-256 checksums, financial reconciliation sums).
 * The output is the portable format consumed by ffarena:db:import. It is
 * deterministic: rows are ordered by primary key when one exists.
 */
class DbExportCommand extends Command
{
    protected $signature = 'ffarena:db:export
        {connection? : Connection name to export (defaults to the default connection)}
        {--output= : Output path (defaults to storage/app/private/cutover/<timestamp>.jsonl)}';

    protected $description = 'Export the database to a deterministic, portable JSONL fixture with checksums';

    public function handle(): int
    {
        $name = $this->argument('connection') ?: (string) config('database.default');
        $db = DatabaseTransfer::connection($name);

        $output = $this->option('output')
            ?: storage_path('app/private/cutover/db-'.now()->format('Ymd-His').'-'.$name.'.jsonl');

        if (! is_dir(dirname($output)) && ! mkdir(dirname($output), 0755, true) && ! is_dir(dirname($output))) {
            $this->error('Cannot create output directory: '.dirname($output));

            return self::FAILURE;
        }

        $tables = DatabaseTransfer::dependencyOrder($db);
        $counts = DatabaseTransfer::rowCounts($db, $tables);
        $checksums = [];
        $totalRows = 0;

        $handle = fopen($output, 'w');

        if ($handle === false) {
            $this->error('Cannot open output file: '.$output);

            return self::FAILURE;
        }

        $this->info('Exporting connection ['.$name.'] ('.DatabaseTransfer::driver($db).') → '.$output);
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            $hash = hash_init('sha256');
            $rowCount = 0;

            fwrite($handle, json_encode(['__table__' => $table], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

            $db->table($table)->orderBy($this->primaryKeyOrId($db, $table))->chunkById(500, function ($rows) use ($handle, $hash, &$rowCount) {
                foreach ($rows as $row) {
                    $values = $this->normaliseRow((array) $row);
                    hash_update($hash, json_encode(DatabaseTransfer::canonicalRow($values), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    fwrite($handle, json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                    $rowCount++;
                }
            }, $this->primaryKeyOrId($db, $table));

            $checksums[$table] = hash_final($hash);
            $totalRows += $rowCount;
            $bar->advance();
        }

        fclose($handle);
        $bar->finish();
        $this->newLine();

        $manifest = [
            'format' => 'ffarena-db-export-v1',
            'driver' => DatabaseTransfer::driver($db),
            'connection' => $name,
            'exported_at' => now()->toIso8601String(),
            'tables' => $tables,
            'row_counts' => $counts,
            'total_rows' => $totalRows,
            'checksums' => $checksums,
            'financial_sums' => DatabaseTransfer::financialSums($db),
        ];

        $manifestPath = $output.'.manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Exported '.$totalRows.' rows across '.count($tables).' tables.');
        $this->line('  fixture:   '.$output);
        $this->line('  manifest:  '.$manifestPath);

        return self::SUCCESS;
    }

    /**
     * Best-effort primary key column for deterministic ordering.
     */
    private function primaryKeyOrId($db, string $table): string
    {
        if ($db->getSchemaBuilder()->hasColumn($table, 'id')) {
            return 'id';
        }

        // Fall back to the first column so ordering is still deterministic.
        $first = $db->getSchemaBuilder()->getColumnListing($table);

        return $first[0] ?? 'id';
    }

    /**
     * Normalise row values for JSON: objects → arrays, binary → error loudly.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_object($value)) {
                $row[$key] = json_decode(json_encode($value), true);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw new \RuntimeException(
                    "Column [{$key}] contains binary/non-UTF-8 data; use the pg_dump/BackupService path instead of the JSON exporter."
                );
            }
        }

        return $row;
    }
}
