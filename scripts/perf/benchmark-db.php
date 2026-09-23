<?php

/**
 * G3 — PostgreSQL/SQLite query baseline benchmark.
 *
 *   php scripts/perf/benchmark-db.php
 *   DB_CONNECTION=pgsql php scripts/perf/benchmark-db.php
 *   PERF_ITERATIONS=200 php scripts/perf/benchmark-db.php
 *
 * Measures the latency of the key read queries (tournaments, teams,
 * leaderboard/standings, notifications, audit, payments, webhook events) with
 * median/p90/p95/p99, and prints the query plan (EXPLAIN ANALYZE on
 * PostgreSQL, EXPLAIN QUERY PLAN on SQLite) for the most important joins so
 * sequential scans and missing indexes are visible.
 *
 * READ-ONLY. Requires a migrated database (run against the dedicated
 * non-production datastore, never against live data).
 */

require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\DB;

perf_header('DB query baseline ('.perf_driver().')');

$required = ['tournaments', 'teams', 'users', 'notifications', 'audit_logs', 'payments', 'webhook_events', 'scores'];

foreach ($required as $table) {
    try {
        DB::table($table)->selectRaw('1')->limit(1)->get();
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: table '{$table}' unavailable — run migrations first. ({$e->getMessage()})\n");
        exit(1);
    }
}

/**
 * A read-only benchmark query, expressed as a closure.
 *
 * @param  list<string>  $queryKeys
 * @return array<string, mixed>
 */
$bench = function (string $label, callable $query, int $iterations) {
    $samples = perf_sample($query, $iterations);
    $stats = perf_percentiles($samples);
    perf_line($label, $stats);

    return $stats;
};

$results = [];
$it = perf_env_int('PERF_ITERATIONS', 100);

// 1. Tournament discovery
$results['tournaments_list'] = $bench('tournaments list', function () {
    DB::table('tournaments')->where('status', 'open')->orderByDesc('created_at')->limit(24)->get();
}, $it);

// 2. Team lookup per tournament
$results['teams_by_tournament'] = $bench('teams by tournament', function () {
    $t = DB::table('tournaments')->select('id')->first();

    if ($t === null) {
        return;
    }

    DB::table('teams')->where('tournament_id', $t->id)->whereIn('status', ['pending', 'confirmed'])->get();
}, $it);

// 3. Leaderboard-style standings (points desc, tie-breakers)
$results['standings'] = $bench('standings (scores join)', function () {
    DB::table('scores')
        ->select('team_id', DB::raw('SUM(points) as total'))
        ->groupBy('team_id')
        ->orderByDesc('total')
        ->limit(24)
        ->get();
}, $it);

// 4. Notification inbox page
$results['notifications'] = $bench('notifications inbox', function () {
    $u = DB::table('users')->select('id')->first();

    if ($u === null) {
        return;
    }

    DB::table('notifications')->where('user_id', $u->id)->orderByDesc('id')->limit(20)->get();
}, $it);

// 5. Audit trail page
$results['audit'] = $bench('audit log page', function () {
    DB::table('audit_logs')->orderByDesc('id')->limit(50)->get();
}, $it);

// 6. Payment status
$results['payments'] = $bench('payments by status', function () {
    DB::table('payments')->whereIn('status', ['pending', 'processing'])->orderByDesc('id')->limit(50)->get();
}, $it);

// 7. Webhook events
$results['webhook_events'] = $bench('webhook events', function () {
    DB::table('webhook_events')->orderByDesc('id')->limit(50)->get();
}, $it);

// ---------------------------------------------------------------------
// Query plans (read-only)
// ---------------------------------------------------------------------
perf_header('Query plans');

$plans = [];

$plan = function (string $label, string $sql, array $bindings = []) use (&$plans) {
    if (perf_driver() === 'pgsql') {
        $rows = DB::select('EXPLAIN ANALYZE '.$sql, $bindings);
        $text = implode("\n", array_map(fn ($r) => (string) ($r->{'QUERY PLAN'} ?? json_encode($r)), $rows));
    } else {
        $rows = DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings);
        $text = implode("\n", array_map(fn ($r) => (string) ($r->detail ?? json_encode($r)), $rows));
    }

    echo "--- {$label} ---\n{$text}\n\n";
    $plans[$label] = $text;
};

try {
    $plan('standings aggregation', 'SELECT team_id, SUM(points) AS total FROM scores GROUP BY team_id ORDER BY total DESC LIMIT 24');
} catch (Throwable $e) {
    echo "plan skipped: {$e->getMessage()}\n";
}

try {
    $plan('payments by status', 'SELECT * FROM payments WHERE status IN (?, ?) ORDER BY id DESC LIMIT 50', ['pending', 'processing']);
} catch (Throwable $e) {
    echo "plan skipped: {$e->getMessage()}\n";
}

try {
    $plan('teams by tournament', 'SELECT * FROM teams WHERE tournament_id = ? AND status IN (?, ?)', [1, 'pending', 'confirmed']);
} catch (Throwable $e) {
    echo "plan skipped: {$e->getMessage()}\n";
}

// ---------------------------------------------------------------------
// Result persistence
// ---------------------------------------------------------------------
$path = perf_save('db', [
    'driver' => perf_driver(),
    'iterations' => $it,
    'queries' => $results,
    'plans' => $plans,
]);

echo "\nSaved: {$path}\n";
