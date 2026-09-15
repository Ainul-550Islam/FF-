<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 13 — central audit log: append-only semantics, redaction,
 * correlation, search and access control.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function service(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    public function test_record_captures_actor_entity_and_metadata(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('player');

        $log = $this->service()->record($admin, 'role.change', 'user', $target->id, [
            'target_user' => $target,
            'before' => ['role' => 'player'],
            'after' => ['role' => 'moderator'],
        ]);

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame('user', $log->entity_type);
        $this->assertSame($target->id, $log->entity_id);
        $this->assertSame($target->id, $log->target_user_id);
        $this->assertSame(['role' => 'player'], $log->before);
        $this->assertSame(['role' => 'moderator'], $log->after);
        $this->assertNotNull($log->created_at);
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->record($this->makeUser('admin'), 'made.up.action');
    }

    public function test_record_quietly_never_throws(): void
    {
        $result = $this->service()->recordQuietly($this->makeUser('admin'), 'made.up.action');

        $this->assertNull($result);
    }

    public function test_audit_rows_are_append_only(): void
    {
        $admin = $this->makeUser('admin');
        $log = $this->service()->record($admin, 'role.change', 'user', 1);

        // In-place update is refused (the `updating` event returns false).
        $log->action = 'tampered';
        $this->assertFalse($log->save());

        // Deletion is refused (the `deleting` event returns false).
        $this->assertFalse($log->delete());

        $fresh = AuditLog::findOrFail($log->id);
        $this->assertSame('role.change', $fresh->action);
    }

    public function test_mass_assignment_is_guarded(): void
    {
        $log = new AuditLog();

        try {
            $log->fill([
                'actor_user_id' => 1,
                'action' => 'role.change',
                'before' => ['role' => 'admin'],
            ]);
            $this->fail('AuditLog accepted mass assignment.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_sensitive_keys_are_redacted(): void
    {
        $admin = $this->makeUser('admin');

        $log = $this->service()->record($admin, 'wallet.credited', 'wallet', 1, [
            'metadata' => [
                'email' => 'user@example.com',
                'phone' => '01700000000',
                'password' => 'secret',
                'game_uid' => 'UID123',
                'amount_minor' => 500,
            ],
        ]);

        $this->assertSame('[redacted]', $log->metadata['email']);
        $this->assertSame('[redacted]', $log->metadata['phone']);
        $this->assertSame('[redacted]', $log->metadata['password']);
        $this->assertSame('[redacted]', $log->metadata['game_uid']);
        $this->assertSame(500, $log->metadata['amount_minor']);
    }

    public function test_request_correlation_is_shared_within_a_request(): void
    {
        $admin = $this->makeUser('admin');

        request()->attributes->set('audit_request_id', 'corr-abc-123');

        $first = $this->service()->record($admin, 'role.change', 'user', 1);
        $second = $this->service()->record($admin, 'wallet.credited', 'wallet', 2);

        $this->assertSame('corr-abc-123', $first->request_id);
        $this->assertSame('corr-abc-123', $second->request_id);
    }

    public function test_search_filters_by_action_and_entity(): void
    {
        $admin = $this->makeUser('admin');

        $this->service()->record($admin, 'role.change', 'user', 1);
        $this->service()->record($admin, 'wallet.credited', 'wallet', 1);
        $this->service()->record($admin, 'wallet.debited', 'wallet', 1);

        $byAction = $this->service()->search(['action' => 'wallet.credited']);
        $this->assertSame(1, $byAction->total());
        $this->assertSame('wallet.credited', $byAction->first()->action);

        $byEntity = $this->service()->search(['entity_type' => 'wallet']);
        $this->assertSame(2, $byEntity->total());
    }

    public function test_search_is_paginated_newest_first(): void
    {
        $admin = $this->makeUser('admin');

        for ($i = 0; $i < 35; $i++) {
            $this->service()->record($admin, 'role.change', 'user', $i);
        }

        $page = $this->service()->search([], 30);

        $this->assertSame(35, $page->total());
        $this->assertCount(30, $page->items());
    }

    public function test_related_history_scopes_to_one_entity(): void
    {
        $admin = $this->makeUser('admin');

        $this->service()->record($admin, 'role.change', 'user', 1);
        $this->service()->record($admin, 'wallet.credited', 'wallet', 1);
        $this->service()->record($admin, 'role.change', 'user', 2);

        $history = $this->service()->relatedHistory('user', 1);

        $this->assertCount(1, $history);
        $this->assertSame('user', $history->first()->entity_type);
        $this->assertSame(1, $history->first()->entity_id);
    }

    public function test_guest_and_player_cannot_access_audit_log(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));

        $player = $this->makeUser('player');
        $this->actingAs($player)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_admin_can_view_audit_log(): void
    {
        $admin = $this->makeUser('admin');
        $this->service()->record($admin, 'role.change', 'user', 1);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('role.change');
    }

    public function test_audit_export_is_admin_only_csv(): void
    {
        $admin = $this->makeUser('admin');
        $this->service()->record($admin, 'role.change', 'user', 1);

        $this->actingAs($admin)
            ->get(route('admin.audit.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $player = $this->makeUser('player');
        $this->actingAs($player)->get(route('admin.audit.export'))->assertForbidden();
    }
}
