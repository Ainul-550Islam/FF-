<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 07 — dispute/evidence security: file validation, private storage,
 * IDOR/cross-tournament access, mass-assignment, role escalation, evidence
 * immutability and state-transition bypass.
 */
class DisputeSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Security Tournament';
        $t->slug = $o['slug'] ?? ('security-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
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
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = GameMatch::STATUS_COMPLETED;
        $m->winner_team_id = $t1->id;
        $m->completed_at = now();
        $m->save();

        return $m;
    }

    protected function openDispute(User $opener, Tournament $tournament, GameMatch $match): Dispute
    {
        $this->actingAs($opener)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'base dispute',
        ])->assertRedirect();

        return Dispute::where('match_id', $match->id)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // File validation
    // ------------------------------------------------------------------

    public function test_evidence_invalid_extension_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'evil',
            'evidence_file' => UploadedFile::fake()->create('evil.exe', 100),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    public function test_evidence_invalid_mime_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        // A file claiming a PNG extension but with an executable MIME.
        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'fake',
            'evidence_file' => UploadedFile::fake()->create('fake.png', 100, 'application/x-msdownload'),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    public function test_evidence_oversized_file_rejected(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'huge',
            'evidence_file' => UploadedFile::fake()->create('huge.png', DisputeEvidence::MAX_KB + 1000, 'image/png'),
        ])->assertSessionHasErrors('evidence_file');

        $this->assertSame(0, DisputeEvidence::where('dispute_id', $dispute->id)->count());
    }

    // ------------------------------------------------------------------
    // Private storage + access control
    // ------------------------------------------------------------------

    public function test_private_evidence_is_not_publicly_accessible(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        // Log out the organizer so the request below is truly a guest.
        auth()->logout();

        // Guests are redirected to login (route is behind `auth`).
        $this->get(route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]))
            ->assertRedirect(route('login'));
    }

    public function test_unrelated_user_cannot_view_evidence(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        $this->actingAs($stranger)
            ->get(route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]))
            ->assertStatus(403);
    }

    public function test_cross_tournament_evidence_access_blocked(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA);
        $teamB = $this->makeTeam($tournamentA);
        $match = $this->makeMatch($tournamentA, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournamentA, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournamentA, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();

        $evidence = DisputeEvidence::firstOrFail();

        // Same evidence id, but addressed through a different tournament's
        // route — must 404, never stream.
        $this->actingAs($org)
            ->get(route('matches.disputes.evidence.show', [$tournamentB, $match, $dispute, $evidence]))
            ->assertStatus(404);
    }

    public function test_evidence_must_belong_to_dispute(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $teamC = $this->makeTeam($tournament);
        $matchA = $this->makeMatch($tournament, $teamA, $teamB);
        $matchB = $this->makeMatch($tournament, $teamB, $teamC);
        $disputeA = $this->openDispute($org, $tournament, $matchA);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $matchA, $disputeA]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();

        $disputeB = $this->openDispute($org, $tournament, $matchB);

        $this->actingAs($org)
            ->get(route('matches.disputes.evidence.show', [$tournament, $matchB, $disputeB, $evidence]))
            ->assertStatus(404);
    }

    public function test_evidence_is_immutable_for_participants(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        $this->actingAs($captainA)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'text', 'description' => 'explanation',
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();

        // Participants cannot remove evidence (staff only).
        $this->actingAs($captainA)
            ->post(route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]))
            ->assertStatus(403);

        $this->assertDatabaseHas('dispute_evidence', ['id' => $evidence->id]);
    }

    public function test_staff_can_remove_evidence_and_it_is_audited(): void
    {
        Storage::fake('local');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.evidence.store', [$tournament, $match, $dispute]), [
            'type' => 'image',
            'description' => 'shot',
            'evidence_file' => UploadedFile::fake()->create('shot.png', 100, 'image/png'),
        ])->assertRedirect();
        $evidence = DisputeEvidence::firstOrFail();
        $path = $evidence->path;

        $this->actingAs($org)
            ->post(route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]))
            ->assertRedirect();

        $this->assertDatabaseMissing('dispute_evidence', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('moderation_events', [
            'dispute_id' => $dispute->id,
            'event' => 'dispute.evidence_removed',
        ]);
    }

    // ------------------------------------------------------------------
    // Mass assignment / injection
    // ------------------------------------------------------------------

    public function test_client_supplied_status_and_assignment_are_ignored_on_open(): void
    {
        $org = $this->makeUser('organizer');
        $attacker = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => 'base',
            'status' => 'resolved',
            'assigned_to' => $attacker->id,
            'opened_by' => $attacker->id,
            'resolved_by' => $attacker->id,
        ])->assertRedirect();

        $dispute = Dispute::firstOrFail();
        $this->assertSame(Dispute::STATUS_OPEN, $dispute->status);
        $this->assertSame($org->id, $dispute->opened_by);
        $this->assertNull($dispute->assigned_to);
        $this->assertNull($dispute->resolved_by);
    }

    public function test_resolve_rejects_non_participant_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $outsider = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $outsider->id,
            'resolution' => 'hijack',
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
        $this->assertSame($teamA->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Role escalation
    // ------------------------------------------------------------------

    public function test_player_cannot_promote_moderator(): void
    {
        $player = $this->makeUser('player');
        $target = $this->makeUser('player');

        $this->actingAs($player)->post(route('admin.users.moderate'), [
            'email' => $target->email,
        ])->assertStatus(403);

        $this->assertSame('player', $target->fresh()->role);
    }

    public function test_admin_promotes_and_demotes_moderator(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.users.moderate'), [
            'email' => $target->email,
        ])->assertRedirect();
        $this->assertSame('moderator', $target->fresh()->role);

        // Moderators can now access the queue.
        $this->actingAs($target->fresh())->get(route('moderation.index'))->assertOk();

        $this->actingAs($admin)->post(route('admin.users.unmoderate', $target))->assertRedirect();
        $this->assertSame('player', $target->fresh()->role);
    }

    public function test_admin_cannot_be_demoted(): void
    {
        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');

        $this->actingAs($admin)->post(route('admin.users.unmoderate', $otherAdmin))->assertSessionHas('error');
        $this->assertSame('admin', $otherAdmin->fresh()->role);
    }

    // ------------------------------------------------------------------
    // State-transition bypass
    // ------------------------------------------------------------------

    public function test_participant_cannot_bypass_state_machine(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captainA)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other', 'description' => 'base', 'team_id' => $teamA->id,
        ])->assertRedirect();
        $dispute = Dispute::first();

        // Direct resolve attempt by a participant.
        $this->actingAs($captainA)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'self resolve',
        ])->assertStatus(403);

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->fresh()->status);
    }

    public function test_cancelled_dispute_cannot_be_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);
        $dispute = $this->openDispute($org, $tournament, $match);

        $this->actingAs($org)->post(route('matches.disputes.cancel', [$tournament, $match, $dispute]))->assertRedirect();
        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);

        // Resolving a cancelled dispute must fail (invalid transition).
        $this->actingAs($org)->post(route('matches.disputes.resolve', [$tournament, $match, $dispute]), [
            'winner_team_id' => $teamA->id,
            'resolution' => 'too late',
        ])->assertSessionHas('error');

        $this->assertSame(Dispute::STATUS_CANCELLED, $dispute->fresh()->status);
    }

    public function test_dispute_requires_description_and_category(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament);
        $teamB = $this->makeTeam($tournament);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'other',
            'description' => '',
        ])->assertSessionHasErrors('description');

        $this->actingAs($org)->post(route('matches.disputes.store', [$tournament, $match]), [
            'category' => 'bogus_category',
            'description' => 'desc',
        ])->assertSessionHasErrors('category');

        $this->assertSame(0, Dispute::count());
    }
}
