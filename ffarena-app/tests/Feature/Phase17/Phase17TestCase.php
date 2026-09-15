<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 17 — UI/UX, accessibility, SEO and performance tests base.
 */
abstract class Phase17TestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTournament(User $organizer, string $status = 'open', array $overrides = []): Tournament
    {
        $tournament = new Tournament;
        $tournament->organizer_id = $organizer->id;
        $tournament->name = $overrides['name'] ?? 'Phase17 Tournament';
        $tournament->slug = $overrides['slug'] ?? 'phase17-'.Str::random(8);
        $tournament->game_mode = $overrides['game_mode'] ?? 'squad';
        $tournament->map = $overrides['map'] ?? 'Bermuda';
        $tournament->entry_fee = $overrides['entry_fee'] ?? 0;
        $tournament->prize_pool = $overrides['prize_pool'] ?? 5000;
        $tournament->team_slots = $overrides['team_slots'] ?? 8;
        $tournament->team_size = $overrides['team_size'] ?? 4;
        $tournament->rules = $overrides['rules'] ?? 'No rules.';
        $tournament->starts_at = $overrides['starts_at'] ?? now()->addDay();
        $tournament->format = $overrides['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = $status;
        $tournament->save();

        return $tournament;
    }
}
