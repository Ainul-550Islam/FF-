<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — robots.txt + XML sitemap: only indexable public resources are
 * listed; private/limited resources are excluded by construction.
 */
class SitemapRobotsTest extends Phase17TestCase
{
    public function test_robots_txt_is_served_and_blocks_private_areas(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('User-agent: *')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /wallet')
            ->assertSee('Disallow: /settings')
            ->assertSee('Disallow: /profile/edit')
            ->assertSee('Allow: /tournaments')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_sitemap_is_valid_xml_and_lists_public_pages(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Public Cup']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false)
            ->assertSee(route('home'), false)
            ->assertSee(route('tournaments.index'), false)
            ->assertSee(route('tournaments.show', ['tournament' => $tournament->slug]), false);
    }

    public function test_sitemap_excludes_draft_and_cancelled_tournaments(): void
    {
        $organizer = $this->makeUser('organizer');
        $draft = $this->makeTournament($organizer, Tournament::STATUS_DRAFT, ['name' => 'Draft Cup']);
        $cancelled = $this->makeTournament($organizer, Tournament::STATUS_CANCELLED, ['name' => 'Dead Cup']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString($draft->slug, $content);
        $this->assertStringNotContainsString($cancelled->slug, $content);
    }

    public function test_sitemap_includes_public_profiles_and_excludes_private(): void
    {
        $public = $this->makeUser('player', ['username' => 'publicone', 'privacy' => 'public']);
        $private = $this->makeUser('player', ['username' => 'privateone', 'privacy' => 'private']);
        $registered = $this->makeUser('player', ['username' => 'regone', 'privacy' => 'registered']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(route('profile.show', $public), $content);
        $this->assertStringNotContainsString(route('profile.show', $private), $content);
        $this->assertStringNotContainsString(route('profile.show', $registered), $content);
    }

    public function test_sitemap_adds_leaderboard_only_for_live_and_finished(): void
    {
        $organizer = $this->makeUser('organizer');
        $live = $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Live Cup']);
        $open = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Open Cup']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(
            route('leaderboard.show', ['tournament' => $live->slug]),
            $content
        );
        $this->assertStringNotContainsString(
            route('leaderboard.show', ['tournament' => $open->slug]),
            $content
        );
    }
}
