<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 19/G1 — PostgreSQL JSON strategy.
     *
     * The historical migrations create JSON columns with ->json(). On SQLite
     * that is TEXT (unchanged); on PostgreSQL it is the `json` type. This
     * migration converts every `json` column in the public schema to `jsonb`
     * so PostgreSQL gets binary-storage JSON with deterministic key ordering,
     * while the application's existing `array` casts and JSON reads continue
     * to work identically (json and jsonb accept and return the same values
     * through Laravel's encoder/decoder).
     *
     * It is a no-op on every non-PostgreSQL driver, so SQLite local/test
     * remains exactly as before.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->jsonColumns('json') as [$table, $column]) {
            DB::statement(
                "ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE jsonb USING \"{$column}\"::jsonb"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->jsonColumns('jsonb') as [$table, $column]) {
            DB::statement(
                "ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE json USING \"{$column}\"::json"
            );
        }
    }

    /**
     * Discover the (table, column) pairs that use the given JSON data type in
     * the public schema.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function jsonColumns(string $type): array
    {
        $rows = DB::select(
            "SELECT table_name, column_name
               FROM information_schema.columns
              WHERE table_schema = 'public'
                AND data_type = ?
              ORDER BY table_name, column_name",
            [$type]
        );

        return array_map(
            fn ($row) => [$row->table_name, $row->column_name],
            $rows
        );
    }
};
