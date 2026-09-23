<?php

/**
 * G3 — authenticated API latency baseline (in-process HTTP kernel).
 *
 *   php scripts/perf/benchmark-api.php
 *   DB_CONNECTION=pgsql php scripts/perf/benchmark-api.php
 *
 * Fires full HTTP requests through the Laravel kernel (middleware, routing,
 * rate limiting, controllers, DB) and records endpoint latency percentiles
 * plus an error rate. This measures the application stack end-to-end for a
 * single worker; real multi-user behaviour is the k6 scripts' job.
 *
 * Uses a dedicated perf user + token minted once at startup. Run against the
 * dedicated non-production datastore, never live data.
 */

require __DIR__.'/bootstrap.php';

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

perf_header('API latency baseline ('.perf_driver().')');

$kernel = app(Kernel::class);
$it = perf_env_int('PERF_ITERATIONS', 50);

// Rate limiting is a separate concern measured by the k6 scripts under
// realistic concurrency. A single-worker latency sample firing N requests in
// ~1s from one IP/user would otherwise trip the production-equivalent limits
// and pollute the sample with 429s. For this run only, raise the API limits
// (configurable via PERF_RATE_LIMIT) and state it on the record.
$rateLimit = perf_env_int('PERF_RATE_LIMIT', 100000);
config(['api.rate_limits.api' => $rateLimit, 'api.rate_limits.api_anon' => $rateLimit]);

if (getenv('PERF_QUIET') !== '1') {
    printf("   API rate limits raised to %d/min for this run (throttling is measured by k6)\n", $rateLimit);
}

// A dedicated perf user + bearer token (created once, reused).
$user = User::where('email', 'perf-api@ffarena.local')->first();

if ($user === null) {
    $user = new User();
    $user->name = 'Perf API User';
    $user->email = 'perf-api@ffarena.local';
    $user->password = bcrypt('perf-only-password');
    $user->email_verified_at = now();
    $user->save();

    $user->role = 'player';
    $user->account_status = 'active';
    $user->save();
}

$token = $user->createToken('perf-harness', ['profile:read', 'wallet:read', 'notifications:read'])->plainTextToken;

// A stable tournament slug for the discovery/leaderboard endpoints.
$slug = (string) (DB::table('tournaments')->value('slug') ?? '');
$leaderboardPath = $slug !== '' ? '/api/v1/tournaments/'.$slug.'/leaderboard' : '/api/v1/leaderboards';

/**
 * Time one HTTP request through the kernel.
 *
 * @return array{status:int,ms:float}
 */
$fire = function (string $method, string $uri, array $headers = []) use ($kernel): array {
    $request = Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json'] + $headers);
    $start = perf_start();
    $response = $kernel->handle($request);
    $ms = (microtime(true) - $start) * 1000;
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'ms' => $ms];
};

/**
 * Benchmark an endpoint and capture latency + error rate.
 */
$bench = function (string $label, string $method, string $uri, array $headers = []) use ($fire, $it): array {
    $samples = [];
    $statuses = [];

    for ($i = 0; $i < $it; $i++) {
        $r = $fire($method, $uri, $headers);
        $statuses[$r['status']] = ($statuses[$r['status']] ?? 0) + 1;

        // Latency percentiles measure the application path, not the rate
        // limiter. A 429 means the request never reached the controller, so
        // it is reported separately rather than polluting the sample.
        if ($r['status'] !== 429) {
            $samples[] = $r['ms'];
        }
    }

    $stats = perf_percentiles($samples);
    $throttled = $statuses[429] ?? 0;
    $errors = array_sum(array_filter(
        $statuses,
        fn ($count, $status) => $status >= 400 && $status !== 429,
        ARRAY_FILTER_USE_BOTH,
    ));
    $ok = $it - $throttled - $errors;

    perf_line($label, $stats);
    printf(
        "   %-20s ok=%d throttled(429)=%d errors=%d\n",
        '',
        $ok,
        $throttled,
        $errors,
    );

    return [
        'stats' => $stats,
        'iterations' => $it,
        'ok' => $ok,
        'throttled' => $throttled,
        'errors' => $errors,
        'status_codes' => $statuses,
    ];
};

$results = [];
$bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

$results['health'] = $bench('GET /health', 'GET', '/health');
$results['tournaments_list'] = $bench('GET /api/v1/tournaments', 'GET', '/api/v1/tournaments');
$results['leaderboard'] = $bench('GET leaderboard', 'GET', $leaderboardPath);
$results['me'] = $bench('GET /api/v1/me (auth)', 'GET', '/api/v1/me', $bearer);
$results['wallet'] = $bench('GET /api/v1/me/wallet (auth)', 'GET', '/api/v1/me/wallet', $bearer);
$results['wallet_ledger'] = $bench('GET /api/v1/me/wallet/ledger (auth)', 'GET', '/api/v1/me/wallet/ledger', $bearer);

$path = perf_save('api', [
    'driver' => perf_driver(),
    'iterations' => $it,
    'rate_limit_override_per_minute' => $rateLimit,
    'endpoints' => $results,
]);

echo "\nSaved: {$path}\n";
