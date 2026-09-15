<?php

/**
 * G3 — leaderboard / standings performance benchmark.
 *
 *   php scripts/perf/benchmark-leaderboard.php
 *   DB_CONNECTION=pgsql php scripts/perf/benchmark-leaderboard.php
 *
 * Measures the two leaderboard code paths under read load:
 *   1. ScoringService::standings() — the service-level aggregation used by
 *      the web + API leaderboard controllers;
 *   2. the HTTP leaderboard endpoint through the kernel.
 *
 * Read-only. Run against the dedicated non-production datastore.
 */

require __DIR__.'/bootstrap.php';

use App\Models\Tournament;
use App\Services\ScoringService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

perf_header('Leaderboard benchmark ('.perf_driver().')');

$it = perf_env_int('PERF_ITERATIONS', 50);
$scoring = app(ScoringService::class);
$kernel = app(Kernel::class);

$tournament = Tournament::where('status', '!=', 'draft')->orderBy('id')->first();

if ($tournament === null) {
    fwrite(STDERR, "ERROR: no tournament available — seed the database first.\n");
    exit(1);
}

// 1. Service-level standings.
$samples = perf_sample(function () use ($scoring, $tournament) {
    $scoring->standings($tournament);
}, $it);

$stats = perf_percentiles($samples);
perf_line('ScoringService::standings', $stats);
$serviceStats = $stats;

// 2. HTTP endpoint.
$slug = $tournament->slug;
$errors = 0;
$httpSamples = [];

for ($i = 0; $i < $it; $i++) {
    $request = Request::create('/tournaments/'.$slug.'/leaderboard', 'GET');
    $start = perf_start();
    $response = $kernel->handle($request);
    $httpSamples[] = (microtime(true) - $start) * 1000;
    $kernel->terminate($request, $response);

    if ($response->getStatusCode() >= 400) {
        $errors++;
    }
}

$httpStats = perf_percentiles($httpSamples);
perf_line('GET leaderboard (HTTP)', $httpStats);
printf("   %-20s error rate: %.2f%%\n", '', round(($errors / $it) * 100, 2));

$path = perf_save('leaderboard', [
    'driver' => perf_driver(),
    'tournament_slug' => $slug,
    'iterations' => $it,
    'service' => $serviceStats,
    'http' => $httpStats,
    'http_error_pct' => round(($errors / $it) * 100, 2),
]);

echo "\nSaved: {$path}\n";
