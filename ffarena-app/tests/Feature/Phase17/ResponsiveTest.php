<?php

namespace Tests\Feature\Phase17;

/**
 * Phase 17 — responsive/mobile web: viewport, mobile navigation toggle,
 * responsive tables and touch target sizing.
 */
class ResponsiveTest extends Phase17TestCase
{
    public function test_pages_declare_a_viewport(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('name="viewport"', false)
            ->assertSee('width=device-width', false);
    }

    public function test_mobile_navigation_toggle_exists(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-nav-toggle', false);
    }

    public function test_stylesheet_defines_mobile_breakpoint_and_touch_targets(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('@media (pointer: coarse)', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertStringContainsString('.table-wrap', $css);
    }

    public function test_tournament_list_cards_render_without_fixed_widths(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, 'open', ['name' => 'Mobile Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('grid cols-3', false)
            ->assertSee('Mobile Cup');
    }
}
