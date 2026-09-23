<?php

namespace Tests\Feature\Api;

use App\Models\SupportTicket;
use App\Models\Team;

/**
 * Phase 15 — Idempotency-Key behavior for critical mutations.
 */
class ApiIdempotencyTest extends ApiTestCase
{
    public function test_registration_replay_is_idempotent(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        $payload = $this->registrationPayload('Idem Squad', 'UIDIDEM001');

        $first = $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-1'])
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-1'])
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        // Exactly one team was created.
        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_reusing_key_with_different_body_is_conflict(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-2'])
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $this->registrationPayload('Team One', 'UIDIDEM002'))
            ->assertStatus(201);

        // Same key, different body → 409 conflict, no second team.
        $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-2'])
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', $this->registrationPayload('Team Two', 'UIDIDEM003'))
            ->assertStatus(409);

        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_support_ticket_creation_is_idempotent(): void
    {
        $user = $this->user();

        $payload = ['subject' => 'Idempotent ticket', 'category' => 'general', 'message' => 'body'];

        $first = $this->asUser($user, ['support:write'])
            ->withHeaders(['Idempotency-Key' => 'support-1'])
            ->postJson('/api/v1/me/support', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($user, ['support:write'])
            ->withHeaders(['Idempotency-Key' => 'support-1'])
            ->postJson('/api/v1/me/support', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        $this->assertSame(1, SupportTicket::where('user_id', $user->id)->count());
    }
}
