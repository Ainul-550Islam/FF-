<?php

namespace App\Console\Commands;

use App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * Phase 16 — safe, redacted configuration/health diagnostics for operators.
 *
 *   php artisan ffarena:health
 *   php artisan ffarena:health --production
 *
 * Never prints secrets: values are reduced to ok/fail plus short, class-only
 * reasons. Exits non-zero when a critical readiness check fails.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'ffarena:health {--production : validate a production-like configuration too}';

    protected $description = 'Run health checks and print safe, redacted diagnostics';

    public function handle(HealthService $health): int
    {
        $ready = $health->ready();
        $diagnostics = $health->diagnostics();

        $this->line('FF Arena health check');
        $this->line('Environment: '.(string) config('app.env'));
        $this->line('Maintenance mode: '.($diagnostics['maintenance'] ? 'yes' : 'no'));
        $this->line('Readiness: '.strtoupper($ready['status']));
        $this->newLine();

        foreach ($ready['checks'] as $check) {
            $line = '  ['.($check['ok'] ? '✓' : '✗').'] '.$check['label'];

            if (! $check['ok'] && isset($check['error'])) {
                $line .= ' — '.$check['error'];
            }

            $check['ok'] ? $this->info($line) : $this->error($line);
        }

        $this->newLine();
        $queue = $diagnostics['queue'];

        $this->line('Queue: '.($queue['reachable'] ? 'reachable' : 'UNREACHABLE'));
        $this->line('  pending jobs: '.$queue['pending_jobs']);
        $this->line('  failed jobs: '.$queue['failed_jobs']);
        $this->line('  oldest pending age: '.($queue['oldest_pending_seconds'] === null ? 'n/a' : $queue['oldest_pending_seconds'].'s'));
        $this->line('  scheduler heartbeat age: '.($queue['scheduler_heartbeat_seconds_ago'] === null ? 'n/a' : $queue['scheduler_heartbeat_seconds_ago'].'s'));

        $this->newLine();
        $storage = $health->storageStats();
        $this->line('Private storage: '.($storage['exists'] ? ($storage['files'].' files, '.number_format($storage['size_bytes']).' bytes') : 'missing'));

        if ($this->option('production')) {
            $this->newLine();
            $this->line('Production configuration validation:');

            $issues = $health->productionIssues();

            if ($issues === []) {
                $this->info('  No issues detected.');
            }

            foreach ($issues as $issue) {
                $line = '  ['.strtoupper($issue['severity']).'] '.$issue['key'].' — '.$issue['message'];
                $issue['severity'] === 'critical' ? $this->error($line) : $this->warn($line);
            }
        }

        $this->newLine();
        $this->line('Correlation id: '.(string) ($diagnostics['request_id'] ?? 'n/a'));

        return $ready['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
