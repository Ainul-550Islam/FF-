<?php

namespace Tests\Feature\Gameberry;

use App\Models\AntiCheatIncident;
use App\Models\GameSession;
use App\Models\LedgerEntry;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Gameberry\AntiCheatDecision;
use App\Services\Gameberry\AntiCheatService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A8 (tracker row 018) — Gameberry-side anti-cheat.
 *
 * The evaluation is deterministic and read-only, and escalation goes through
 * the existing Phase 10 incident workflow. Both halves are pinned here,
 * including the guarantee that neither path can move money.
 */
class GameberryAntiCheatTest extends TestCase
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
        $t->name = 'Anti-cheat Cup';
        $t->slug = 'anticheat-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'live';
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
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    protected function makeSession(User $user, array $attributes = []): GameSession
    {
        return GameSession::create(array_merge([
            'user_id' => $user->id,
            'game_mode' => 'classic',
            'game_variation' => 'classic',
            'bet_amount' => 100,
            'result' => 'pending',
            'gold_change' => 0,
            'gem_change' => 0,
            'trophies_change' => 0,
            'duration_seconds' => 120,
            'is_team_up' => false,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(8),
        ], $attributes));
    }

    protected function antiCheat(): AntiCheatService
    {
        return app(AntiCheatService::class);
    }

    /**
     * A plausible action log: one action every 400ms inside the session.
     *
     * @return array<int, array{id: string, type: string, at: int}>
     */
    protected function plausibleActions(GameSession $session, int $count = 12, int $intervalMs = 400): array
    {
        $start = $session->started_at->getTimestampMs() + 500;
        $actions = [];

        for ($i = 0; $i < $count; $i++) {
            $actions[] = [
                'id' => 'a'.$i,
                'type' => 'roll',
                'at' => $start + ($i * $intervalMs),
            ];
        }

        return $actions;
    }

    public function test_a_plausible_session_is_allowed_and_writes_nothing(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $wallet = app(WalletService::class)->walletFor($user);
        app(WalletService::class)->credit($wallet, 10000, LedgerEntry::TYPE_DEPOSIT, 'seed');

        $ledgerBefore = LedgerEntry::count();
        $balanceBefore = $wallet->fresh()->balanceMinor();

        $decision = $this->antiCheat()->evaluate($session, $this->plausibleActions($session));

        $this->assertTrue($decision->isAllowed(), 'Plausible actions must be allowed: '.json_encode($decision->toArray()));
        $this->assertSame(0, $decision->score);
        $this->assertSame([], $decision->rules());
        $this->assertSame($session->id, $decision->sessionId);
        $this->assertSame(12, $decision->actionCount);

        // Read-only: no incidents, no ledger, no balance change.
        $this->assertSame(0, AntiCheatIncident::count());
        $this->assertSame($ledgerBefore, LedgerEntry::count());
        $this->assertSame($balanceBefore, $wallet->fresh()->balanceMinor());
    }

    public function test_impossibly_fast_actions_are_flagged(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        // 5ms between actions: not humanly possible.
        $decision = $this->antiCheat()->evaluate($session, $this->plausibleActions($session, 12, 5));

        $this->assertFalse($decision->isAllowed());
        $this->assertContains(AntiCheatService::RULE_IMPOSSIBLE_SPEED, $decision->rules());
        $this->assertGreaterThan(0, $decision->score);
    }

    public function test_out_of_order_timestamps_and_duplicate_actions_block(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $start = $session->started_at->getTimestampMs() + 500;

        $actions = [
            ['id' => 'a1', 'type' => 'roll', 'at' => $start + 5000],
            ['id' => 'a2', 'type' => 'roll', 'at' => $start + 1000],   // backwards
            ['id' => 'a1', 'type' => 'roll', 'at' => $start + 1500],   // duplicate id
            ['id' => 'a3', 'type' => 'roll', 'at' => $start + 1600],
            ['id' => 'a4', 'type' => 'roll', 'at' => $start + 1610],
            ['id' => 'a5', 'type' => 'roll', 'at' => $start + 1620],
        ];

        $decision = $this->antiCheat()->evaluate($session, $actions);

        $this->assertTrue($decision->isBlocked(), 'Corroborated anomalies must block: '.json_encode($decision->toArray()));
        $this->assertContains(AntiCheatService::RULE_REGRESSING_TIMESTAMP, $decision->rules());
        $this->assertContains(AntiCheatService::RULE_DUPLICATE_ACTION, $decision->rules());
        $this->assertContains(AntiCheatService::RULE_IMPOSSIBLE_SPEED, $decision->rules());
    }

    public function test_actions_outside_the_session_window_are_detected(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $actions = [
            ['id' => 'a1', 'type' => 'roll', 'at' => $session->started_at->getTimestampMs() - 600_000],
            ['id' => 'a2', 'type' => 'roll', 'at' => $session->finished_at->getTimestampMs() + 600_000],
            ['id' => 'a3', 'type' => 'roll', 'at' => $session->finished_at->getTimestampMs() + 600_100],
            ['id' => 'a4', 'type' => 'roll', 'at' => $session->finished_at->getTimestampMs() + 600_200],
        ];

        $decision = $this->antiCheat()->evaluate($session, $actions);

        $this->assertFalse($decision->isAllowed());
        $this->assertContains(AntiCheatService::RULE_OUTSIDE_WINDOW, $decision->rules());
    }

    public function test_unusable_timestamps_are_reported_rather_than_guessed(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $actions = [
            ['id' => 'a1', 'type' => 'roll', 'at' => 'not-a-timestamp'],
            ['id' => 'a2', 'type' => 'roll', 'at' => null],
            ['id' => 'a3', 'type' => 'roll'],
        ];

        $decision = $this->antiCheat()->evaluate($session, $actions);

        $this->assertFalse($decision->isAllowed());
        $this->assertContains(AntiCheatService::RULE_INVALID_TIMESTAMP, $decision->rules());
        $this->assertSame(3, $decision->actionCount, 'The action count is reported even when timestamps are unusable.');
    }

    public function test_a_result_with_no_actions_is_detected(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user, ['result' => 'win']);

        $decision = $this->antiCheat()->evaluate($session, []);

        $this->assertFalse($decision->isAllowed());
        $this->assertContains(AntiCheatService::RULE_RESULT_WITHOUT_ACTIONS, $decision->rules());
    }

    public function test_evaluation_can_be_disabled_by_configuration(): void
    {
        config(['gameberry.anti_cheat.enabled' => false]);

        $user = $this->makeUser();
        $session = $this->makeSession($user, ['result' => 'win']);

        $decision = $this->antiCheat()->evaluate($session, []);

        $this->assertTrue($decision->isAllowed(), 'With evaluation disabled nothing may be flagged.');
        $this->assertFalse($decision->shouldEscalate());
    }

    public function test_escalation_opens_a_phase10_incident_for_a_flagged_session(): void
    {
        $organizer = $this->makeUser('organizer');
        $staff = $this->makeUser('moderator');
        $player = $this->makeUser();

        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $player);
        $session = $this->makeSession($player, ['private_table_id' => null]);

        $wallet = app(WalletService::class)->walletFor($player);
        app(WalletService::class)->credit($wallet, 10000, LedgerEntry::TYPE_DEPOSIT, 'seed');

        $ledgerBefore = LedgerEntry::count();
        $balanceBefore = $wallet->fresh()->balanceMinor();

        $result = $this->antiCheat()->evaluateAndEscalate($session, $this->plausibleActions($session, 12, 5), [
            'tournament' => $tournament,
            'team' => $team,
            'accused' => $player,
            'reporter' => $staff,
        ]);

        $this->assertFalse($result['decision']->isAllowed());
        $this->assertNotNull($result['incident'], 'A flagged session with a tournament context must open an incident.');
        $this->assertSame(AntiCheatIncident::STATUS_FLAGGED, (string) $result['incident']->status);
        $this->assertSame($tournament->id, $result['incident']->tournament_id);
        $this->assertSame($player->id, $result['incident']->accused_user_id);
        $this->assertStringContainsString('game_session:'.$session->id, (string) $result['incident']->evidence_reference);

        // No money moved, on either path.
        $this->assertSame($ledgerBefore, LedgerEntry::count());
        $this->assertSame($balanceBefore, $wallet->fresh()->balanceMinor());
        $this->assertSame(0, Score::count());
    }

    public function test_escalation_without_a_tournament_context_creates_no_incident(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $decision = $this->antiCheat()->evaluate($session, $this->plausibleActions($session, 12, 5));

        $this->assertTrue($decision->shouldEscalate());

        // No tournament ⇒ no incident. An unattributed accusation is worse
        // than no accusation.
        $this->assertNull($this->antiCheat()->escalate($session, $decision, []));
        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_an_allowed_session_is_never_escalated(): void
    {
        $organizer = $this->makeUser('organizer');
        $staff = $this->makeUser('moderator');
        $player = $this->makeUser();

        $tournament = $this->makeTournament($organizer);
        $session = $this->makeSession($player);

        $result = $this->antiCheat()->evaluateAndEscalate($session, $this->plausibleActions($session), [
            'tournament' => $tournament,
            'reporter' => $staff,
        ]);

        $this->assertTrue($result['decision']->isAllowed());
        $this->assertNull($result['incident']);
        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_the_decision_object_is_json_safe_and_carries_no_money(): void
    {
        $user = $this->makeUser();
        $session = $this->makeSession($user);

        $decision = $this->antiCheat()->evaluate($session, $this->plausibleActions($session, 12, 5));
        $array = $decision->toArray();

        $this->assertNotFalse(json_encode($array));
        $this->assertSame($decision->decision, $array['decision']);
        $this->assertArrayNotHasKey('gold_change', $array);
        $this->assertArrayNotHasKey('balance', $array);
        $this->assertArrayNotHasKey('bet_amount', $array);
        $this->assertContains($decision->decision, AntiCheatDecision::DECISIONS);
    }
}
