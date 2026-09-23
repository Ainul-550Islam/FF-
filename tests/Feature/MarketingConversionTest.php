<?php

namespace Tests\Feature;

use App\Models\MarketingEvent;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 20 — payment ↔ funnel bridging.
 *
 * Every payment outcome is mirrored into marketing_events (quietly, never
 * inside the payment transaction's success path) with honest amounts, and
 * an illegal transition still throws before any marketing write.
 */
class MarketingConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_payment_records_payment_success(): void
    {
        $payment = $this->makePendingPayment(100);
        $admin = $this->makeUser('admin');

        app(PaymentService::class)->verifyManually($payment, $admin);

        $event = MarketingEvent::query()->where('name', 'payment_success')->first();
        $this->assertNotNull($event, 'A verified payment is a revenue moment.');
        $this->assertSame($payment->id, (int) $event->properties['payment_id']);
        $this->assertSame($payment->amountMinor(), (int) $event->properties['amount_minor']);
        $this->assertSame('BDT', $event->properties['currency']);
    }

    public function test_mark_failed_records_payment_failed(): void
    {
        $payment = $this->makePendingPayment(50);
        $admin = $this->makeUser('admin');

        app(PaymentService::class)->markFailed($payment, $admin, 'fake trx id');

        $event = MarketingEvent::query()->where('name', 'payment_failed')->first();
        $this->assertNotNull($event);
        $this->assertSame($payment->id, (int) $event->properties['payment_id']);
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_mark_gateway_failed_records_payment_failed(): void
    {
        $payment = $this->makePendingPayment(50);

        app(PaymentService::class)->markGatewayFailed($payment, 'TRX-GW', 'provider declined');

        $this->assertSame(1, MarketingEvent::query()->where('name', 'payment_failed')->count());
    }

    public function test_a_failed_callback_records_payment_failed(): void
    {
        $payment = $this->makePendingPayment(100);

        [$payload, $rawBody, $signature] = $this->signedCallback($payment, Payment::STATUS_FAILED);

        app(PaymentService::class)->handleProviderCallback('bkash', $payload, $signature, $rawBody);

        $this->assertSame(1, MarketingEvent::query()->where('name', 'payment_failed')->count());
    }

    public function test_a_successful_callback_records_payment_success(): void
    {
        $payment = $this->makePendingPayment(100);

        [$payload, $rawBody, $signature] = $this->signedCallback($payment, Payment::STATUS_PAID);

        app(PaymentService::class)->handleProviderCallback('bkash', $payload, $signature, $rawBody);

        $this->assertSame(1, MarketingEvent::query()->where('name', 'payment_success')->count());
        $this->assertSame($payment->amountMinor(), (int) MarketingEvent::query()->where('name', 'payment_success')->first()->properties['amount_minor']);
    }

    public function test_an_illegal_transition_throws_before_any_marketing_write(): void
    {
        $payment = $this->makePendingPayment(100);
        $admin = $this->makeUser('admin');

        app(PaymentService::class)->markFailed($payment, $admin, 'first');

        $this->expectException(DomainException::class);

        app(PaymentService::class)->markFailed($payment->fresh(), $admin, 'second');
    }

    public function test_the_payer_is_resolved_from_the_team_captain(): void
    {
        $payment = $this->makePendingPayment(100);
        $captain = $payment->team->captain;
        $admin = $this->makeUser('admin');

        app(PaymentService::class)->markFailed($payment, $admin, 'test');

        $event = MarketingEvent::query()->where('name', 'payment_failed')->first();
        $this->assertNotNull($event);
        $this->assertSame($captain->id, $event->user_id, 'The event is attributed to the payer.');
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makePendingPayment(int $entryFee): Payment
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, $entryFee);
        $team = $this->makeTeam($tournament, $captain);

        return app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX'.Str::upper(Str::random(6)));
    }

    protected function makeTournament(User $organizer, int $entryFee): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Conversion Tournament';
        $t->slug = 'conv-'.Str::random(8);
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

    protected function makeTeam(Tournament $tournament, ?User $captain = null): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'pending';
        $team->save();

        return $team;
    }

    protected function signedCallback(Payment $payment, string $status): array
    {
        $payload = [
            'payment_id' => $payment->id,
            'provider_reference' => (string) $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => $status,
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, (string) config('services.payments.webhook_secret'));

        return [$payload, $rawBody, $signature];
    }
}
