<?php

namespace Tests\Feature\Phase16;

use App\Contracts\MetricsInterface;
use App\Services\OperationsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Phase16\Stubs\FailingTestJob;
use Tests\Feature\Phase16\Stubs\RecordingMetrics;

/**
 * Phase 16 — queue dispatch, failed jobs, retry and observability.
 */
class QueueTest extends Phase16TestCase
{
    public function test_job_processing_and_failure_are_recorded(): void
    {
        $metrics = new RecordingMetrics;
        $this->app->instance(MetricsInterface::class, $metrics);

        config(['queue.default' => 'database']);

        Queue::push(new FailingTestJob);

        // Process one job; it fails and lands in the failed table.
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertGreaterThanOrEqual(1, $metrics->count('queue.jobs_failed'));
    }

    public function test_failed_jobs_are_listable_retryable_and_deletable(): void
    {
        config(['queue.default' => 'database']);

        Queue::push(new FailingTestJob);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $ops = app(OperationsService::class);

        $listing = $ops->failedJobs();
        $this->assertSame(1, $listing->total());

        $uuid = (string) $listing->items()[0]->uuid;

        // Retry re-queues the job onto the jobs table.
        $ops->retryFailedJob($uuid);
        $this->assertDatabaseCount('jobs', 1);

        // Delete removes the failed record.
        $ops->deleteFailedJob($uuid);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_retry_all_failed_jobs(): void
    {
        config(['queue.default' => 'database']);

        Queue::push(new FailingTestJob);
        Queue::push(new FailingTestJob);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertDatabaseCount('failed_jobs', 2);

        app(OperationsService::class)->retryAllFailed();

        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_failed_job_records_expose_a_closed_column_set(): void
    {
        config(['queue.default' => 'database']);
        Queue::push(new FailingTestJob);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $listing = app(OperationsService::class)->failedJobs();
        $row = (array) $listing->items()[0];

        foreach (array_keys($row) as $key) {
            $this->assertContains($key, ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
        }
    }
}
