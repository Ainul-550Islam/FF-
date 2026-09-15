<?php

namespace Tests\Feature\Phase16;

use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — admin infrastructure operations: access control, dashboard,
 * queue controls, backup trigger, cache purge and audit integration.
 */
class AdminOpsTest extends Phase16TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/ops')->assertRedirect();
    }

    public function test_player_is_forbidden(): void
    {
        $this->actingAs($this->makeUser('player'))
            ->get('/admin/ops')
            ->assertStatus(403);
    }

    public function test_organizer_is_forbidden(): void
    {
        $this->actingAs($this->makeUser('organizer'))
            ->get('/admin/ops')
            ->assertStatus(403);
    }

    public function test_moderator_is_forbidden(): void
    {
        $this->actingAs($this->makeUser('moderator'))
            ->get('/admin/ops')
            ->assertStatus(403);
    }

    public function test_admin_can_view_ops_dashboard(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get('/admin/ops')
            ->assertStatus(200)
            ->assertSee('Infrastructure Operations');
    }

    public function test_admin_health_endpoint_returns_safe_checks(): void
    {
        $response = $this->actingAs($this->makeUser('admin'))
            ->getJson('/admin/ops/health')
            ->assertStatus(200);

        $this->assertArrayHasKey('database', $response->json());
        $this->assertArrayHasKey('cache', $response->json());
    }

    public function test_admin_cache_flush_is_audited(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post('/admin/ops/cache/flush', ['namespace' => 'providers'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ops.cache_flushed',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_admin_unknown_cache_namespace_is_rejected(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/ops/cache/flush', ['namespace' => 'wallet-private'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ops.cache_flushed']);
    }

    public function test_admin_backup_trigger_is_audited_even_on_failure(): void
    {
        $admin = $this->makeUser('admin');

        if (DB::connection()->getDriverName() === 'pgsql') {
            // On PostgreSQL the default connection is a real database and would
            // back up successfully, so force the honest failure by hiding the
            // dump tooling (pg_dump). The attempt must still be auditable via
            // the healthy connection.
            $originalPath = (string) getenv('PATH');
            putenv('PATH=');

            try {
                $this->actingAs($admin)
                    ->post('/admin/ops/backup')
                    ->assertSessionHas('error');
            } finally {
                putenv('PATH='.$originalPath);
            }
        } else {
            // Default SQLite test DB is :memory: — backup fails honestly, but
            // the attempt must still be auditable.
            $this->actingAs($admin)
                ->post('/admin/ops/backup')
                ->assertSessionHas('error');
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ops.backup_created',
        ]);
    }

    public function test_admin_failed_jobs_page_renders(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get('/admin/ops/failed-jobs')
            ->assertStatus(200)
            ->assertSee('Failed Jobs');
    }
}
