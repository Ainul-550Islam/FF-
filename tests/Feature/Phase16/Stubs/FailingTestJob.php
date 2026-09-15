<?php

namespace Tests\Feature\Phase16\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 16 — a deliberately failing job used to exercise the failed-job
 * persistence, listing and retry paths (database queue).
 */
class FailingTestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function handle(): void
    {
        throw new \RuntimeException('FailingTestJob: intentional failure');
    }
}
