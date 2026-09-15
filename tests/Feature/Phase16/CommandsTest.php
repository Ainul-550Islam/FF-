<?php

namespace Tests\Feature\Phase16;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — operational CLI commands (health, queue health, heartbeat,
 * scheduled cleanup) and their idempotence.
 */
class CommandsTest extends Phase16TestCase
{
    public function test_health_command_runs(): void
    {
        $this->artisan('ffarena:health')->assertExitCode(0);
    }

    public function test_queue_health_command_runs(): void
    {
        $this->artisan('ffarena:queue:health')->assertExitCode(0);
    }

    public function test_heartbeat_records_scheduler_row(): void
    {
        $this->artisan('ffarena:ops:heartbeat')->assertExitCode(0);

        $this->assertDatabaseHas('operations_heartbeats', ['source' => 'scheduler']);
    }

    public function test_heartbeat_is_idempotent(): void
    {
        $this->artisan('ffarena:ops:heartbeat')->assertExitCode(0);
        $this->artisan('ffarena:ops:heartbeat')->assertExitCode(0);

        $this->assertDatabaseCount('operations_heartbeats', 1);
    }

    public function test_cleanup_otp_prunes_only_expired(): void
    {
        $expired = new OtpChallenge;
        $expired->phone = '+8801700000001';
        $expired->purpose = 'login';
        $expired->code_hash = 'h1';
        $expired->expires_at = now()->subDay();
        $expired->created_at = now()->subDays(2);
        $expired->save();

        $fresh = new OtpChallenge;
        $fresh->phone = '+8801700000002';
        $fresh->purpose = 'login';
        $fresh->code_hash = 'h2';
        $fresh->expires_at = now()->addMinutes(5);
        $fresh->created_at = now();
        $fresh->save();

        $this->artisan('ffarena:cleanup:otp')->assertExitCode(0);

        $this->assertDatabaseCount('otp_challenges', 1);
    }

    public function test_cleanup_notifications_preserves_unread(): void
    {
        $read = new Notification;
        $read->user_id = $this->makeUser()->id;
        $read->type = 'test';
        $read->title = 'read one';
        $read->body = 'x';
        $read->read_at = now()->subDays(400);
        $read->created_at = now()->subDays(400);
        $read->save();

        $unread = new Notification;
        $unread->user_id = $this->makeUser()->id;
        $unread->type = 'test';
        $unread->title = 'unread one';
        $unread->body = 'x';
        $unread->read_at = null;
        $unread->created_at = now()->subDays(400);
        $unread->save();

        $this->artisan('ffarena:cleanup:notifications')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['title' => 'unread one']);
    }

    public function test_cleanup_live_events_prunes_old(): void
    {
        $user = $this->makeUser();

        $old = new LiveEvent;
        $old->type = 'team.registered';
        $old->actor_user_id = $user->id;
        $old->payload = [];
        $old->created_at = now()->subDays(100);
        $old->save();

        $new = new LiveEvent;
        $new->type = 'team.registered';
        $new->actor_user_id = $user->id;
        $new->payload = [];
        $new->created_at = now();
        $new->save();

        $this->artisan('ffarena:cleanup:live-events')->assertExitCode(0);

        $this->assertDatabaseCount('live_events', 1);
    }

    public function test_cleanup_idempotency_and_failed_jobs_are_safe(): void
    {
        $this->artisan('ffarena:cleanup:idempotency')->assertExitCode(0);
        $this->artisan('ffarena:cleanup:failed-jobs')->assertExitCode(0);
        $this->artisan('ffarena:cleanup:webhooks')->assertExitCode(0);
        $this->artisan('ffarena:cleanup:webhook-events')->assertExitCode(0);

        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertSame(0, DB::table('webhook_events')->count());
    }
}
