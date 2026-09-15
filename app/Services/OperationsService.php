<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — admin operational controls + dashboard data.
 *
 * Exposes queue administration (failed-job inspection/retry/delete), cache
 * flushing and the safe metrics the admin infrastructure dashboard renders.
 * All destructive actions are admin-only at the route layer and audited here
 * through the Phase 13 AuditLogService.
 */
class OperationsService
{
    public function __construct(
        protected AuditLogService $audit,
        protected HealthService $health,
        protected BackupService $backups,
        protected CacheInvalidationService $cache,
    ) {}

    /**
     * Everything the admin infrastructure dashboard needs, in one safe shape.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'health' => $this->health->ready(),
            'queue' => $this->health->queueStats(),
            'storage' => $this->health->storageStats(),
            'webhooks' => $this->webhookStats(),
            'backup' => $this->backups->list()[0] ?? null,
            'activity' => $this->activityStats(),
            'production_issues' => $this->health->productionIssues(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookStats(): array
    {
        $dayAgo = now()->subDay();

        return [
            'endpoints' => (int) DB::table('webhook_endpoints')->count(),
            'deliveries_24h' => (int) DB::table('webhook_deliveries')->where('created_at', '>=', $dayAgo)->count(),
            'failures_24h' => (int) DB::table('webhook_deliveries')
                ->where('created_at', '>=', $dayAgo)
                ->whereIn('status', ['failed', 'disabled'])
                ->count(),
            'pending' => (int) DB::table('webhook_deliveries')->where('status', 'pending')->count(),
        ];
    }

    /**
     * Lightweight counters for the dashboard, computed from already-indexed
     * status/date columns.
     *
     * @return array<string, mixed>
     */
    public function activityStats(): array
    {
        $today = now()->startOfDay();

        return [
            'registrations_today' => (int) DB::table('teams')->where('created_at', '>=', $today)->count(),
            'payments_failed_24h' => (int) DB::table('payments')->where('created_at', '>=', now()->subDay())->where('status', 'failed')->count(),
            'disputes_open' => (int) DB::table('disputes')->whereIn('status', ['opened', 'under_review', 'assigned'])->count(),
            'notifications_today' => (int) DB::table('notifications')->where('created_at', '>=', $today)->count(),
        ];
    }

    /**
     * The uuid is the identifier the queue:retry / queue:forget commands
     * accept for the database-uuids failed-job provider.
     *
     * @return LengthAwarePaginator<int, object{uuid: string, connection: string, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function failedJobs(int $perPage = 20): LengthAwarePaginator
    {
        return DB::table('failed_jobs')
            ->select(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->paginate($perPage);
    }

    public function retryFailedJob(string $id): void
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        $this->audit->recordQuietly($this->actor(), 'ops.failed_job_retried', 'failed_job', null, [
            'metadata' => ['failed_job_id' => $id],
        ]);
    }

    public function retryAllFailed(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->audit->recordQuietly($this->actor(), 'ops.failed_jobs_retried', 'failed_job');
    }

    public function deleteFailedJob(string $id): void
    {
        Artisan::call('queue:forget', ['id' => [$id]]);

        $this->audit->recordQuietly($this->actor(), 'ops.failed_job_deleted', 'failed_job', null, [
            'metadata' => ['failed_job_id' => $id],
        ]);
    }

    public function flushCache(string $namespace): bool
    {
        $flushed = $this->cache->flush($namespace);

        if ($flushed) {
            $this->audit->recordQuietly($this->actor(), 'ops.cache_flushed', 'cache', null, [
                'metadata' => ['namespace' => $namespace],
            ]);
        }

        return $flushed;
    }

    /**
     * The acting operator (null in CLI/queue contexts where no user exists).
     */
    protected function actor(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }
}
