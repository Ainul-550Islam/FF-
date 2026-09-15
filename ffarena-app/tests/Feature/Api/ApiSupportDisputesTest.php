<?php

namespace Tests\Feature\Api;

use App\Models\SupportTicket;

/**
 * Phase 15 — support (own-only + IDOR) and disputes (authorized read-only).
 */
class ApiSupportDisputesTest extends ApiTestCase
{
    public function test_support_ticket_create_and_list_own_only(): void
    {
        $user = $this->user();
        $other = $this->user();

        $created = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Can I change my team?',
                'category' => 'general',
                'message' => 'Please help.',
            ]);

        $created->assertStatus(201);
        $ticketId = $created->json('data.id');

        $this->asUser($other, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Other',
                'category' => 'general',
                'message' => 'Other body',
            ])->assertStatus(201);

        $mine = $this->asUser($user, ['support:read'])->getJson('/api/v1/me/support');
        $mine->assertStatus(200);
        $this->assertSame([$ticketId], array_column($mine->json('data'), 'id'));
    }

    public function test_support_idor_is_blocked(): void
    {
        $user = $this->user();
        $other = $this->user();

        $ticket = new SupportTicket();
        $ticket->user_id = $other->id;
        $ticket->subject = 'Private';
        $ticket->category = 'general';
        $ticket->priority = 'normal';
        $ticket->status = 'open';
        $ticket->save();

        $this->asUser($user, ['support:read'])->getJson('/api/v1/me/support/' . $ticket->id)
            ->assertStatus(403);

        $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support/' . $ticket->id . '/messages', ['body' => 'snooping'])
            ->assertStatus(403);
    }

    public function test_support_messages_and_reply(): void
    {
        $user = $this->user();

        $created = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Messages',
                'category' => 'payment',
                'message' => 'First message',
            ]);
        $ticketId = $created->json('data.id');

        $replied = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support/' . $ticketId . '/messages', ['body' => 'Second message']);
        $replied->assertStatus(201);

        $messages = $this->asUser($user, ['support:read'])
            ->getJson('/api/v1/me/support/' . $ticketId . '/messages');
        $messages->assertStatus(200);
        $this->assertSame(2, count($messages->json('data.messages')));
    }

    public function test_disputes_list_and_read_are_authorized(): void
    {
        $stranger = $this->user();
        $other = $this->user();
        $captainB = $this->user();

        // A dispute owned by another user must not appear in the caller's
        // list and must not be readable by id.
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'finished');
        $teamA = $this->makeTeam($tournament, $other, 'confirmed', 'UIDDSP001');
        $teamB = $this->makeTeam($tournament, $captainB, 'confirmed', 'UIDDSP002');

        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = 'completed';
        $match->save();

        $dispute = app(\App\Services\DisputeService::class)->open($match, $teamA, $other, 'wrong_score', 'I disagree with the result');

        // The other user is the opener, so it appears in their list.
        $theirs = $this->asUser($other, ['disputes:read'])->getJson('/api/v1/me/disputes');
        $this->assertSame([$dispute->id], array_column($theirs->json('data'), 'id'));

        // A stranger (not a party) must not see it in their list, and a
        // direct id read is forbidden.
        $mine = $this->asUser($stranger, ['disputes:read'])->getJson('/api/v1/me/disputes');
        $this->assertSame([], $mine->json('data'));

        $this->asUser($stranger, ['disputes:read'])->getJson('/api/v1/disputes/' . $dispute->id)
            ->assertStatus(403);
    }

    public function test_dispute_evidence_is_never_serialized(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'finished');
        $teamA = $this->makeTeam($tournament, $player, 'confirmed', 'UIDEV0011');
        $teamB = $this->makeTeam($tournament, null, 'confirmed', 'UIDEV0022');

        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = 'completed';
        $match->save();

        $dispute = app(\App\Services\DisputeService::class)->open($match, $teamA, $player, 'wrong_score', 'Evidence check');

        $res = $this->asUser($player, ['disputes:read'])->getJson('/api/v1/disputes/' . $dispute->id);
        $res->assertStatus(200);

        $body = json_encode($res->json('data'));
        foreach (['evidence', 'path', 'correction', 'resolution_winner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "leaked field: {$forbidden}");
        }
    }
}
