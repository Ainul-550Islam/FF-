<?php

namespace Tests\Feature\Api;

use App\Models\Tournament;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\GoPaymentGatewayAdapter;
use App\Services\Integration\ServiceAuthenticator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * P2 backlog regressions (2026-10-07): every test fails on the pre-P2 tree.
 *
 * Tournament create-path caps, member/registration UID parity with the
 * captain rule, service HMAC signing + guard, and the 90-day token backstop.
 */
class P2BacklogRegressionTest extends ApiTestCase
{
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
        $t->name = $o['name'] ?? 'P2 Tournament';
        $t->slug = $o['slug'] ?? ('p2-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function validTournamentPayload(): array
    {
        return [
            'name' => 'Fee Cap Cup',
            'game_mode' => 'squad',
            'map' => 'Bermuda',
            'entry_fee' => 100,
            'prize_pool' => 5000,
            'team_slots' => 8,
            'team_size' => 4,
            'starts_at' => now()->addDay()->toDateTimeString(),
        ];
    }

    public function test_tournament_store_enforces_the_fee_and_pool_caps(): void
    {
        $organizer = $this->makeUser('organizer');

        // Above the update-path caps (FIX-21 values) — rejected at creation.
        $this->actingAs($organizer)->post(route('tournaments.store'), array_merge(
            $this->validTournamentPayload(),
            ['entry_fee' => 1000001, 'prize_pool' => 100000001]
        ))->assertSessionHasErrors(['entry_fee', 'prize_pool']);

        // Exactly at the caps — accepted.
        $this->actingAs($organizer)->post(route('tournaments.store'), array_merge(
            $this->validTournamentPayload(),
            ['name' => 'At Cap Cup', 'entry_fee' => 1000000, 'prize_pool' => 100000000]
        ))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('tournaments', ['name' => 'At Cap Cup']);
    }

    public function test_member_uid_format_matches_the_captain_rule(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $payload = [
            'name' => 'Parity Squad',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UIDCAPT01',
            'members' => [
                ['player_name' => 'Bad UID', 'game_uid' => 'bad uid!'],
            ],
        ];

        $this->actingAs($captain)
            ->post(route('teams.store', $tournament), $payload)
            ->assertSessionHasErrors('members.0.game_uid');

        $this->assertDatabaseMissing('teams', ['name' => 'Parity Squad']);
    }

    public function test_web_registration_rejects_a_malformed_uid(): void
    {
        $this->post('/register', [
            'name' => 'Uid Probe',
            'email' => 'uid-probe@example.com',
            'game_uid' => 'x',
            'role' => 'player',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('game_uid');

        $this->assertDatabaseMissing('users', ['email' => 'uid-probe@example.com']);
    }

    public function test_go_adapter_signs_with_the_service_secret(): void
    {
        config([
            'services_go_rust.go_payment.enabled' => true,
            'services_go_rust.go_payment.hmac_secret' => 'p2-test-secret',
        ]);

        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response(['status' => 'succeeded'], 200);
        });

        app(GoPaymentGatewayAdapter::class)->createPayment([
            'provider' => 'manual',
            'external_id' => 'p2-ext-1',
        ]);

        $this->assertNotNull($captured);

        $psr = $captured->toPsrRequest();
        $timestamp = (int) $psr->getHeaderLine('X-Timestamp');
        $nonce = $psr->getHeaderLine('X-Nonce');

        $expected = ServiceAuthenticator::signRequest(
            'p2-test-secret',
            'POST',
            '/api/v1/payments',
            '{"provider":"manual","external_id":"p2-ext-1"}',
            $timestamp,
            $nonce
        );

        $this->assertSame($expected, $psr->getHeaderLine('X-Signature'));
        $this->assertSame('ffarena-laravel', $psr->getHeaderLine('X-Service-ID'));
    }

    protected function registerServicePingRoute(): void
    {
        Route::post('/_p2/service-ping', function () {
            return response()->json(['service' => request()->attributes->get('service_id')]);
        })->middleware('service.hmac');
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    protected function signedHeaders(string $method, string $path, string $body, string $secret, string $serviceId = 'payment-gateway-go', ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $nonce = (string) Str::uuid();

        return [[
            'X-Service-ID' => $serviceId,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => ServiceAuthenticator::signRequest($secret, $method, $path, $body, $timestamp, $nonce),
        ], $nonce];
    }

    public function test_service_hmac_guard_accepts_a_valid_signature(): void
    {
        $this->registerServicePingRoute();
        config(['services_go_rust.go_payment.hmac_secret' => 'p2-hmac-secret']);

        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"a":1}', 'p2-hmac-secret');

        $this->postJson('/_p2/service-ping', ['a' => 1], $headers)
            ->assertOk()
            ->assertJson(['service' => 'payment-gateway-go']);
    }

    public function test_service_hmac_guard_rejects_forgeries_replays_and_stale_calls(): void
    {
        $this->registerServicePingRoute();
        config(['services_go_rust.go_payment.hmac_secret' => 'p2-hmac-secret']);

        // Tampered body.
        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"a":1}', 'p2-hmac-secret');
        $this->postJson('/_p2/service-ping', ['a' => 2], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_signature');

        // Stale timestamp.
        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"a":1}', 'p2-hmac-secret', 'payment-gateway-go', time() - 1000);
        $this->postJson('/_p2/service-ping', ['a' => 1], $headers)->assertStatus(401);

        // Unknown service.
        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"a":1}', 'p2-hmac-secret', 'nope');
        $this->postJson('/_p2/service-ping', ['a' => 1], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error', 'unknown_service');

        // Missing headers.
        $this->postJson('/_p2/service-ping', ['a' => 1])->assertStatus(401);

        // Replay: the same signed request twice.
        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"replay":true}', 'p2-hmac-secret');
        $this->postJson('/_p2/service-ping', ['replay' => true], $headers)->assertOk();
        $this->postJson('/_p2/service-ping', ['replay' => true], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error', 'replayed_request');

        // Placeholder secret fails closed.
        config(['services_go_rust.go_payment.hmac_secret' => 'CHANGE_ME_X']);
        [$headers] = $this->signedHeaders('POST', '/_p2/service-ping', '{"a":1}', 'CHANGE_ME_X');
        $this->postJson('/_p2/service-ping', ['a' => 1], $headers)
            ->assertStatus(401)
            ->assertJsonPath('error', 'service_not_configured');
    }

    public function test_tokens_beyond_the_global_lifetime_are_rejected(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('old-token', ['*'], now()->addDays(200));
        $token->accessToken->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->authForget();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'token_expired');
    }

    public function test_api_token_issuance_clamps_to_the_global_ceiling(): void
    {
        $user = $this->makeUser();

        $token = app(ApiTokenService::class)->issue($user, 'long-lived', ['profile:read'], 200);

        $this->assertTrue(
            $token->accessToken->expires_at->lessThanOrEqualTo(now()->addDays(90)->addHour()),
            'Issuance beyond 90 days must be clamped.'
        );
    }
}
