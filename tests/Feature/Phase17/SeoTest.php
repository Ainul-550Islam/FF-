<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — SEO metadata: titles, descriptions, canonical URLs, noindex
 * defaults for private pages, Open Graph and JSON-LD structured data.
 */
class SeoTest extends Phase17TestCase
{
    public function test_homepage_has_full_metadata(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title>FF Arena — Free Fire Tournaments in Bangladesh</title>', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'"', false)
            ->assertSee('<meta property="og:site_name"', false)
            ->assertSee('<meta property="og:type" content="website"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_tournament_listing_is_indexable_with_canonical(): void
    {
        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('tournaments.index').'"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_tournament_detail_has_canonical_title_and_event_data(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Alpha Cup']);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('<title>Alpha Cup — FF Arena</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('tournaments.show', $tournament).'"', false)
            ->assertSee('"@type":"Event"', false)
            ->assertSee('"name":"Alpha Cup"', false)
            ->assertSee('OnlineEventAttendanceMode', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_cancelled_tournament_is_not_indexable(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_CANCELLED);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('"@type":"Event"', false);
    }

    public function test_leaderboard_is_indexable(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Ranked Cup']);

        $this->get(route('leaderboard.show', $tournament))
            ->assertOk()
            ->assertSee('<title>Leaderboard — Ranked Cup — FF Arena</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('leaderboard.show', $tournament).'"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_public_profile_is_indexable_with_person_data(): void
    {
        $user = $this->makeUser('player', [
            'username' => 'acegamer',
            'privacy' => 'public',
            'bio' => 'Pro Free Fire squad leader from Dhaka.',
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('profile.show', $user).'"', false)
            ->assertSee('"@type":"ProfilePage"', false)
            ->assertSee('"@type":"Person"', false)
            ->assertSee('"alternateName":"acegamer"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_private_profile_is_noindex_and_leaks_nothing_into_metadata(): void
    {
        $user = $this->makeUser('player', [
            'username' => 'ghost',
            'privacy' => 'private',
            'bio' => 'TOP SECRET BIO',
        ]);

        $response = $this->get(route('profile.show', $user));

        $response->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('TOP SECRET BIO', false)
            ->assertDontSee('ProfilePage', false)
            ->assertDontSee('<meta name="description" content="TOP SECRET', false);
    }

    public function test_authenticated_private_pages_are_noindex_by_default(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)
            ->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('<link rel="canonical"', false);
    }
}
