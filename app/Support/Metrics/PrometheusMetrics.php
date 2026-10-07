<?php

namespace App\Support\Metrics;

use App\Contracts\MetricsInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * GAP-10 C — Prometheus-backed metrics.
 *
 * Counters/gauges/timings are aggregated in the shared cache (Redis or the
 * database store in production — never per-process) so a scrape from any web
 * process sees the whole application, and `/metrics` can render them in the
 * Prometheus exposition format.
 *
 * Design rules:
 *  - the measured *names* are exactly the ones the application already emits
 *    through App\Support\Metrics (`http.requests`, `http.server_errors`,
 *    `queue.jobs_failed`, …). Dots are translated to underscores at scrape
 *    time, which is the standard Prometheus mapping; the alert rules in
 *    deploy/prometheus-alerts.yml therefore reference names that exist.
 *  - every method is exception-safe: a metrics failure must never break a
 *    payment, payout, registration or score submission.
 *  - labels stay low-cardinality (the facade already truncates them) and
 *    every series is registered in an index so `/metrics` can enumerate it.
 *
 * The cache is deliberately the storage layer: metrics are operational data
 * with a short lifetime, and a cache flush losing counters is preferable to
 * writing a metric row for every request into the business database.
 */
class PrometheusMetrics implements MetricsInterface
{
    /**
     * Cache key holding the list of known series, so an exporter can
     * enumerate counters without scanning the whole keyspace (which Redis
     * KEYS/database LIKE scans make expensive and locks-prone).
     */
    public const INDEX_KEY = 'ffarena:metrics:index';

    /**
     * Series older than this stop being reported. Kept longer than the usual
     * scrape/alert window (1h) so a delayed scrape still sees the counters.
     */
    public const TTL_SECONDS = 7200;

    public function __construct(
        protected ?CacheRepository $cache = null,
        protected int $ttl = self::TTL_SECONDS,
    ) {
        $this->cache ??= Cache::store();
    }

    public function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        try {
            $key = $this->counterKey($metric, $labels);

            // add() seeds the counter atomically; increment() then bumps it.
            $this->cache->add($key, 0, $this->ttl);
            $this->cache->increment($key, $delta);

            $this->register($metric, 'counter', $labels, $key);
        } catch (Throwable) {
            // Never surface metrics failures into business code.
        }
    }

    public function gauge(string $metric, float $value, array $labels = []): void
    {
        try {
            $key = $this->gaugeKey($metric, $labels);

            $this->cache->put($key, $value, $this->ttl);

            $this->register($metric, 'gauge', $labels, $key);
        } catch (Throwable) {
        }
    }

    /**
     * Timings are exported as a counter pair (`_sum` in milliseconds and
     * `_count`), which is what the dashboards need for an average latency
     * without shipping a full histogram.
     */
    public function timing(string $metric, float $milliseconds, array $labels = []): void
    {
        try {
            $sumKey = $this->sumKey($metric, $labels);
            $countKey = $this->countKey($metric, $labels);

            // Sum is stored as a float, so read-modify-write is acceptable
            // here (unlike counters, an occasional lost sample cannot skew a
            // counter-based alert).
            $currentSum = (float) ($this->cache->get($sumKey) ?? 0.0);
            $currentCount = (int) ($this->cache->get($countKey) ?? 0);

            $this->cache->put($sumKey, $currentSum + $milliseconds, $this->ttl);
            $this->cache->put($countKey, $currentCount + 1, $this->ttl);

            $this->register($metric, 'summary', $labels, $sumKey);
        } catch (Throwable) {
        }
    }

    /**
     * Every registered series, newest state included.
     *
     * @return array<int, array{metric: string, type: string, labels: array<string, string>, value: float}>
     */
    public function series(): array
    {
        $index = [];

        try {
            $index = (array) $this->cache->get(self::INDEX_KEY, []);
        } catch (Throwable) {
            return [];
        }

        $series = [];

        foreach ($index as $entry) {
            if (! is_array($entry) || ! isset($entry['key'], $entry['metric'], $entry['type'])) {
                continue;
            }

            try {
                $value = $this->cache->get((string) $entry['key']);
            } catch (Throwable) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $series[] = [
                'metric' => (string) $entry['metric'],
                'type' => (string) $entry['type'],
                'labels' => (array) ($entry['labels'] ?? []),
                'value' => (float) $value,
            ];
        }

        return $series;
    }

    /**
     * Record a series in the index the exporter enumerates. Bounded: the
     * index is trimmed so a runaway label combination cannot grow forever.
     *
     * @param  array<string, string>  $labels
     */
    protected function register(string $metric, string $type, array $labels, string $key): void
    {
        $index = (array) $this->cache->get(self::INDEX_KEY, []);

        $signature = $type.'|'.$metric.'|'.$key;

        if (! isset($index[$signature])) {
            if (count($index) >= 500) {
                // Keep the newest 400 entries; losing the tail of a runaway
                // series is better than an unbounded cache key.
                $index = array_slice($index, -400, null, true);
            }

            $index[$signature] = [
                'metric' => $metric,
                'type' => $type,
                'labels' => $labels,
                'key' => $key,
            ];

            $this->cache->put(self::INDEX_KEY, $index, $this->ttl);
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function labelsSuffix(array $labels): string
    {
        if ($labels === []) {
            return 'none';
        }

        ksort($labels);

        return substr(sha1((string) json_encode($labels)), 0, 16);
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function counterKey(string $metric, array $labels): string
    {
        return 'ffarena:metrics:counter:'.$metric.':'.$this->labelsSuffix($labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function gaugeKey(string $metric, array $labels): string
    {
        return 'ffarena:metrics:gauge:'.$metric.':'.$this->labelsSuffix($labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function sumKey(string $metric, array $labels): string
    {
        return 'ffarena:metrics:timing_sum:'.$metric.':'.$this->labelsSuffix($labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function countKey(string $metric, array $labels): string
    {
        return 'ffarena:metrics:timing_count:'.$metric.':'.$this->labelsSuffix($labels);
    }
}
