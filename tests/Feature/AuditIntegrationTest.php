<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GameMatch;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\Restriction;
use App\Models\Score;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 13 — real business flows emit central audit entries, and failed
 * actions emit none.
 */
class AuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Audit Tournament';
        $t->slug = 'audit-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    public function test_role_change_is_audited_with_before_and_after(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.users.moderate'), [
            'email' => $user->email,
        ])->assertRedirect();

        $log = AuditLog::where('action', 'role.change')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($user->id, $log->target_user_id);
        $this->assertSame(['role' => 'player'], $log->before);
        $this->assertSame(['role' => 'moderator'], $log->after);
        $this->assertSame('moderator', $user->fresh()->role);
    }

    public function test_failed_moderation_promotion_emits_no_audit_entry(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post(route('admin.users.moderate'), [
            'email' => 'nobody@example.com',
        ])->assertSessionHasErrors();

        $this->assertSame(0, AuditLog::where('action', 'role.change')->count());
    }

    public function test_wallet_credit_is_audited_with_target(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.wallet.credit', $user), [
            'amount' => '100.00',
            'description' => 'Support credit',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'wallet.credited')->firstOrFail();

        $this->assertSame($user->id, $log->target_user_id);
        $this->assertSame(10000, $log->metadata['amount_minor']);
    }

    public function test_restriction_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.restrict', $user), [
            'type' => Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
            'reason' => 'Suspected ban evasion',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'restriction.applied')->firstOrFail();

        $this->assertSame($user->id, $log->target_user_id);
        $this->assertSame(Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED, $log->metadata['type']);
    }

    public function test_identity_verification_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.verify', $user), [])->assertRedirect();

        $log = AuditLog::where('action', 'identity.verified')->firstOrFail();

        $this->assertSame($user->id, $log->target_user_id);
    }

    public function test_payout_approval_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = 'draft';
        $distribution->pool_minor = 5000;
        $distribution->total_allocated_minor = 5000;
        $distribution->save();

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->rank = 1;
        $payout->amount_minor = 5000;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_PENDING;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->save();

        $this->actingAs($admin)->post(route('admin.payouts.approve', $payout))->assertRedirect();

        $log = AuditLog::where('action', 'payout.approved')->firstOrFail();

        $this->assertSame($tournament->id, $log->tournament_id);
        $this->assertSame('payout', $log->entity_type);
        $this->assertSame($payout->id, $log->entity_id);
    }

    public function test_tournament_publish_is_audited_with_organizer_actor(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_DRAFT);

        $this->actingAs($organizer)->post(route('tournaments.publish', $tournament))->assertRedirect();

        $log = AuditLog::where('action', 'tournament.published')->firstOrFail();

        $this->assertSame($organizer->id, $log->actor_user_id);
        $this->assertSame('tournament', $log->entity_type);
        $this->assertSame($tournament->id, $log->entity_id);
    }

    public function test_roster_add_is_audited(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'New Player',
            'game_uid' => 'UID12345',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'team.member_added')->firstOrFail();

        $this->assertSame($captain->id, $log->actor_user_id);
        $this->assertSame($team->id, $log->entity_id);
        $this->assertSame('New Player', $log->metadata['player_name']);
    }

    public function test_score_adjustment_is_audited(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->status = GameMatch::STATUS_READY;
        $match->save();

        $score = new Score();
        $score->match_id = $match->id;
        $score->team_id = $teamA->id;
        $score->kills = 3;
        $score->placement = 2;
        $score->points = 0;
        $score->status = 'pending';
        $score->save();

        $this->actingAs($organizer)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id,
            'type' => 'bonus',
            'points' => 5,
            'reason' => 'Kill confirmation',
        ])->assertRedirect();

        $log = AuditLog::where('action', 'match.score_adjusted')->firstOrFail();

        $this->assertSame($match->id, $log->entity_id);
        $this->assertSame('bonus', $log->metadata['type']);
        $this->assertSame(5, $log->metadata['points']);
    }

    public function test_team_withdrawal_is_audited(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))->assertRedirect();

        $this->assertTrue(AuditLog::where('action', 'team.withdrawn')->exists());
        $this->assertSame(Team::STATUS_WITHDRAWN, $team->fresh()->status);
    }

    public function test_member_removal_is_audited(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain);

        $member = new TeamMember();
        $member->team_id = $team->id;
        $member->player_name = 'Roster Player';
        $member->game_uid = 'UID99999';
        $member->save();

        $this->actingAs($captain)->post(route('teams.members.remove', [$tournament, $team, $member]))->assertRedirect();

        $log = AuditLog::where('action', 'team.member_removed')->firstOrFail();

        $this->assertSame('Roster Player', $log->metadata['player_name']);
    }
}
