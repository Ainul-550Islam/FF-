<?php

/**
 * G3 — performance harness bootstrap.
 *
 * Required by every scripts/perf/benchmark-*.php. Boots the Laravel
 * application exactly like `php artisan` does and provides the shared
 * measurement helpers (timing, percentile math, report rendering, env-safe
 * configuration). No benchmark stores state here — this file is pure setup.
 *
 * Environment:
 *   DB_CONNECTION  override the datastore (sqlite|pgsql); defaults to the
 *                  app's configured driver.
 *   PERF_ITERATIONS  default iterations per measurement (default 100).
 *   PERF_QUIET       set to 1 to suppress the human header.
 *
 * Exit policy: any benchmark that cannot run honestly (missing table, DB
 * unreachable) exits non-zero — never prints fabricated numbers.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;

$perfRoot = dirname(__DIR__, 2); // project root

require $perfRoot.'/vendor/autoload.php';

/** @var Application $app */
$perfApp = require $perfRoot.'/bootstrap/app.php';

$perfApp->make(Kernel::class)->bootstrap();

/**
 * Resolve an integer env value with a fallback.
 */
function perf_env_int(string $key, int $default): int
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return max(1, (int) $value);
}

/**
 * Start a named timer. Returns a float microtime.
 */
function perf_start(): float
{
    return microtime(true);
}

/**
 * Milliseconds elapsed since a perf_start() marker.
 */
function perf_ms(float $start): float
{
    return round((microtime(true) - $start) * 1000, 3);
}

/**
 * Compute median/p90/p95/p99 from a sample of milliseconds (linear
 * interpolation-free nearest-rank method — deterministic and dependency-free).
 *
 * @param  list<float>  $samples
 * @return array{p50:float,p90:float,p95:float,p99:float,min:float,max:float,n:int,mean:float}
 */
function perf_percentiles(array $samples): array
{
    $n = count($samples);

    if ($n === 0) {
        return ['p50' => 0.0, 'p90' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'min' => 0.0, 'max' => 0.0, 'n' => 0, 'mean' => 0.0];
    }

    sort($samples);

    $pick = function (float $p) use ($samples, $n): float {
        $idx = (int) ceil($p * $n) - 1;
        $idx = max(0, min($n - 1, $idx));

        return $samples[$idx];
    };

    return [
        'p50' => round($pick(0.50), 3),
        'p90' => round($pick(0.90), 3),
        'p95' => round($pick(0.95), 3),
        'p99' => round($pick(0.99), 3),
        'min' => round($samples[0], 3),
        'max' => round($samples[$n - 1], 3),
        'n' => $n,
        'mean' => round(array_sum($samples) / $n, 3),
    ];
}

/**
 * Run a callable N times and return millisecond samples.
 *
 * @return list<float>
 */
function perf_sample(callable $fn, ?int $iterations = null): array
{
    $iterations ??= perf_env_int('PERF_ITERATIONS', 100);
    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = perf_start();
        $fn();
        $samples[] = (microtime(true) - $start) * 1000;
    }

    return $samples;
}

/**
 * Render a benchmark section header.
 */
function perf_header(string $title): void
{
    if (getenv('PERF_QUIET') === '1') {
        return;
    }

    echo "\n==================================================\n";
    echo " {$title}\n";
    echo "==================================================\n";
}

/**
 * Render a measured metric line.
 */
function perf_line(string $label, array $stats, string $unit = 'ms'): void
{
    printf(
        " %-22s p50 %8.3f %s | p90 %8.3f | p95 %8.3f | p99 %8.3f | min %8.3f | max %8.3f | n=%d\n",
        $label,
        $stats['p50'], $unit,
        $stats['p90'],
        $stats['p95'],
        $stats['p99'],
        $stats['min'],
        $stats['max'],
        $stats['n'],
    );
}

/**
 * The active datastore driver for benchmarks (env override wins).
 */
function perf_driver(): string
{
    $override = getenv('DB_CONNECTION');

    return $override !== false && $override !== '' ? $override : (string) config('database.default');
}

/**
 * Persist a benchmark result set as JSON under storage/perf/.
 *
 * A PERF_TAG env var (e.g. PERF_TAG=pgsql) suffixes the file and the result
 * name so the same benchmark can be recorded against multiple datastores
 * (SQLite vs PostgreSQL) without overwriting each other.
 */
function perf_save(string $name, array $payload): string
{
    $dir = dirname(__DIR__, 2).'/storage/perf';

    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $tag = getenv('PERF_TAG');
    $tag = ($tag !== false && $tag !== '') ? $tag : null;

    $fileName = $name.($tag !== null ? '.'.$tag : '').'.json';
    $resultName = $name.($tag !== null ? '.'.$tag : '');

    $path = $dir.'/'.$fileName;

    file_put_contents($path, json_encode(array_merge([
        'name' => $resultName,
        'driver' => perf_driver(),
        'recorded_at' => gmdate('c'),
        'php' => PHP_VERSION,
    ], $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $path;
}

echo '[perf] booted Laravel '.app()->version().' on '.perf_driver()."\n";
