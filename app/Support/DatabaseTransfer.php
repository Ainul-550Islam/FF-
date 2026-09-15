<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19/G1 — driver-aware schema/data helpers for the SQLite → PostgreSQL
 * cutover tooling (ffarena:db:export / ffarena:db:transfer).
 *
 * Provides table discovery, foreign-key dependency ordering, row counts,
 * target column typing and financial reconciliation sums. Everything is
 * driver-aware: SQLite (PRAGMA) and PostgreSQL (information_schema) are both
 * supported without any business-logic changes.
 */
class DatabaseTransfer
{
    /**
     * User tables (excluding Laravel's migrations table and any SQLite
     * internal tables). Used for data transfer.
     *
     * @return array<int, string>
     */
    public static function tables(Connection $db): array
    {
        $driver = $db->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $db->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations' ORDER BY name"
            );

            return array_map(fn ($r) => $r->name, $rows);
        }

        if ($driver === 'pgsql') {
            $rows = $db->select(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name != 'migrations'
                  ORDER BY table_name"
            );

            return array_map(fn ($r) => $r->table_name, $rows);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $db->select(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ? AND table_name != ? ORDER BY table_name',
                ['BASE TABLE', 'migrations']
            );

            return array_map(fn ($r) => $r->table_name, $rows);
        }

        return [];
    }

    /**
     * Tables referenced by the given table's foreign keys (parents).
     *
     * @return array<int, string>
     */
    public static function referencedTables(Connection $db, string $table): array
    {
        $driver = $db->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $db->select(sprintf('PRAGMA foreign_key_list("%s")', str_replace('"', '""', $table)));

            return array_values(array_unique(array_filter(array_map(fn ($r) => $r->table ?? null, $rows))));
        }

        if ($driver === 'pgsql') {
            $rows = $db->select(
                "SELECT ccu.table_name AS referenced_table
                   FROM information_schema.table_constraints tc
                   JOIN information_schema.constraint_column_usage ccu
                     ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                  WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_schema = current_schema()
                    AND tc.table_name = ?",
                [$table]
            );

            return array_values(array_unique(array_map(fn ($r) => $r->referenced_table, $rows)));
        }

        return [];
    }

    /**
     * Tables in foreign-key dependency order (parents before children) so
     * rows can be inserted without violating constraints.
     *
     * @return array<int, string>
     */
    public static function dependencyOrder(Connection $db): array
    {
        $tables = self::tables($db);
        $parents = [];

        foreach ($tables as $table) {
            $parents[$table] = self::referencedTables($db, $table);
        }

        // Kahn's algorithm on the child -> parent graph.
        $childrenOf = [];

        foreach ($parents as $child => $refs) {
            foreach ($refs as $ref) {
                $childrenOf[$ref][] = $child;
            }
        }

        $indegree = [];

        foreach ($tables as $table) {
            $indegree[$table] = count($parents[$table]);
        }

        $queue = array_values(array_filter($tables, fn ($t) => $indegree[$t] === 0));
        $order = [];

        while ($queue !== []) {
            $table = array_shift($queue);
            $order[] = $table;

            foreach ($childrenOf[$table] ?? [] as $child) {
                $indegree[$child]--;

                if ($indegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        // Anything left has a cycle (unexpected for this schema) — append it
        // after the resolved order so no data is silently skipped.
        foreach ($tables as $table) {
            if (! in_array($table, $order, true)) {
                $order[] = $table;
            }
        }

        return $order;
    }

    /**
     * Row counts keyed by table name.
     *
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    public static function rowCounts(Connection $db, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) $db->table($table)->count();
        }

        return $counts;
    }

    /**
     * Target column type info: column => ['data_type' => ..., 'udt' => ...].
     *
     * @return array<string, array{data_type: string, udt: string}>
     */
    public static function targetColumns(Connection $db, string $table): array
    {
        $driver = $db->getDriverName();
        $columns = [];

        if ($driver === 'pgsql') {
            $rows = $db->select(
                'SELECT column_name, data_type, udt_name
                   FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = ?
                  ORDER BY ordinal_position',
                [$table]
            );

            foreach ($rows as $row) {
                $columns[$row->column_name] = ['data_type' => $row->data_type, 'udt' => $row->udt_name];
            }
        } elseif ($driver === 'sqlite') {
            $rows = $db->select(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)));

            foreach ($rows as $row) {
                $columns[$row->name] = ['data_type' => strtolower((string) $row->type), 'udt' => strtolower((string) $row->type)];
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $db->select(
                'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                [$table]
            );

            foreach ($rows as $row) {
                $columns[$row->column_name] = ['data_type' => strtolower((string) $row->data_type), 'udt' => strtolower((string) $row->data_type)];
            }
        }

        return $columns;
    }

    /**
     * Financial reconciliation sums (integer minor units) — used before and
     * after a cutover to prove no money changed. Returns null for a key when
     * the table/column does not exist on this connection.
     *
     * @return array<string, int|null>
     */
    public static function financialSums(Connection $db): array
    {
        $sums = [
            'payments_amount_minor' => null,
            'wallets_balance_minor' => null,
            'ledger_credits_minor' => null,
            'ledger_debits_minor' => null,
            'refunds_amount_minor' => null,
            'prize_tiers_amount_minor' => null,
            'prize_snapshot_amount_minor' => null,
            'payouts_amount_minor' => null,
            'settlement_gross_collected_minor' => null,
            'settlement_refunded_minor' => null,
            'settlement_net_collected_minor' => null,
            'settlement_prize_pool_minor' => null,
            'settlement_allocated_prizes_minor' => null,
            'settlement_completed_payouts_minor' => null,
            'settlement_platform_revenue_minor' => null,
        ];

        $map = [
            'payments_amount_minor' => ['payments', 'amount_minor'],
            'wallets_balance_minor' => ['wallets', 'balance_minor'],
            'refunds_amount_minor' => ['refunds', 'amount_minor'],
            'prize_tiers_amount_minor' => ['prize_tiers', 'amount_minor'],
            'prize_snapshot_amount_minor' => ['prize_snapshot_items', 'amount_minor'],
            'payouts_amount_minor' => ['payouts', 'amount_minor'],
            'settlement_gross_collected_minor' => ['financial_settlements', 'gross_collected_minor'],
            'settlement_refunded_minor' => ['financial_settlements', 'refunded_minor'],
            'settlement_net_collected_minor' => ['financial_settlements', 'net_collected_minor'],
            'settlement_prize_pool_minor' => ['financial_settlements', 'prize_pool_minor'],
            'settlement_allocated_prizes_minor' => ['financial_settlements', 'allocated_prizes_minor'],
            'settlement_completed_payouts_minor' => ['financial_settlements', 'completed_payouts_minor'],
            'settlement_platform_revenue_minor' => ['financial_settlements', 'platform_revenue_minor'],
        ];

        foreach ($map as $key => [$table, $column]) {
            if (! $db->getSchemaBuilder()->hasTable($table) || ! $db->getSchemaBuilder()->hasColumn($table, $column)) {
                continue;
            }

            $sums[$key] = (int) $db->table($table)->sum($column);
        }

        if ($db->getSchemaBuilder()->hasTable('ledger_entries') && $db->getSchemaBuilder()->hasColumn('ledger_entries', 'direction')) {
            $sums['ledger_credits_minor'] = (int) $db->table('ledger_entries')->where('direction', 'credit')->sum('amount_minor');
            $sums['ledger_debits_minor'] = (int) $db->table('ledger_entries')->where('direction', 'debit')->sum('amount_minor');
        }

        return $sums;
    }

    /**
     * Canonicalise a row so the same logical data hashes identically across
     * drivers. Normalises: booleans → '0'/'1', numbers → canonical decimal
     * strings, JSON strings → key-sorted compact JSON, objects → arrays.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function canonicalRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = self::canonicalValue($value);
        }

        return $row;
    }

    /**
     * @return mixed
     */
    public static function canonicalValue($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::canonicalNumber((string) $value);
        }

        if (is_array($value)) {
            $value = self::sortKeysRecursive($value);

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_object($value)) {
            return self::canonicalValue(json_decode(json_encode($value), true));
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return json_encode(self::sortKeysRecursive($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if ($value !== '' && is_numeric($value)) {
                return self::canonicalNumber($value);
            }

            return $value;
        }

        return (string) $value;
    }

    /**
     * Canonical decimal representation: no leading zeros, no trailing zeros,
     * no exponent (converted to fixed form).
     */
    public static function canonicalNumber(string $value): string
    {
        if (preg_match('/[eE]/', $value)) {
            $value = sprintf('%.10F', (float) $value);
        }

        $negative = str_starts_with($value, '-');
        $body = $negative ? substr($value, 1) : $value;
        $body = ltrim($body, '0');

        if ($body === '' || $body[0] === '.') {
            $body = '0'.$body;
        }

        if (str_contains($body, '.')) {
            [$int, $frac] = explode('.', $body, 2);
            $frac = rtrim($frac, '0');

            $body = $frac === '' ? $int : $int.'.'.$frac;
        }

        return ($negative ? '-' : '').$body;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function sortKeysRecursive(array $value): array
    {
        $assoc = array_keys($value) !== range(0, count($value) - 1);

        if ($assoc) {
            ksort($value);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortKeysRecursive($v);
            }
        }

        return $value;
    }

    /**
     * Current default connection driver name.
     */
    public static function driver(Connection $db): string
    {
        return (string) $db->getDriverName();
    }

    /**
     * Resolve a connection by name, with a helpful failure.
     */
    public static function connection(string $name): Connection
    {
        try {
            return DB::connection($name);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Database connection [{$name}] is not configured: ".$e->getMessage());
        }
    }
}
