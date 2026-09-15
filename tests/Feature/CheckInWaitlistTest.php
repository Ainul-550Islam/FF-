<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 04 — registration eligibility, check-in, waitlist and no-show
 * handling, plus bracket-eligibility integration.
 */
class CheckInWaitlistTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (sensitive fields set explicitly, mirroring production)
    // ------------------------------------------------------------------

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Participation Tournament';
        $t->slug = $o['slug'] ?? ('part-'.Str::random(8));
        $t->game_mode = $o['game_mode'] ?? 'squad';
        $t->map = $o['map'] ?? 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = $o['team_slots'] ?? 8;
        $t->team_size = $o['team_size'] ?? 4;
        $t->rules = $o['rules'] ?? null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(
        Tournament $tournament,
        ?User $captain = null,
        string $status = 'pending',
        ?string $uid = null,
        bool $checkedIn = false,
    ): Team {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = $uid ?? 'UID'.strtoupper(Str::random(8));
        $team->status = $status;

        if ($status === Team::STATUS_WAITLISTED) {
            $team->waitlisted_at = now();
        }

        if ($checkedIn) {
            $team->checked_in_at = now();
        }

        $team->save();

        return $team;
    }

    protected function validPayload(string $name = 'CheckIn Squad', string $uid = 'UIDCHECK1'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    protected function openCheckInWindow(): array
    {
        return [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ];
    }

    protected function closedCheckInWindow(): array
    {
        return [
            'check_in_starts_at' => now()->subHours(2),
            'check_in_ends_at' => now()->subHour(),
        ];
    }

    // ------------------------------------------------------------------
    // 1. Registration eligibility + capacity
    // ------------------------------------------------------------------

    public function test_guest_cannot_register(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->post(route('teams.store', $tournament), $this->validPayload())
            ->assertRedirect(route('login'));
    }

    public function test_full_tournament_places_team_on_waitlist(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validPayload('Overflow'))
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertSame(8, Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count());
        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
        $this->assertSame(0, $tournament->fresh()->slotsLeft());

        $waitlisted = Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->first();
        $this->assertNotNull($waitlisted->waitlisted_at);
        $this->assertSame(1, $waitlisted->waitlistPosition());
    }

    public function test_waitlist_is_fifo_ordered(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        $t1 = $this->makeTeam($tournament, null, 'waitlisted');
        $t2 = $this->makeTeam($tournament, null, 'waitlisted');
        $t3 = $this->makeTeam($tournament, null, 'waitlisted');

        $t1->waitlisted_at = now()->subMinutes(30);
        $t1->save();
        $t2->waitlisted_at = now()->subMinutes(20);
        $t2->save();
        $t3->waitlisted_at = now()->subMinutes(10);
        $t3->save();

        $this->assertSame(1, $t1->fresh()->waitlistPosition());
        $this->assertSame(2, $t2->fresh()->waitlistPosition());
        $this->assertSame(3, $t3->fresh()->waitlistPosition());
    }

    public function test_withdrawn_team_releases_slot(): void
    {
        $org = $this->makeUser('organizer');
        $withdrawer = $this->makeUser('player');
        $newCaptain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $mine = $this->makeTeam($tournament, $withdrawer, 'pending');

        $this->actingAs($withdrawer)->post(route('teams.withdraw', [$tournament, $mine]))
            ->assertSessionHas('success');
        $this->assertSame('withdrawn', $mine->fresh()->status);

        $this->actingAs($newCaptain)->post(route('teams.store', $tournament), $this->validPayload('Newcomer', 'UIDNEWCOMER'))
            ->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Newcomer')->firstOrFail()]));

        $newcomer = Team::where('name', 'Newcomer')->firstOrFail();
        $this->assertSame('pending', $newcomer->status);
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
    }

    public function test_waitlisted_team_does_not_occupy_slot(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $this->makeTeam($tournament, null, 'waitlisted');

        $this->assertSame(0, $tournament->fresh()->slotsLeft());
        $this->assertSame(8, Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count());
    }

    // ------------------------------------------------------------------
    // 2. Check-in authorization + window rules
    // ------------------------------------------------------------------

    public function test_guest_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->post(route('teams.checkin', [$tournament, $team]))
            ->assertRedirect(route('login'));

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_non_captain_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $intruder = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($intruder)->post(route('teams.checkin', [$tournament, $team]))
            ->assertStatus(403);

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_captain_can_check_in_during_window(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('success');

        $team->refresh();
        $this->assertTrue($team->isCheckedIn());
        $this->assertSame($captain->id, $team->checked_in_by);
    }

    public function test_check_in_before_open_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', [
            'check_in_starts_at' => now()->addHour(),
            'check_in_ends_at' => now()->addHours(2),
        ]);
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_after_close_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))->assertSessionHas('success');
        $checkedAt = Team::find($team->id)->checked_in_at;

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))->assertSessionHas('success');

        $team->refresh();
        $this->assertSame($checkedAt->toDateTimeString(), $team->checked_in_at->toDateTimeString());
        $this->assertSame($captain->id, $team->checked_in_by);
        $this->assertSame('confirmed', $team->status);
    }

    public function test_withdrawn_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'withdrawn');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_pending_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'pending');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_waitlisted_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'waitlisted');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_cross_tournament_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', $this->openCheckInWindow() + ['name' => 'A']);
        $tournamentB = $this->makeTournament($org, 'open', $this->openCheckInWindow() + ['name' => 'B']);
        $teamA = $this->makeTeam($tournamentA, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournamentB, $teamA]))
            ->assertStatus(404);

        $this->assertFalse($teamA->fresh()->isCheckedIn());
    }

    public function test_check_in_not_configured_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open'); // no check-in window
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_admin_can_check_in_after_close(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($admin)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('success');

        $this->assertTrue($team->fresh()->isCheckedIn());
        $this->assertSame($admin->id, $team->fresh()->checked_in_by);
    }

    // ------------------------------------------------------------------
    // 3. No-show handling
    // ------------------------------------------------------------------

    public function test_mark_no_shows_marks_unchecked_confirmed_teams(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow());

        $checkedA = $this->makeTeam($tournament, null, 'confirmed', null, true);
        $checkedB = $this->makeTeam($tournament, null, 'confirmed', null, true);
        $noShowA = $this->makeTeam($tournament, null, 'confirmed');
        $noShowB = $this->makeTeam($tournament, null, 'confirmed');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('confirmed', $checkedA->fresh()->status);
        $this->assertSame('confirmed', $checkedB->fresh()->status);
        $this->assertSame('no_show', $noShowA->fresh()->status);
        $this->assertSame('no_show', $noShowB->fresh()->status);
    }

    public function test_mark_no_shows_requires_window_closed(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $team->fresh()->status);
    }

    public function test_mark_no_shows_promotes_waitlisted(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow() + ['team_slots' => 8]);

        // 8 confirmed teams: 2 checked in, 6 no-shows.
        for ($i = 0; $i < 2; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        // Two waitlisted teams.
        $w1 = $this->makeTeam($tournament, null, 'waitlisted');
        $w2 = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('success');

        $this->assertSame(6, Team::where('tournament_id', $tournament->id)->where('status', 'no_show')->count());
        $this->assertSame('pending', $w1->fresh()->status);
        $this->assertSame('pending', $w2->fresh()->status);
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
    }

    // ------------------------------------------------------------------
    // 4. Waitlist promotion
    // ------------------------------------------------------------------

    public function test_promote_next_waitlisted_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('pending', $waitlisted->fresh()->status);
        $this->assertNull($waitlisted->fresh()->waitlisted_at);
    }

    public function test_promotion_is_fifo_and_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        $t1 = $this->makeTeam($tournament, null, 'waitlisted');
        $t2 = $this->makeTeam($tournament, null, 'waitlisted');
        $t3 = $this->makeTeam($tournament, null, 'waitlisted');
        $t1->waitlisted_at = now()->subMinutes(30);
        $t1->save();
        $t2->waitlisted_at = now()->subMinutes(20);
        $t2->save();
        $t3->waitlisted_at = now()->subMinutes(10);
        $t3->save();

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('success');
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('success');

        $this->assertSame('pending', $t1->fresh()->status); // FIFO: first in, first promoted
        $this->assertSame('pending', $t2->fresh()->status);
        $this->assertSame('waitlisted', $t3->fresh()->status); // no slot left

        // A third promotion cannot promote t1/t2 again (they are no longer waitlisted).
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('error');
        $this->assertSame('waitlisted', $t3->fresh()->status);
    }

    public function test_promote_when_waitlist_empty_errors(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');
    }

    public function test_promote_when_no_slot_errors(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('waitlisted', $waitlisted->fresh()->status);
    }

    public function test_promote_requires_registration_open(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('waitlisted', $waitlisted->fresh()->status);
    }

    public function test_withdrawn_waitlisted_team_is_skipped(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $first = $this->makeTeam($tournament, $captainA, 'waitlisted');
        $second = $this->makeTeam($tournament, $captainB, 'waitlisted');

        // The first waitlisted team withdraws.
        $this->actingAs($captainA)->post(route('teams.withdraw', [$tournament, $first]))
            ->assertSessionHas('success');

        // Promotion picks the next eligible (the second) team.
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('withdrawn', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_player_cannot_promote(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($player)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertStatus(403);
    }

    public function test_player_cannot_mark_no_shows(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow());

        $this->actingAs($player)->post(route('tournaments.noshows', $tournament))
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // 5. Bracket eligibility integration
    // ------------------------------------------------------------------

    public function test_unchecked_team_excluded_from_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        $checkedIds = [];
        for ($i = 0; $i < 4; $i++) {
            $checkedIds[] = $this->makeTeam($tournament, null, 'confirmed', null, true)->id;
        }
        for ($i = 0; $i < 4; $i++) {
            $this->makeTeam($tournament, null, 'confirmed'); // not checked in
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->count());

        // Only checked-in teams may appear in the bracket.
        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($checkedIds);
        sort($participantIds);
        $this->assertSame($checkedIds, $participantIds);
    }

    public function test_all_checked_in_teams_enter_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_no_show_team_excluded_from_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }
        $noShow = $this->makeTeam($tournament, null, 'confirmed'); // never checked in

        // Mark no-shows: the unchecked-in team becomes no_show.
        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))->assertSessionHas('success');
        $this->assertSame('no_show', $noShow->fresh()->status);

        // 7 eligible teams fill an 8-team bracket (7 matches) with one bye.
        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());

        // The no-show team must never appear in the bracket.
        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertNotContains($noShow->id, $participantIds);
    }

    public function test_start_blocked_while_check_in_open(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->openCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('error');

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    // ------------------------------------------------------------------
    // 6. Mass-assignment / integrity backstops
    // ------------------------------------------------------------------

    public function test_check_in_fields_cannot_be_mass_assigned(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'confirmed', 'UIDCAPTAIN');

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UIDCAPTAIN',
            'status' => 'finished',
            'checked_in_at' => now(),
            'waitlisted_at' => now(),
        ])->assertSessionHas('success');

        $team->refresh();
        $this->assertSame('Renamed', $team->name);
        $this->assertSame('confirmed', $team->status);       // status not mass-assignable
        $this->assertNull($team->checked_in_at);             // check-in not mass-assignable
        $this->assertNull($team->waitlisted_at);             // waitlist not mass-assignable
    }
}
