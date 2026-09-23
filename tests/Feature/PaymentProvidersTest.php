<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 — payment provider adapters and checkout: honest provider
 * statuses, provider validation, and integration with the Phase 08 state
 * machine. No real bKash/Nagad/card requests are ever made.
 */
class PaymentProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, float $entryFee = 100): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Provider Tournament';
        $t->slug = 'prov-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $entryFee;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.Str::random(6);
        $team->status = Team::STATUS_PENDING;
        $team->save();

        return $team;
    }

    public function test_provider_statuses_are_honest(): void
    {
        $statuses = app(PaymentGatewayManager::class)->statuses();

        $byId = collect($statuses)->keyBy('id');

        $this->assertArrayHasKey('bkash', $byId->all());
        $this->assertArrayHasKey('nagad', $byId->all());
        $this->assertArrayHasKey('rocket', $byId->all());
        $this->assertArrayHasKey('card', $byId->all());
        $this->assertArrayHasKey('bank', $byId->all());
        $this->assertArrayHasKey('sslcommerz', $byId->all());

        // With no credentials in the environment, the hosted providers report
        // themselves as NOT configured — never as live.
        $this->assertFalse($byId['card']['configured']);
        $this->assertFalse($byId['sslcommerz']['configured']);
    }

    public function test_checkout_methods_page_lists_enabled_providers(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->get(route('payment.methods', [$tournament, $team]))
            ->assertOk()
            ->assertSee('bKash')
            ->assertSee('Nagad');
    }

    public function test_checkout_initiate_creates_payment_with_chosen_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'nagad',
            'trx_id' => 'NAGAD123',
        ])->assertRedirect(route('payment.pending', [$tournament, $team, Payment::where('team_id', $team->id)->first()]));

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('nagad', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_checkout_initiate_rejects_unknown_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'paypal',
        ])->assertSessionHasErrors('provider');
    }

    public function test_checkout_initiate_with_free_entry_confirms_immediately(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org, entryFee: 0);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
        ])->assertRedirect(route('tournaments.show', $tournament));

        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_create_for_team_accepts_explicit_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament,
            $team,
            $captain,
            'rocket',
            'ROCKET123',
            'rocket',
            'ROCKET123',
        );

        $this->assertSame('rocket', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_CREATED,
        ]);
    }

    public function test_create_for_team_rejects_unknown_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->expectException(DomainException::class);
        app(PaymentService::class)->createForTeam(
            $tournament,
            $team,
            $captain,
            'paypal',
            'X',
            'paypal',
        );
    }

    public function test_unconfigured_hosted_provider_does_not_fake_success(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // The card gateway has no credentials configured; the flow must fall
        // back to the manual pending screen with an honest error, and the
        // payment must remain pending (never auto-verified).
        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'card',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(Team::STATUS_PENDING, $team->fresh()->status);
    }
}
