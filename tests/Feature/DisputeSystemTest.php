<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\ModerationEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 07 — dispute creation, evidence, state machine, moderation queue,
 * resolution and auditable result correction (happy paths + lifecycle).
 */
class DisputeSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Dispute Tournament';
        $t->slug = $o['slug'] ?? ('dispute-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
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
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
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

    protected function completeMatch(GameMatch $match, Team $winner, ?string $completedAt = null): GameMatch
    {
        $match->winner_team_id = $winner->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = $completedAt ?? now();
        $match->save();

        return $match->fresh();
    }

    // ------------------------------------------------------------------
    // Dispute creation
    // ------------------------------------------------------------------

    public function test_participant_captain_can_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'We actually won that game.',
            'team_id' => $teamA->id,
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertNotNull($dispute);
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->status);
        $this->assertSame($captainA->id, $dispute->opened_by);
        $this->assertSame($teamA->id, $dispute->team_id);
        $this->assertSame('disputed', $match->fresh()->status);
    }

    public function test_captain_team_is_inferred_when_team_id_omitted(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'Please review.',
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertSame($teamA->id, $dispute->team_id);
    }

    public function test_organizer_can_open_dispute_without_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'technical_issue',
            'description' => 'Room crashed mid game.',
        ])->assertRedirect();

        $dispute = Dispute::where('match_id', $match->id)->first();
        $this->assertNotNull($dispute);
        $this->assertNull($dispute->team_id);
        $this->assertSame($org->id, $dispute->opened_by);
    }

    public function test_admin_can_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($admin)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'rule_violation',
            'description' => 'Admin review required.',
        ])->assertRedirect();

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    public function test_unrelated_player_cannot_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $random = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($random)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'not my match',
        ])->assertStatus(403);

        $this->assertSame(0, Dispute::count());
    }

    public function test_other_team_captain_cannot_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainC = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $teamC = $this->makeTeam($tournament, $captainC);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainC)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'I am not in this match.',
        ])->assertStatus(403);

        $this->assertSame(0, Dispute::count());
    }

    public function test_captain_cannot_dispute_for_another_team(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'trying to dispute as the other team',
            'team_id' => $teamB->id,
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());
    }

    public function test_cross_tournament_dispute_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, $captainA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournamentB, $matchA]), [
            'category' => 'other',
            'description' => 'cross tournament',
        ])->assertStatus(404);

        $this->assertSame(0, Dispute::count());
    }

    public function test_duplicate_open_dispute_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'first',
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'second',
        ])->assertSessionHas('error');

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    // ------------------------------------------------------------------
    // Dispute window
    // ------------------------------------------------------------------

    public function test_expired_window_blocks_participant_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB, now()->subHours(25));

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner',
            'description' => 'too late',
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());
    }

    public function test_staff_bypasses_expired_dispute_window(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB, now()->subHours(25));

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'staff override',
        ])->assertRedirect();

        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    public function test_zero_window_disables_participant_disputes(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live', ['dispute_window_hours' => 0]);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'window disabled',
        ])->assertSessionHas('error');

        $this->assertSame(0, Dispute::count());

        // Staff can still open.
        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'staff',
        ])->assertRedirect();
        $this->assertSame(1, Dispute::where('match_id', $match->id)->count());
    }

    // ------------------------------------------------------------------
    // Evidence
    // ------------------------------------------------------------------

    public function test_participant_can_add_text_evidence(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text',
            'description' => 'The room ID was wrong.',
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertNotNull($evidence);
        $this->assertNull($evidence->path);
        $this->assertSame('text', $evidence->type);
    }

    public function test_staff_can_add_image_evidence_to_private_disk(): void
    {
        Storage::fake('local');

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'Screenshot of the result',
            'evidence_file' => UploadedFile::fake()->create('result.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertNotNull($evidence);
        $this->assertNotNull($evidence->path);
        $this->assertSame('image', $evidence->type);
        Storage::disk('local')->assertExists($evidence->path);
    }

    public function test_staff_can_add_document_evidence(): void
    {
        Storage::fake('local');

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'document',
            'description' => 'Rules PDF',
            'evidence_file' => UploadedFile::fake()->create('rules.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
        $this->assertSame('document', $evidence->type);
        Storage::disk('local')->assertExists($evidence->path);
    }

    public function test_evidence_blocked_after_resolution(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Result stands.',
        ])->assertRedirect();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text',
            'description' => 'late evidence',
        ])->assertSessionHas('error');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    // ------------------------------------------------------------------
    // State machine
    // ------------------------------------------------------------------

    public function test_valid_transition_open_to_review_to_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertRedirect();
        $this->assertSame(Dispute::STATUS_UNDER_REVIEW, $dispute->fresh()->status);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Upheld.',
        ])->assertRedirect();

        $this->assertSame(Dispute::STATUS_RESOLVED, $dispute->fresh()->status);
    }

    public function test_participant_cannot_resolve_reject_review_or_assign(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.reject', [$tournament, $match, $dispute]), [
            'resolution' => 'nope',
        ])->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id, 'resolution' => 'nope',
        ])->assertStatus(403);
        $this->actingAs($captainA)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $moderator->id,
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->assigned_to);
    }

    public function test_participant_cannot_self_assign_as_reviewer(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $captainA->id,
        ])->assertStatus(403);

        $this->assertNull($dispute->fresh()->assigned_to);
    }

    public function test_assignment_requires_moderator_or_admin(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        // Assign to a normal player → rejected by the service.
        $this->actingAs($org)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $player->id,
        ])->assertSessionHas('error');
        $this->assertNull($dispute->fresh()->assigned_to);

        // Assign to a moderator → accepted.
        $this->actingAs($org)->post(route('matches.disputes.assign', [$tournament, $match, $dispute]), [
            'reviewer_id' => $moderator->id,
        ])->assertRedirect();
        $this->assertSame($moderator->id, $dispute->fresh()->assigned_to);
    }

    // ------------------------------------------------------------------
    // Resolution / rejection / cancellation
    // ------------------------------------------------------------------

    public function test_resolution_requires_a_reason(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => '',
        ])->assertSessionHasErrors('resolution');
    }

    public function test_resolve_corrects_winner_and_reopens_bracket_slot(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'winner wrong',
        ])->assertRedirect();
        $dispute = Dispute::first();
        $this->assertSame('disputed', $match->fresh()->status);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id,
            'resolution' => 'Team B actually won.',
        ])->assertRedirect();

        $dispute = $dispute->fresh();
        $this->assertSame(Dispute::STATUS_RESOLVED, $dispute->status);
        $this->assertSame($teamB->id, $dispute->resolution_winner_team_id);

        $match = $match->fresh();
        $this->assertSame('completed', $match->status);
        $this->assertSame($teamB->id, $match->winner_team_id);
        $this->assertNotNull($match->completed_at);
    }

    public function test_reject_restores_match_with_original_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'contest',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.reject', [$tournament, $match, $dispute]), [
            'resolution' => 'Evidence insufficient — result stands.',
        ])->assertRedirect();

        $this->assertSame(Dispute::STATUS_REJECTED, $dispute->fresh()->status);
        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    public function test_opener_can_cancel_own_open_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.cancel', [$tournament, $match, $dispute]))->assertRedirect();

        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);
        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Result correction (ScoringService integration)
    // ------------------------------------------------------------------

    public function test_result_correction_recalculates_and_preserves_rule_version(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1); // 12 + 6 = 18
        $ruleId = $score->scoring_rules_id;

        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'kills wrong', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'Corrected kills and placement.',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 3, 'placement' => 2],
            ],
        ])->assertRedirect();

        $score = $score->fresh();
        $this->assertSame(3, (int) $score->kills);
        $this->assertSame(2, (int) $score->placement);
        $this->assertSame(9, (int) $score->placement_points);
        $this->assertSame(3, (int) $score->kill_points);
        $this->assertSame(12, (int) $score->points);
        $this->assertSame($ruleId, $score->scoring_rules_id);
    }

    public function test_correction_ignores_client_supplied_points(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'recalc',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 1, 'placement' => 1, 'points' => 9999],
            ],
        ])->assertRedirect();

        $score = $score->fresh();
        $this->assertSame(13, (int) $score->points); // 12 + 1 — 9999 ignored
    }

    public function test_correction_rejects_non_participant_team(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $outsider = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $scoring = app(ScoringService::class);
        $score = $scoring->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'attempted outsider correction',
            'corrections' => [
                ['team_id' => $outsider->id, 'kills' => 99, 'placement' => 1],
            ],
        ])->assertSessionHas('error');

        $score = $score->fresh();
        $this->assertSame(6, (int) $score->kills);
        $this->assertSame(1, (int) $score->placement);
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    public function test_cross_tournament_resolution_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournamentA, $matchA]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournamentB, $matchA, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'cross tournament',
        ])->assertStatus(404);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_moderation_events_are_recorded_with_correct_actor(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text', 'description' => 'explanation',
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.disputes.review', [$tournament, $match, $dispute]))->assertRedirect();
        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamB->id, 'resolution' => 'done',
        ])->assertRedirect();

        $events = ModerationEvent::where('dispute_id', $dispute->id)->get();

        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_DISPUTE_OPENED && $e->actor_id === $captainA->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_EVIDENCE_ADDED && $e->actor_id === $captainA->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_STATUS_CHANGED && $e->actor_id === $org->id));
        $this->assertTrue($events->contains(fn ($e) => $e->event === ModerationEvent::EVENT_RESOLVED && $e->actor_id === $org->id));
    }

    public function test_correction_creates_result_corrected_audit_event(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(ScoringService::class)->submitScore($match, $teamA, 6, 1);
        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_score', 'description' => 'base',
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'corrected',
            'corrections' => [
                ['team_id' => $teamA->id, 'kills' => 2, 'placement' => 3],
            ],
        ])->assertRedirect();

        $this->assertTrue(
            ModerationEvent::where('dispute_id', $dispute->id)
                ->where('event', ModerationEvent::EVENT_RESULT_CORRECTED)
                ->exists()
        );
    }

    // ------------------------------------------------------------------
    // Moderation queue
    // ------------------------------------------------------------------

    public function test_moderation_queue_is_staff_only(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $moderator = $this->makeUser('moderator');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();

        $this->actingAs($player)->get(route('moderation.index'))->assertStatus(403);
        $this->actingAs($org)->get(route('moderation.index'))->assertOk();
        $this->actingAs($moderator)->get(route('moderation.index'))->assertOk();
    }

    public function test_organizer_queue_is_scoped_to_own_tournaments(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($orgA);
        $tournamentB = $this->makeTournament($orgB, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $matchA = $this->completeMatch($this->makeMatch($tournamentA, $teamA, $teamB), $teamB);

        $this->actingAs($orgA)->post(route('matches.disputes.store', [$tournamentA, $matchA]), [
            'category' => 'other', 'description' => 'base',
        ])->assertRedirect();

        // orgB's queue must not contain orgA's dispute.
        $response = $this->actingAs($orgB)->get(route('moderation.index'));
        $response->assertOk();
        $response->assertDontSee('wrong_winner');
        $this->assertSame(0, $response->viewData('disputes')->total());
    }

    public function test_dispute_show_page_is_accessible_to_participant_and_staff(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->completeMatch($this->makeMatch($tournament, $teamA, $teamB), $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'wrong_winner', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->get(route('matches.disputes.show', [$tournament, $match, $dispute]))
            ->assertOk()->assertSee('Wrong Winner');
        $this->actingAs($org)->get(route('matches.disputes.show', [$tournament, $match, $dispute]))
            ->assertOk()->assertSee('Moderation');
    }
}
