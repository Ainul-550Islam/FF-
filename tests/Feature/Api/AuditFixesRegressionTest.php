<?php

namespace Tests\Feature\Api;

use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\PrivateTableParticipant;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\PrivateTableService;
use App\Services\ScoringService;
use App\Support\PaymentCallbackState;

/**
 * Regression suite for the 2026-10-07 production audit (FIX-01 … FIX-21).
 *
 * Each test pins one audit fix: it FAILS on the pre-fix codebase and PASSES
 * with the fix applied. Run with:
 *
 *   php artisan test tests/Feature/Api/AuditFixesRegressionTest.php
 */
class AuditFixesRegressionTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // FIX-01: privileged User attributes are not mass-assignable
    // ------------------------------------------------------------------
    public function test_privileged_user_fields_are_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mallory',
            'username' => 'mallory_priv_esc',
            'email' => 'mallory-privesc@example.com',
            'password' => 'password123',
            'is_admin' => true,
            'is_staff' => true,
            'is_banned' => true,
            'account_status' => 'active',
        ]);

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse($user->fresh()->is_staff);
        $this->assertFalse($user->fresh()->is_banned);
    }

    // ------------------------------------------------------------------
    // FIX-02: web checkout requires `pay` on the team (IDOR closed)
    // ------------------------------------------------------------------
    public function test_web_checkout_rejects_payment_for_other_captains_team(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $intruder = $this->user();
        $t = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($t, $captainA, 'pending', 'UIDFIX02A1');

        $this->actingAs($intruder)
            ->post(route('payment.initiate', [$t, $team]), ['provider' => 'bkash'])
            ->assertForbidden();

        $this->assertDatabaseMissing('payments', ['team_id' => $team->id]);
    }

    // ------------------------------------------------------------------
    // FIX-03: gateway callback state token is mandatory
    // ------------------------------------------------------------------
    public function test_gateway_callback_rejects_missing_state_token(): void
    {
        config(['payments.callback.require_state' => true]);

        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $t = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($t, $captain, 'pending', 'UIDFIX03A1');

        // Attribute-by-attribute: Payment is not mass-fillable by design.
        $payment = new Payment();
        $payment->tournament_id = $t->id;
        $payment->team_id = $team->id;
        $payment->payer_user_id = $captain->id;
        $payment->amount_minor = 10000;
        $payment->amount = '100.00';
        $payment->currency = 'BDT';
        $payment->method = 'bkash';
        $payment->trx_id = 'FIX03TRX1';
        $payment->provider = 'bkash';
        $payment->provider_reference = 'FIX03REF1';
        $payment->idempotency_key = 'fix03-key-1';
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        // No `state` at all → rejected (previously skipped verification).
        $this->post('/payments/callback/bkash', ['payment_id' => $payment->id])
            ->assertForbidden();

        // Forged state → rejected.
        $this->post('/payments/callback/bkash', [
            'payment_id' => $payment->id,
            'state' => 'forged-token',
        ])->assertForbidden();

        // A valid state token passes verification (the gateway itself is
        // unconfigured in tests, so the flow lands on the pending screen
        // instead of settling — the point is the HMAC gate, not settlement).
        $this->post('/payments/callback/bkash', [
            'payment_id' => $payment->id,
            'state' => PaymentCallbackState::build($payment),
        ])->assertRedirect(route('payment.pending', [$t, $team, $payment]));
    }

    // ------------------------------------------------------------------
    // FIX-04: Gameberry API enforces the bearer hardening stack
    // ------------------------------------------------------------------
    public function test_gameberry_api_rejects_deactivated_accounts_token(): void
    {
        $user = $this->user(['account_status' => 'deactivated']);

        $this->asUser($user, ['*'])
            ->getJson('/v1/gameberry/dice/collection')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_gameberry_api_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/v1/gameberry/dice/collection')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // FIX-05: fake "service tokens" are rejected
    // ------------------------------------------------------------------
    public function test_fake_jwt_shaped_service_token_is_rejected(): void
    {
        // Three dot-separated parts with no signature — the old
        // isServiceToken() accepted exactly this shape.
        $fake = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0 noisesignaturehere';

        $this->withToken($fake)->getJson('/api/v1/me')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // FIX-08: kills are upper-bounded (API + service)
    // ------------------------------------------------------------------
    public function test_score_kills_are_capped(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDFIX08A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDFIX08B1');

        app(ScoringService::class)->createVersion($t, [
            'name' => 'v1',
            'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $match = new GameMatch();
        $match->tournament_id = $t->id;
        $match->team1_id = $a->id;
        $match->team2_id = $b->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = GameMatch::BRACKET_WINNERS;
        $match->status = GameMatch::STATUS_LIVE;
        $match->save();

        $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/'.$match->id.'/scores', [
                'team_id' => $a->id,
                'kills' => 99999,
                'placement' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->expectException(\DomainException::class);
        app(ScoringService::class)->submitScore($match, $a, ScoringRule::MAX_KILLS + 1, 2);
    }

    // ------------------------------------------------------------------
    // FIX-09: admin money actions require step-up confirmation
    // ------------------------------------------------------------------
    public function test_admin_wallet_credit_requires_recent_password_confirmation(): void
    {
        $admin = $this->admin();
        $player = $this->user();

        // No `auth.password_confirmed_at` in the session → bounced to the
        // confirmation screen instead of moving money.
        $this->actingAs($admin)
            ->post(route('admin.wallet.credit', $player), ['amount' => '10.00'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, app(\App\Services\WalletService::class)->getBalance($player->id));
    }

    // ------------------------------------------------------------------
    // FIX-10: room credentials are gated on the web match page
    // ------------------------------------------------------------------
    public function test_match_room_credentials_hidden_from_public_on_web(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $outsider = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDFIX10A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDFIX10B1');

        $match = new GameMatch();
        $match->tournament_id = $t->id;
        $match->team1_id = $a->id;
        $match->team2_id = $b->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = GameMatch::BRACKET_WINNERS;
        $match->status = GameMatch::STATUS_READY;
        $match->room_id = 'ROOMFIX10';
        $match->room_pass = 'PASSFIX10';
        $match->save();

        $url = '/tournaments/'.$t->slug.'/matches/'.$match->id;

        // Guest: no credentials.
        $this->get($url)->assertOk()->assertDontSee('ROOMFIX10')->assertDontSee('PASSFIX10');

        // Authenticated outsider: no credentials.
        $this->actingAs($outsider)->get($url)->assertOk()
            ->assertDontSee('ROOMFIX10')->assertDontSee('PASSFIX10');

        // Participating captain: credentials visible.
        $this->actingAs($captainA)->get($url)->assertOk()
            ->assertSee('ROOMFIX10')->assertSee('PASSFIX10');
    }

    // ------------------------------------------------------------------
    // FIX-11: paginated API lists return a FLAT data[] (OpenAPI contract)
    // ------------------------------------------------------------------
    public function test_tournament_list_returns_flat_data_array(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $this->makeTournament($org, 'open', ['name' => 'Flat Cup One']);
        $this->makeTournament($org, 'open', ['name' => 'Flat Cup Two']);

        $res = $this->getJson('/api/v1/tournaments');
        $res->assertOk();

        $data = $res->json('data');
        $this->assertIsArray($data, 'data must be a JSON array, not a nested paginator object');
        $this->assertArrayNotHasKey('data', $data, 'data.data nesting detected — mobile lists would render empty');
        $res->assertJsonPath('meta.pagination.total', 2);
    }

    // ------------------------------------------------------------------
    // FIX-12: GET /matches/{match}/scores exists (mobile calls it)
    // ------------------------------------------------------------------
    public function test_match_scores_endpoint_exists(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, null, 'confirmed', 'UIDFIX12A1');
        $b = $this->makeTeam($t, null, 'confirmed', 'UIDFIX12B1');

        $match = new GameMatch();
        $match->tournament_id = $t->id;
        $match->team1_id = $a->id;
        $match->team2_id = $b->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = GameMatch::BRACKET_WINNERS;
        $match->status = GameMatch::STATUS_LIVE;
        $match->save();

        $this->getJson('/api/v1/matches/'.$match->id.'/scores')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ------------------------------------------------------------------
    // FIX-13: API registration enforces the web password/username policy
    // ------------------------------------------------------------------
    public function test_api_register_rejects_short_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Short Pass',
            'username' => 'shortpass1',
            'email' => 'shortpass@example.com',
            'role' => 'player',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['email' => 'shortpass@example.com']);
    }

    // ------------------------------------------------------------------
    // FIX-15: Gameberry failures use the standard error envelope
    // ------------------------------------------------------------------
    public function test_gameberry_errors_use_standard_envelope(): void
    {
        $user = $this->user();

        $this->asUser($user, ['*'])
            ->getJson('/v1/gameberry/private-tables/NOPE12')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    // ------------------------------------------------------------------
    // FIX-16: private-table gold cannot be duplicated
    // ------------------------------------------------------------------
    public function test_private_table_join_without_gold_grants_no_seat(): void
    {
        $host = $this->user();
        $broke = $this->user();
        $tables = app(PrivateTableService::class);
        $gold = app(GoldEconomyService::class);

        // Max bet (100k) far exceeds the 5k starter gold.
        $table = $tables->createTable($host->id, [
            'game_mode' => 'classic',
            'bet_amount' => 100000,
        ]);

        try {
            $tables->joinTable($broke->id, $table->code);
            $this->fail('joinTable should throw when the stake cannot be covered');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('gold', $e->getMessage());
        }

        $this->assertDatabaseMissing('private_table_participants', [
            'private_table_id' => $table->id,
            'user_id' => $broke->id,
        ]);
    }

    public function test_host_leaving_table_mints_no_gold(): void
    {
        $host = $this->user();
        $tables = app(PrivateTableService::class);
        $gold = app(GoldEconomyService::class);

        $table = $tables->createTable($host->id, [
            'game_mode' => 'classic',
            'bet_amount' => 100,
        ]);

        $before = $gold->getBalance($host->id);
        $tables->leaveTable($host->id, $table->code);

        // The host never staked at creation, so leaving must not credit gold.
        $this->assertSame($before, $gold->getBalance($host->id));
    }

    // ------------------------------------------------------------------
    // FIX-19: web registration flows through the shared engine
    // ------------------------------------------------------------------
    public function test_web_registration_delegates_to_shared_engine(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $t = $this->makeTournament($org, 'open');

        $this->actingAs($captain)
            ->post('/tournaments/'.$t->slug.'/register', [
                'name' => 'Engine Squad',
                'captain_name' => 'Captain',
                'phone' => '01700000000',
                'game_uid' => 'UIDFIX19A1',
                'members' => [],
            ])
            ->assertRedirect();

        $team = Team::where('tournament_id', $t->id)->where('captain_id', $captain->id)->first();
        $this->assertNotNull($team);
        $this->assertSame(Team::STATUS_PENDING, $team->status);
    }

    // ------------------------------------------------------------------
    // FIX-21: tournament money fields lock after registration opens
    // ------------------------------------------------------------------
    public function test_tournament_financial_fields_locked_after_registration(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $t = $this->makeTournament($org, 'open', ['entry_fee' => 100, 'prize_pool' => 5000]);
        $this->makeTeam($t, $captain, 'pending', 'UIDFIX21A1');

        $payload = [
            'name' => $t->name,
            'game_mode' => $t->game_mode,
            'map' => $t->map,
            'entry_fee' => 999, // changed after a team registered
            'prize_pool' => 5000,
            'team_slots' => $t->team_slots,
            'team_size' => $t->team_size,
            'starts_at' => now()->addDays(2)->toDateTimeString(),
        ];

        $this->actingAs($org)->put('/tournaments/'.$t->slug, $payload)->assertStatus(422);
        $this->assertSame(10000, $t->fresh()->entryFeeMinor());
    }

    // ------------------------------------------------------------------
    // Providers gap (P1): manual methods have no status query
    // ------------------------------------------------------------------
    public function test_callback_for_manual_method_lands_on_pending(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $t = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($t, $captain, 'pending', 'UIDPROV01');

        // Attribute-by-attribute: Payment is not mass-fillable by design.
        $payment = new Payment();
        $payment->tournament_id = $t->id;
        $payment->team_id = $team->id;
        $payment->payer_user_id = $captain->id;
        $payment->amount_minor = 10000;
        $payment->amount = '100.00';
        $payment->currency = 'BDT';
        $payment->method = 'rocket';
        $payment->trx_id = 'PROVTRX1';
        $payment->provider = 'rocket';
        $payment->provider_reference = 'PROVREF1';
        $payment->idempotency_key = 'prov-key-1';
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        // Rocket cannot be queried server-to-server: a return with a valid
        // state token must land on the pending screen (manual TrxID flow),
        // not fatal with a 500 on the undefined query method.
        $this->get(route('payments.callback', [
            'provider' => 'rocket',
            'payment_id' => $payment->id,
            'state' => PaymentCallbackState::build($payment),
        ]))->assertRedirect(route('payment.pending', [$t, $team, $payment]));
    }
}
