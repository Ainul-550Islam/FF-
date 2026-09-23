<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Services\PaymentService;

/**
 * Phase 15 — HTTP smoke matrix across actors (guest / protected / player /
 * organizer / moderator / admin) plus inbound webhook valid/invalid/replay.
 *
 * Prints a readable result table for the phase report and asserts every cell.
 */
class ApiSmokeMatrixTest extends ApiTestCase
{
    public function test_full_smoke_matrix(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $mod = $this->user(['role' => 'moderator']);
        $admin = $this->admin();
        $player = $this->user();

        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $playerTeam = $this->makeTeam($tournament, $player);
        $playerReg = $this->user();

        $rows = [];

        // --- Guest -----------------------------------------------------------------
        $rows[] = ['guest', 'GET /tournaments', $this->getJson('/api/v1/tournaments')->getStatusCode()];
        $rows[] = ['guest', 'GET /me', $this->getJson('/api/v1/me')->getStatusCode()];
        $rows[] = ['guest', 'POST /registrations', $this->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $this->registrationPayload())->getStatusCode()];
        $rows[] = ['guest', 'GET /admin/webhooks', $this->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Protected (player token, all scopes) ----------------------------------
        $playerToken = $this->tokenFor($player, ['*']);
        $this->authForget();
        $rows[] = ['player', 'GET /me', $this->withToken($playerToken)->getJson('/api/v1/me')->getStatusCode()];
        $rows[] = ['player', 'GET /me/wallet', $this->withToken($playerToken)->getJson('/api/v1/me/wallet')->getStatusCode()];

        $regToken = $this->tokenFor($playerReg, ['*']);
        $this->authForget();
        $rows[] = ['player', 'POST /registrations', $this->withToken($regToken)
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $this->registrationPayload('Smoke', 'UIDSMOKE1'))->getStatusCode()];

        // --- Organizer -------------------------------------------------------------
        $orgToken = $this->tokenFor($org, ['*']);
        $this->authForget();
        $rows[] = ['organizer', 'GET /teams/{team}', $this->withToken($orgToken)->getJson('/api/v1/teams/'.$playerTeam->id)->getStatusCode()];
        $rows[] = ['organizer', 'GET /admin/webhooks', $this->withToken($orgToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Moderator -------------------------------------------------------------
        $modToken = $this->tokenFor($mod, ['*']);
        $this->authForget();
        $rows[] = ['moderator', 'GET /admin/webhooks', $this->withToken($modToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Admin -----------------------------------------------------------------
        $adminToken = $this->tokenFor($admin, ['admin']);
        $this->authForget();
        $rows[] = ['admin', 'GET /admin/webhooks', $this->withToken($adminToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Webhooks (valid / invalid / replay) -----------------------------------
        $payment = app(PaymentService::class)->createForTeam(
            $tournament, $playerTeam, $player, 'bkash', 'TRXSMOKE', 'bkash', 'TRXSMOKE'
        );

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-smoke-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRXSMOKE',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];
        $rawBody = json_encode($payload);
        $secret = (string) config('services.payments.webhook_secret');

        $valid = $this->withHeaders([
            'X-Signature' => hash_hmac('sha256', $rawBody, $secret),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)->getStatusCode();
        $rows[] = ['webhook', 'POST inbound (valid)', $valid];

        $invalid = $this->withHeaders([
            'X-Signature' => 'bad-signature',
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)->getStatusCode();
        $rows[] = ['webhook', 'POST inbound (invalid signature)', $invalid];

        $replay = $this->withHeaders([
            'X-Signature' => hash_hmac('sha256', $rawBody, $secret),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload);
        $rows[] = ['webhook', 'POST inbound (replay)', $replay->getStatusCode().' replay='.var_export($replay->json('data.replay'), true)];

        // --- Assertions ------------------------------------------------------------
        $this->assertSame(200, $rows[0][2], 'guest list tournaments');
        $this->assertSame(401, $rows[1][2], 'guest /me');
        $this->assertSame(401, $rows[2][2], 'guest registration');
        $this->assertSame(401, $rows[3][2], 'guest admin');
        $this->assertSame(200, $rows[4][2], 'player /me');
        $this->assertSame(200, $rows[5][2], 'player wallet');
        $this->assertSame(201, $rows[6][2], 'player registration');
        $this->assertSame(200, $rows[7][2], 'organizer view team');
        $this->assertSame(403, $rows[8][2], 'organizer admin surface');
        $this->assertSame(403, $rows[9][2], 'moderator admin surface');
        $this->assertSame(200, $rows[10][2], 'admin webhooks');
        $this->assertSame(200, $rows[11][2], 'webhook valid');
        $this->assertSame(401, $rows[12][2], 'webhook invalid signature');
        $this->assertSame('200 replay=true', $rows[13][2], 'webhook replay');

        // The payment settled exactly once despite the replay.
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        fwrite(STDERR, "\nPhase 15 API smoke matrix:\n");
        foreach ($rows as $row) {
            fwrite(STDERR, sprintf("  %-9s %-32s %s\n", $row[0], $row[1], $row[2]));
        }
    }
}
