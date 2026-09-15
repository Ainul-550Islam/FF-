<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\SupportInternalNote;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LiveEventService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 13 — support tickets: lifecycle, authorization, internal notes,
 * notifications and realtime integration.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Support Tournament';
        $t->slug = 'support-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    protected function service(): SupportTicketService
    {
        return app(SupportTicketService::class);
    }

    public function test_user_can_create_ticket_with_first_message_and_notification(): void
    {
        $user = $this->makeUser('player');

        $this->actingAs($user)->post(route('support.store'), [
            'subject' => 'Payment not reflected',
            'category' => 'payment',
            'priority' => 'high',
            'message' => 'I paid but my team is still pending.',
        ])->assertRedirect();

        $ticket = SupportTicket::firstOrFail();

        $this->assertSame($user->id, $ticket->user_id);
        $this->assertSame('payment', $ticket->category);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());

        $this->assertTrue(Notification::where('type', Notification::TYPE_SUPPORT_CREATED)
            ->where('user_id', $user->id)
            ->exists());
    }

    public function test_staff_reply_flips_status_to_waiting_on_user(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->service()->reply($staff, $ticket, 'We are on it.');

        $this->assertSame(SupportTicket::STATUS_WAITING_ON_USER, $ticket->fresh()->status);
        $this->assertTrue(Notification::where('type', Notification::TYPE_SUPPORT_REPLY)
            ->where('user_id', $user->id)
            ->exists());
    }

    public function test_user_reply_flips_status_to_waiting_on_staff(): void
    {
        $user = $this->makeUser('player');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->service()->reply($user, $ticket, 'More details.');

        $this->assertSame(SupportTicket::STATUS_WAITING_ON_STAFF, $ticket->fresh()->status);
    }

    public function test_assignment_sets_assignee_and_notifies(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->service()->assign($staff, $ticket, $staff);

        $ticket->refresh();

        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertSame(SupportTicket::STATUS_PENDING, $ticket->status);
        $this->assertTrue(Notification::where('type', Notification::TYPE_SUPPORT_ASSIGNED)
            ->where('user_id', $staff->id)
            ->exists());
    }

    public function test_assignee_must_be_staff(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $notStaff = $this->makeUser('player');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->expectException(\DomainException::class);

        $this->service()->assign($staff, $ticket, $notStaff);
    }

    public function test_status_transitions_are_validated(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        // resolved → resolved is not a valid transition.
        $this->service()->changeStatus($staff, $ticket, SupportTicket::STATUS_RESOLVED, 'Fixed.');

        $this->expectException(\DomainException::class);

        $this->service()->changeStatus($staff, $ticket, SupportTicket::STATUS_RESOLVED, 'Again.');
    }

    public function test_reopen_increments_counter(): void
    {
        $user = $this->makeUser('player');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->service()->closeByUser($user, $ticket);
        $this->service()->reopen($user, $ticket);

        $ticket->refresh();

        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);
        $this->assertSame(1, $ticket->reopened_count);
    }

    public function test_internal_notes_are_hidden_from_the_owner(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Public message',
        ]);

        $this->service()->addInternalNote($staff, $ticket, 'SECRET_NOTE_XYZ');

        $this->assertSame(1, SupportInternalNote::count());

        // The owner's ticket page must not contain the internal note.
        $this->actingAs($user)
            ->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Public message')
            ->assertDontSee('SECRET_NOTE_XYZ');

        // The messages polling endpoint never includes internal notes.
        $this->actingAs($user)
            ->getJson(route('support.tickets.messages', $ticket))
            ->assertOk()
            ->assertJsonMissing(['body' => 'SECRET_NOTE_XYZ']);
    }

    public function test_staff_can_see_internal_notes(): void
    {
        $user = $this->makeUser('player');
        $staff = $this->makeUser('moderator');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Public message',
        ]);

        $this->service()->addInternalNote($staff, $ticket, 'SECRET_NOTE_XYZ');

        $this->actingAs($staff)
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('SECRET_NOTE_XYZ');
    }

    public function test_users_cannot_view_other_users_tickets(): void
    {
        $owner = $this->makeUser('player');
        $intruder = $this->makeUser('player');
        $ticket = $this->service()->create($owner, [
            'subject' => 'Private', 'category' => 'account', 'message' => 'My account',
        ]);

        $this->actingAs($intruder)
            ->get(route('support.tickets.show', $ticket))
            ->assertForbidden();
    }

    public function test_organizer_sees_own_tournament_tickets_but_not_others(): void
    {
        $owner = $this->makeUser('player');
        $organizer = $this->makeUser('organizer');
        $otherOrganizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $ticket = $this->service()->create($owner, [
            'subject' => 'Tournament issue', 'category' => 'dispute', 'message' => 'Help',
        ]);
        $ticket->tournament_id = $tournament->id;
        $ticket->save();

        $this->actingAs($organizer)
            ->get(route('support.tickets.show', $ticket))
            ->assertOk();

        $this->actingAs($otherOrganizer)
            ->get(route('support.tickets.show', $ticket))
            ->assertForbidden();
    }

    public function test_support_activity_emits_staff_only_live_events(): void
    {
        $user = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $event = LiveEvent::where('type', LiveEvent::TYPE_SUPPORT_CREATED)->firstOrFail();

        $live = app(LiveEventService::class);

        // Staff-only: hidden from guests, visible to admins/moderators.
        $this->assertFalse($live->visibleTo(null, $event));
        $this->assertFalse($live->visibleTo($user, $event));
        $this->assertTrue($live->visibleTo($admin, $event));
    }

    public function test_staff_queue_and_export_are_staff_only(): void
    {
        $staff = $this->makeUser('moderator');
        $player = $this->makeUser('player');

        $this->get(route('admin.support.index'))->assertRedirect(route('login'));
        $this->actingAs($player)->get(route('admin.support.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.support.index'))->assertOk();

        $this->actingAs($staff)->get(route('admin.support.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($player)->get(route('admin.support.export'))->assertForbidden();
    }

    public function test_ticket_can_be_closed_and_reopened_by_owner_via_http(): void
    {
        $user = $this->makeUser('player');
        $ticket = $this->service()->create($user, [
            'subject' => 'Hello', 'category' => 'general', 'message' => 'Help me',
        ]);

        $this->actingAs($user)->post(route('support.tickets.close', $ticket))->assertRedirect();
        $this->assertTrue($ticket->fresh()->isClosed());

        $this->actingAs($user)->post(route('support.tickets.reopen', $ticket))->assertRedirect();
        $this->assertTrue($ticket->fresh()->isOpen());
    }
}
