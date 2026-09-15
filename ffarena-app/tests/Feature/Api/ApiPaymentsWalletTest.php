<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;

/**
 * Phase 15 — payments & wallet: server-derived amounts, provider selection
 * only, duplicate protection, wallet/ledger read-only, payouts own-only.
 */
class ApiPaymentsWalletTest extends ApiTestCase
{
    public function test_payment_methods_list_is_honest(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/payments/methods');

        $res->assertStatus(200);
        $this->assertIsArray($res->json('data.providers'));
        $this->assertIsArray($res->json('data.saved_methods'));
    }

    public function test_payment_amount_is_server_derived(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $res = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);

        $res->assertStatus(201);
        // 500 BDT = 50000 poisha, derived server-side; the client never
        // supplied an amount.
        $this->assertSame(50000, $res->json('data.payment.amount_minor'));
        $this->assertSame('BDT', $res->json('data.payment.currency'));
    }

    public function test_payment_requires_team_owner(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $intruder = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $captain);

        $this->asUser($intruder, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(403);
    }

    public function test_duplicate_payment_returns_existing(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $first = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);
        $first->assertStatus(201);

        $second = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);

        $second->assertStatus(200)->assertJsonPath('meta.existing', true);

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_idempotency_key_prevents_duplicate_payment(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $payload = ['team_id' => $team->id, 'provider' => 'bkash'];
        $headers = ['Idempotency-Key' => 'pay-1'];

        $first = $this->asUser($player, ['payments:create'])
            ->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($player, ['payments:create'])
            ->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_no_wallet_credit_or_debit_endpoints(): void
    {
        $user = $this->user();

        // No such mutation endpoints exist — the wallet changes only through
        // the Phase 08/09 services.
        $this->asUser($user, ['wallet:read'])->postJson('/api/v1/me/wallet/credit', ['amount_minor' => 100])
            ->assertStatus(404);

        $this->asUser($user, ['wallet:read'])->postJson('/api/v1/me/wallet/debit', ['amount_minor' => 100])
            ->assertStatus(404);
    }

    public function test_wallet_and_ledger_are_readable(): void
    {
        $user = $this->user();

        $wallet = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet');
        $wallet->assertStatus(200);
        $this->assertSame(0, $wallet->json('data.balance_minor'));

        $ledger = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet/ledger');
        $ledger->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_payouts_are_own_only(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $recipient = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($tournament, $recipient, 'confirmed');

        $dist = new PrizeDistribution();
        $dist->tournament_id = $tournament->id;
        $dist->status = PrizeDistribution::STATUS_COMPLETED;
        $dist->pool_minor = 100000;
        $dist->total_allocated_minor = 100000;
        $dist->save();

        $payout = new Payout();
        $payout->distribution_id = $dist->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->recipient_team_id = $team->id;
        $payout->rank = 1;
        $payout->amount_minor = 100000;
        $payout->currency = 'BDT';
        $payout->status = 'completed';
        $payout->payout_method = 'wallet';
        $payout->provider = 'wallet';
        $payout->save();

        $mine = $this->asUser($recipient, ['payouts:read'])->getJson('/api/v1/me/payouts');
        $mine->assertStatus(200);
        $this->assertSame([$payout->id], array_column($mine->json('data'), 'id'));

        $theirs = $this->asUser($other, ['payouts:read'])->getJson('/api/v1/me/payouts');
        $theirs->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_payout_lookup_by_other_user_route_does_not_exist(): void
    {
        $this->asUser($this->user(), ['payouts:read'])
            ->getJson('/api/v1/users/999/payouts')
            ->assertStatus(404);
    }
}
