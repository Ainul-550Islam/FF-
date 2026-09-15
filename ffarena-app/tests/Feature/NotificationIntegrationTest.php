<?php

namespace Tests\Feature;

use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\DisputeService;
use App\Services\IdentityVerificationService;
use App\Services\PaymentService;
use App\Services\RestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 11 — notifications emitted by real business events, without ever
 * mutating the underlying Phase 01–10 state.
 */
class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

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
        $t->name = $o['name'] ?? 'Test Tournament';
        $t->slug = $o['slug'] ?? ('t-' . Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->dispute_window_hours = $o['dispute_window_hours'] ?? 24;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'ready'): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    // ------------------------------------------------------------------
    // Payment events
    // ------------------------------------------------------------------

    public function test_payment_verified_notifies_payer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $payer = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($organizer, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $payer, Team::STATUS_PENDING);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $payer, 'bkash', 'TRX123');

        app(PaymentService::class)->verifyManually($payment, $admin);

        $this->assertTrue(Notification::where('user_id', $payer->id)
            ->where('type', Notification::TYPE_PAYMENT_VERIFIED)->exists());
    }

    public function test_payment_failed_notifies_payer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $payer = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($organizer, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $payer, Team::STATUS_PENDING);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $payer, 'bkash', 'TRX123');
        app(PaymentService::class)->markFailed($payment, $admin, 'wrong trx');

        $this->assertTrue(Notification::where('user_id', $payer->id)
            ->where('type', Notification::TYPE_PAYMENT_FAILED)->exists());
    }

    // ------------------------------------------------------------------
    // Dispute events
    // ------------------------------------------------------------------

    public function test_dispute_opened_notifies_opponent_organizer_and_staff(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live');
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament, $captainB);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $match->winner_team_id = $teamB->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        app(DisputeService::class)->open($match, $teamA, $captainA, 'wrong_winner', 'We won.');

        $this->assertTrue(Notification::where('user_id', $captainB->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'opponent notified');
        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'organizer notified');
        $this->assertTrue(Notification::where('user_id', $moderator->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'staff notified');
        $this->assertFalse(Notification::where('user_id', $captainA->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'opener not self-notified');
    }

    public function test_dispute_resolution_notifies_participants(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live');
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament, $captainB);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $match->winner_team_id = $teamB->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        $dispute = app(DisputeService::class)->open($match, $teamA, $captainA, 'wrong_winner', 'We won.');
        app(DisputeService::class)->resolve($dispute, $moderator, $teamB, 'result upheld');

        $this->assertTrue(Notification::where('user_id', $captainA->id)
            ->where('type', Notification::TYPE_DISPUTE_RESOLVED)->exists());
        $this->assertTrue(Notification::where('user_id', $captainB->id)
            ->where('type', Notification::TYPE_DISPUTE_RESOLVED)->exists());
    }

    // ------------------------------------------------------------------
    // Moderation / identity / anti-cheat events
    // ------------------------------------------------------------------

    public function test_restriction_notifies_user_on_apply_and_lift(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');
        $service = app(RestrictionService::class);

        $restriction = $service->restrict($user, Restriction::TYPE_DISPUTE_BLOCKED, 'dispute abuse', 'manual', $admin);

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_APPLIED)->exists());

        $service->lift($restriction, $admin);

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_LIFTED)->exists());
    }

    public function test_identity_verified_notifies_user(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        app(IdentityVerificationService::class)->verifyManually($user, $admin, 'docs ok');

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_IDENTITY_VERIFIED)->exists());
    }

    public function test_identity_rejected_notifies_user(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        app(IdentityVerificationService::class)->reject($user, $admin, 'unreadable');

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_IDENTITY_REJECTED)->exists());
    }

    public function test_anti_cheat_resolution_notifies_accused_and_reporter(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $accused = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $service = app(AntiCheatService::class);

        $incident = $service->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $moderator,
            AntiCheatIncident::SOURCE_STAFF,
            AntiCheatService::CATEGORY_AIMBOT,
            'high',
            'suspicious tracking',
        );

        $service->review($incident, $moderator);
        $service->resolve($incident, $moderator, AntiCheatIncident::STATUS_CLEARED, 'no evidence found');

        $this->assertTrue(Notification::where('user_id', $accused->id)
            ->where('type', Notification::TYPE_ANTI_CHEAT_RESOLVED)->exists());
        $this->assertTrue(Notification::where('user_id', $moderator->id)
            ->where('type', Notification::TYPE_ANTI_CHEAT_RESOLVED)->exists());
    }

    // ------------------------------------------------------------------
    // Team registration events
    // ------------------------------------------------------------------

    public function test_team_registration_notifies_captain_and_organizer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Notification Team',
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDNOTIF1',
        ])->assertRedirect();

        $this->assertTrue(Notification::where('user_id', $captain->id)
            ->where('type', Notification::TYPE_TEAM_REGISTERED)->exists());
        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_TEAM_REGISTERED)->exists());
    }

    public function test_team_withdrawal_notifies_organizer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain, Team::STATUS_PENDING);

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))->assertRedirect();

        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_TEAM_WITHDRAWN)->exists());
    }

    // ------------------------------------------------------------------
    // Phase 10 state is never mutated by notification delivery
    // ------------------------------------------------------------------

    public function test_restriction_notification_does_not_change_restriction_state(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');
        $service = app(RestrictionService::class);

        $restriction = $service->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->assertSame(Restriction::STATUS_ACTIVE, $restriction->fresh()->status);
        $this->assertSame(RiskEvent::SEVERITY_CRITICAL, RiskEvent::where('user_id', $user->id)
            ->where('type', RiskEvent::TYPE_ACCOUNT_RESTRICTED)->first()->severity);

        // The notification carries no raw sensitive data.
        $notification = Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_APPLIED)->first();

        $this->assertArrayHasKey('restriction_id', $notification->data);
        $this->assertArrayNotHasKey('password', $notification->data);
        $this->assertArrayNotHasKey('token', $notification->data);
    }
}
