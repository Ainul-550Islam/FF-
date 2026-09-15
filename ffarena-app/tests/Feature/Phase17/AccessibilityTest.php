<?php

namespace Tests\Feature\Phase17;

use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Phase 17 — accessibility: landmarks, skip link, focus styles, reduced
 * motion, form labels, autocomplete for accessible authentication, status
 * announcements and non-colour-only status cues.
 */
class AccessibilityTest extends Phase17TestCase
{
    public function test_layout_has_language_landmarks_and_skip_link(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('class="skip-link"', false)
            ->assertSee('Skip to main content')
            ->assertSee('<main id="main"', false)
            ->assertSee('<header', false)
            ->assertSee('<footer', false);
    }

    public function test_mobile_navigation_controls_are_accessible(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-nav-toggle', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-controls="site-nav"', false)
            ->assertSee('id="site-nav"', false);
    }

    public function test_design_system_defines_visible_focus_and_reduced_motion(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('outline: 2px solid var(--focus)', $css);
    }

    public function test_login_form_has_labels_and_accessible_authentication(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<label for="email"', false)
            ->assertSee('<label for="password"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('type="password"', false);
    }

    public function test_register_form_has_labels_and_password_manager_support(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('<label for="game_uid"', false);
    }

    public function test_flash_error_is_announced_as_alert(): void
    {
        $this->withSession(['error' => 'Something went wrong.'])
            ->get(route('home'))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Something went wrong.');
    }

    public function test_flash_success_is_announced_as_status(): void
    {
        $this->withSession(['success' => 'All good.'])
            ->get(route('home'))
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('All good.');
    }

    public function test_status_pills_carry_text_not_colour_alone(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, 'open', ['name' => 'Status Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('class="pill open">Open</span>', false);
    }

    public function test_confirmed_teams_table_is_wrapped_and_captioned(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, 'open');

        $captain = $this->makeUser('player');
        $team = new Team;
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Table Squad';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = Team::STATUS_CONFIRMED;
        $team->save();

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('class="table-wrap"', false)
            ->assertSee('<caption class="sr-only">', false)
            ->assertSee('Table Squad');
    }

    public function test_tournament_detail_uses_breadcrumbs(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, 'open', ['name' => 'Crumb Cup']);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('class="breadcrumbs"', false)
            ->assertSee('aria-current="page"', false);
    }
}
