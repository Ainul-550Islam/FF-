<?php

namespace App\Http\Controllers;

use App\Contracts\MetricsInterface;
use App\Support\Metrics\PrometheusMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GAP-10 C — Prometheus scrape endpoint.
 *
 * Renders the counters/gauges/timings the application actually emits (see
 * App\Support\Metrics call sites) plus a small set of operational gauges that
 * are read straight from the database and the backup manifests:
 * queue depth, failed jobs, the age of the newest verified backup and the
 * scheduler heartbeat.
 *
 * Fail-closed by design:
 *  - the endpoint 404s unless `FEATURE_PROMETHEUS=true`;
 *  - it 404s when no scrape token is configured, so it can never be exposed
 *    unauthenticated by omission;
 *  - it requires `Authorization: Bearer <METRICS_SCRAPE_TOKEN>`, compared in
 *    constant time;
 *  - a scrape error never 500s: each gauge is resolved defensively, because a
 *    monitoring endpoint that fails exactly when the system is unhealthy is
 *    worse than useless.
 */
class MetricsController extends Controller
{
    /**
     * Prometheus text exposition (version 0.0.4).
     */
    public function __invoke(Request $request): Response
    {
        $token = (string) config('observability.metrics.scrape_token', '');

        if (! (bool) config('observability.metrics.enabled', false) || $token === '') {
            // Monitoring must never be reachable by accident: an unconfigured
            // endpoint reports "not found", exactly like a route that does
            // not exist.
            abort(404);
        }

        $presented = (string) $request->bearerToken();

        if ($presented === '' || ! hash_equals($token, $presented)) {
            abort(401);
        }

        return response(
            $this->render(),
            200,
            [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store, private',
            ]
        );
    }

    /**
     * The full exposition body.
     */
    protected function render(): string
    {
        $lines = [];

        foreach ($this->applicationSeries() as $series) {
            $name = $this->prometheusName((string) $series['metric']);
            $labels = $this->renderLabels((array) $series['labels']);
            $value = $this->renderValue((float) $series['value']);

            if ($series['type'] === 'counter') {
                $lines[] = '# TYPE '.$name.' counter';
                $lines[] = $name.$labels.' '.$value;
            } elseif ($series['type'] === 'summary') {
                $lines[] = '# TYPE '.$name.' summary';
                $lines[] = $name.'_sum'.$labels.' '.$value;
            } else {
                $lines[] = '# TYPE '.$name.' gauge';
                $lines[] = $name.$labels.' '.$value;
            }
        }

        // Timing counts are registered alongside their sums so an average can
        // be computed without a histogram.
        foreach ($this->timingCounts() as $metric => $count) {
            $name = $this->prometheusName($metric);

            $lines[] = '# TYPE '.$name.' summary';
            $lines[] = $name.'_count '.$this->renderValue((float) $count);
        }

        foreach ($this->operationalGauges() as $name => $value) {
            $lines[] = '# TYPE '.$name.' gauge';
            $lines[] = $name.' '.$this->renderValue((float) $value);
        }

        // A static, always-present series: an alert on `ffarena_up == 0` is
        // meaningful because the value is emitted even when the gauges above
        // could not be resolved.
        $lines[] = '# TYPE ffarena_up gauge';
        $lines[] = 'ffarena_up 1';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, array{metric: string, type: string, labels: array<string, string>, value: float}>
     */
    protected function applicationSeries(): array
    {
        try {
            $driver = app(MetricsInterface::class);

            if (! $driver instanceof PrometheusMetrics) {
                // The configured backend keeps metrics in the log stream; the
                // exposition still works and stays honest — it only reports
                // the operational gauges below.
                return [];
            }

            return $driver->series();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int>
     */
    protected function timingCounts(): array
    {
        $counts = [];

        try {
            foreach ($this->rawIndex() as $entry) {
                if (($entry['type'] ?? null) !== 'summary') {
                    continue;
                }

                $countKey = str_replace('timing_sum', 'timing_count', (string) $entry['key']);

                $value = Cache::store()->get($countKey);

                if ($value !== null) {
                    $counts[(string) $entry['metric']] = (int) $value;
                }
            }
        } catch (Throwable) {
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawIndex(): array
    {
        try {
            return (array) Cache::store()->get(PrometheusMetrics::INDEX_KEY, []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Operational gauges read from the live system. Each is resolved behind
     * its own try/catch: a broken probe omits its series instead of failing
     * the scrape.
     *
     * @return array<string, float>
     */
    protected function operationalGauges(): array
    {
        $gauges = [];

        try {
            $gauges['ffarena_queue_depth'] = (float) DB::table('jobs')->count();
        } catch (Throwable) {
        }

        try {
            $gauges['ffarena_queue_failed_total'] = (float) DB::table('failed_jobs')->count();
        } catch (Throwable) {
        }

        try {
            $oldest = DB::table('jobs')->min('available_at');

            $gauges['ffarena_queue_oldest_seconds'] = $oldest === null
                ? 0.0
                : (float) max(0, time() - (int) $oldest);
        } catch (Throwable) {
        }

        // Age of the newest VERIFIED backup. The alert on this gauge is the
        // one that catches "backups silently stopped running".
        try {
            $app = app(\App\Services\BackupService::class);
            $backups = $app->list();

            if ($backups !== []) {
                $created = $backups[0]['created_at'] ?? null;

                if (is_string($created) && $created !== '') {
                    $gauges['ffarena_backup_last_success_timestamp_seconds'] = (float) strtotime($created);
                    $gauges['ffarena_backup_age_seconds'] = (float) max(0, time() - (int) strtotime($created));
                }
            }
        } catch (Throwable) {
        }

        // Scheduler heartbeat: `ffarena:ops:heartbeat` writes the
        // `operations_heartbeats` row every minute, which is exactly what the
        // readiness probe reads. A stale value means cron/scheduler is down.
        try {
            $beat = DB::table('operations_heartbeats')->where('source', 'scheduler')->value('last_beat_at');

            if ($beat !== null && (string) $beat !== '') {
                $gauges['ffarena_scheduler_last_heartbeat_timestamp_seconds'] = (float) strtotime((string) $beat);
                $gauges['ffarena_scheduler_heartbeat_age_seconds'] = (float) max(0, time() - (int) strtotime((string) $beat));
            }
        } catch (Throwable) {
        }

        // Provider/financial reconciliation conflicts: settlements whose
        // stored reconciliation status is not "balanced" (underfunded,
        // overallocated or mismatched). This is the app's own source of truth
        // for "money in does not equal money out", so the alert rule needs no
        // invented metric.
        try {
            $gauges['ffarena_reconciliation_conflicts'] = (float) DB::table('financial_settlements')
                ->where('reconciliation_status', '!=', \App\Models\FinancialSettlement::STATUS_BALANCED)
                ->whereNotNull('reconciliation_status')
                ->count();
        } catch (Throwable) {
        }

        return $gauges;
    }

    /**
     * Dotted application metric names become Prometheus-legal snake_case
     * names with the project prefix, e.g. `http.server_errors` →
     * `ffarena_http_server_errors_total`.
     */
    protected function prometheusName(string $metric): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $metric) ?? $metric;
        $name = strtolower(trim((string) $name, '_'));

        return 'ffarena_'.$name;
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function renderLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels);

        $parts = [];

        foreach ($labels as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key) ?? (string) $key;
            $escaped = str_replace(["\\", "\"", "\n"], ["\\\\", "\\\"", "\\n"], (string) $value);

            $parts[] = $key.'="'.$escaped.'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Prometheus requires a numeric literal; integers are rendered without a
     * decimal point so counters look like counters.
     */
    protected function renderValue(float $value): string
    {
        if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
    }
}
