<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — tournament discovery: search, status and game-mode filters.
 * The default (unfiltered) listing behaviour is unchanged.
 */
class DiscoveryTest extends Phase17TestCase
{
    public function test_listing_shows_all_public_tournaments_by_default(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'One Cup']);
        $this->makeTournament($organizer, Tournament::STATUS_FINISHED, ['name' => 'Two Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('One Cup')
            ->assertSee('Two Cup');
    }

    public function test_search_filters_by_name(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Bermuda Blitz', 'map' => 'Bermuda']);
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Purgatorio Cup', 'map' => 'Purgatorio']);

        $this->get(route('tournaments.index', ['q' => 'bermuda']))
            ->assertOk()
            ->assertSee('Bermuda Blitz')
            ->assertDontSee('Purgatorio Cup');
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Blitz']);

        // A raw `%` must match nothing (it is escaped), not everything.
        $this->get(route('tournaments.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('No tournaments found');
    }

    public function test_status_filter_works(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Open Cup']);
        $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Live Cup']);

        $this->get(route('tournaments.index', ['status' => 'live']))
            ->assertOk()
            ->assertSee('Live Cup')
            ->assertDontSee('Open Cup');
    }

    public function test_game_mode_filter_works(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Duo Cup', 'game_mode' => 'duo']);
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Squad Cup', 'game_mode' => 'squad']);

        $this->get(route('tournaments.index', ['game_mode' => 'duo']))
            ->assertOk()
            ->assertSee('Duo Cup')
            ->assertDontSee('Squad Cup');
    }

    public function test_invalid_status_is_ignored(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Safe Cup']);

        $this->get(route('tournaments.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Safe Cup');
    }

    public function test_pagination_is_accessible(): void
    {
        $organizer = $this->makeUser('organizer');

        for ($i = 1; $i <= 13; $i++) {
            $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Cup '.$i]);
        }

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('aria-label="Pagination"', false)
            ->assertSee('aria-current="page"', false);
    }
}
