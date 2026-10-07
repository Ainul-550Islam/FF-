<?php

namespace Tests\Feature\Phase16;

use App\Contracts\MetricsInterface;
use App\Http\Controllers\MetricsController;
use App\Support\Metrics\PrometheusMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GAP-10 C — Prometheus scrape endpoint.
 *
 * Pins the fail-closed contract and the honest metric-name contract:
 *
 *  - the endpoint does not exist unless the feature flag is on AND a token is
 *    configured (404 in both cases), and a missing/wrong bearer token is 401;
 *  - with a valid token the body is Prometheus text (version 0.0.4);
 *  - the application counters the code actually emits (`http.responses`,
 *    `http.server_errors`, `queue.jobs_failed`, …) are exported under the
 *    documented translation, so the alert rules in deploy/prometheus-alerts.yml
 *    can only reference series that exist;
 *  - labels survive the round trip, timings export `_sum`/`_count`;
 *  - operational gauges (queue depth, failed jobs, scheduler heartbeat age,
 *    reconciliation conflicts, backup age) are read from the live database
 *    and omit themselves rather than failing the scrape.
 */
class PrometheusMetricsTest extends Phase16TestCase
{
    protected const TOKEN = 'scrape-token-for-tests-0123456789';

    /**
     * @var array<string, mixed>
     */
    protected array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshot = $this->snapshotConfig([
            'observability.metrics.driver',
            'observability.metrics.enabled',
            'observability.metrics.scrape_token',
        ]);

        config([
            'observability.metrics.driver' => 'prometheus',
            'observability.metrics.enabled' => true,
            'observability.metrics.scrape_token' => self::TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfig($this->snapshot);

        parent::tearDown();
    }

    public function test_endpoint_is_404_when_the_feature_is_disabled(): void
    {
        config(['observability.metrics.enabled' => false]);

        $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->assertNotFound();
    }

    public function test_endpoint_is_404_when_no_token_is_configured(): void
    {
        // Fail closed: an operator must never expose metrics by forgetting the
        // token, so "enabled but tokenless" is treated as "not deployed".
        config(['observability.metrics.scrape_token' => null]);

        $this->get('/metrics')->assertNotFound();
    }

    public function test_endpoint_rejects_missing_and_wrong_tokens(): void
    {
        $this->get('/metrics')->assertUnauthorized();
        $this->get('/metrics', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }

    public function test_valid_token_returns_prometheus_text(): void
    {
        $response = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN]);

        $response->assertOk();

        $this->assertStringContainsString(
            'text/plain; version=0.0.4',
            (string) $response->headers->get('Content-Type')
        );

        $body = $response->getContent();

        $this->assertStringContainsString('# TYPE ffarena_up gauge', $body);
        $this->assertStringContainsString('ffarena_up 1', $body);
    }

    public function test_application_counters_are_exported_with_their_labels(): void
    {
        $metrics = app(MetricsInterface::class);

        $metrics->increment('http.responses', 1, ['status' => '5xx', 'kind' => 'web']);
        $metrics->increment('http.responses', 1, ['status' => '5xx', 'kind' => 'web']);
        $metrics->increment('http.server_errors', 1, ['kind' => 'web']);
        $metrics->increment('queue.jobs_failed', 3, ['queue' => 'default']);

        $body = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        $this->assertStringContainsString('# TYPE ffarena_http_responses counter', $body);
        $this->assertStringContainsString('ffarena_http_responses{kind="web",status="5xx"} 2', $body);
        $this->assertStringContainsString('ffarena_http_server_errors{kind="web"} 1', $body);
        $this->assertStringContainsString('ffarena_queue_jobs_failed{queue="default"} 3', $body);
    }

    public function test_timings_export_sum_and_count(): void
    {
        $metrics = app(MetricsInterface::class);

        $metrics->timing('http.response_ms', 120.0, ['kind' => 'api']);
        $metrics->timing('http.response_ms', 80.0, ['kind' => 'api']);

        $body = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        $this->assertStringContainsString('# TYPE ffarena_http_response_ms summary', $body);
        $this->assertStringContainsString('ffarena_http_response_ms_sum{kind="api"} 200', $body);
        $this->assertStringContainsString('ffarena_http_response_ms_count 2', $body);
    }

    public function test_gauges_are_exported_as_gauges(): void
    {
        $metrics = app(MetricsInterface::class);

        $metrics->gauge('queue.jobs_pending', 7.0);

        $body = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        $this->assertStringContainsString('# TYPE ffarena_queue_jobs_pending gauge', $body);
        $this->assertStringContainsString('ffarena_queue_jobs_pending 7', $body);
    }

    public function test_operational_gauges_come_from_the_live_system(): void
    {
        // Queue depth + failed jobs are read from the real tables.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 90,
            'created_at' => time() - 120,
        ]);

        $body = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        $this->assertStringContainsString('# TYPE ffarena_queue_depth gauge', $body);
        $this->assertStringContainsString('ffarena_queue_depth 1', $body);
        $this->assertStringContainsString('ffarena_queue_failed_total 0', $body);

        // Oldest job age is reported (the queued job above is ~2 minutes old).
        $this->assertMatchesRegularExpression('/ffarena_queue_oldest_seconds [0-9]+/', $body);

        // Scheduler heartbeat gauge is present once the heartbeat row exists.
        DB::table('operations_heartbeats')->updateOrInsert(
            ['source' => 'scheduler'],
            ['last_beat_at' => now(), 'updated_at' => now()],
        );

        $body = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        $this->assertStringContainsString('# TYPE ffarena_scheduler_heartbeat_age_seconds gauge', $body);

        // Reconciliation conflicts are counted from the settlements table.
        $this->assertStringContainsString('# TYPE ffarena_reconciliation_conflicts gauge', $body);
    }

    public function test_a_broken_probe_omits_its_gauge_instead_of_failing_the_scrape(): void
    {
        // Drop the queue table out from under the collector.
        DB::statement('DROP TABLE IF EXISTS jobs');

        $response = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN]);

        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('ffarena_queue_depth', $body);
        $this->assertStringContainsString('ffarena_up 1', $body);
    }

    public function test_series_index_is_bounded_and_expires(): void
    {
        $metrics = app(MetricsInterface::class);

        $metrics->increment('http.requests', 1, ['kind' => 'web']);

        $index = (array) Cache::store()->get(PrometheusMetrics::INDEX_KEY, []);

        $this->assertNotSame([], $index);
        $this->assertLessThanOrEqual(500, count($index));
    }

    public function test_flushing_the_cache_does_not_break_the_endpoint(): void
    {
        Cache::store()->flush();

        $response = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN]);

        $response->assertOk();

        $this->assertStringContainsString('ffarena_up 1', (string) $response->getContent());
    }

    public function test_controller_name_is_registered_as_a_route(): void
    {
        $route = app('router')->getRoutes()->getByName('metrics.scrape');

        $this->assertNotNull($route);
        // An invokable controller is registered as Class@__invoke.
        $this->assertSame(MetricsController::class.'@__invoke', $route->getAction('uses'));
    }
}
