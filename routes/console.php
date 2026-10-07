<?php

use App\Console\Commands\BackupCreateCommand;
use App\Console\Commands\BackupVerifyCommand;
use App\Console\Commands\CleanupFailedJobsCommand;
use App\Console\Commands\CleanupIdempotencyCommand;
use App\Console\Commands\CleanupLiveEventsCommand;
use App\Console\Commands\CleanupNotificationsCommand;
use App\Console\Commands\CleanupOtpCommand;
use App\Console\Commands\CleanupWebhookDeliveriesCommand;
use App\Console\Commands\CleanupWebhookEventsCommand;
use App\Console\Commands\OpsHeartbeatCommand;
use App\Console\Commands\ReconcileGameSessions;
use App\Console\Commands\ReconcilePendingSettlements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Phase 16 — production scheduler
|--------------------------------------------------------------------------
|
| Production runs `php artisan schedule:run` every minute (see
| docs/PRODUCTION_RUNBOOK.md). Every entry is idempotent and safe to run
| repeatedly; cleanup jobs only touch operational/temporary rows, never
| immutable business or audit records.
|
*/

// Scheduler heartbeat for readiness/alerting (every minute).
Schedule::command(OpsHeartbeatCommand::class)->everyMinute()->withoutOverlapping();

// GAP-10 A8 — settlement and Gameberry session reconciliation. Both commands
// dispatch queued work (never money movement) and are bounded per run, so a
// backlog drains gradually instead of flooding the queue. onOneServer keeps a
// multi-node scheduler from working the same backlog twice, and
// withoutOverlapping keeps a slow run from stacking onto the next tick.
Schedule::command(ReconcilePendingSettlements::class)->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(ReconcileGameSessions::class)->everyThirtyMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Backup (daily, off-peak) + weekly verification.
Schedule::command(BackupCreateCommand::class)->dailyAt('03:00')->withoutOverlapping();
Schedule::command(BackupVerifyCommand::class, ['--all'])->weeklyOn(1, '04:00')->withoutOverlapping();

// Operational cleanup (hourly/daily windows).
Schedule::command(CleanupOtpCommand::class)->hourly();
Schedule::command(CleanupIdempotencyCommand::class)->hourly();
Schedule::command(CleanupWebhookDeliveriesCommand::class)->dailyAt('03:10');
Schedule::command(CleanupWebhookEventsCommand::class)->dailyAt('03:20');
Schedule::command(CleanupNotificationsCommand::class)->dailyAt('03:30');
Schedule::command(CleanupFailedJobsCommand::class)->dailyAt('03:40');
Schedule::command(CleanupLiveEventsCommand::class)->dailyAt('03:50');
