<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — HTTP smoke matrix over the long-tail views (guest / authed /
 * admin). This complements the dedicated feature tests (disputes, support,
 * security, settlements, ops) by exercising every remaining view against
 * the design-system rewrite and asserting a 200 response with its page
 * title/heading rendered.
 */
class SmokeMatrixTest extends Phase17TestCase
{
    public function test_guest_can_view_public_pages(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Smoke Cup']);

        $this->get(route('home'))->assertOk();
        $this->get(route('tournaments.index'))->assertOk()->assertSee('Smoke Cup');
        $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('Smoke Cup');
        $this->get(route('leaderboard.show', $tournament))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    public function test_authenticated_user_can_view_account_and_settings_pages(): void
    {
        $user = $this->makeUser('player');

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        foreach ([
            'settings.security',
            'settings.sessions',
            'settings.login-history',
            'settings.connected-accounts',
            'settings.payment-methods',
        ] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }

        $this->get(route('wallet.index'))->assertOk();
        $this->get(route('notifications.index'))->assertOk();
        $this->get(route('support.index'))->assertOk()->assertSee('My Support Tickets');
        $this->get(route('support.create'))->assertOk()->assertSee('New Support Ticket');
    }

    public function test_admin_can_view_admin_dashboard_and_operations(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Admin Dashboard');
        $this->get(route('admin.accounts.index'))->assertOk()->assertSee('Account Administration');
        $this->get(route('admin.audit.index'))->assertOk()->assertSee('Audit Log');
        $this->get(route('admin.ops.dashboard'))->assertOk()->assertSee('Infrastructure Operations');
        $this->get(route('admin.ops.failed_jobs'))->assertOk()->assertSee('Failed Jobs');
    }

    public function test_admin_can_view_analytics_financial_and_security_pages(): void
    {
        $admin = $this->makeUser('admin');
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Metrics Cup']);

        $this->actingAs($admin);

        foreach ([
            ['admin.analytics.index', 'Analytics'],
            ['admin.analytics.tournaments', 'Match Analytics'],
            ['admin.analytics.financial', 'Financial Analytics'],
            ['admin.analytics.security', 'Security Analytics'],
            ['admin.analytics.disputes', 'Dispute Analytics'],
            ['admin.analytics.support', 'Support Analytics'],
            ['admin.payments.index', 'Payments'],
            ['admin.payouts.index', 'Payouts'],
            ['admin.settlements.index', 'Financial Settlements'],
            ['admin.security.dashboard', 'Security Dashboard'],
            ['admin.security.users', 'Suspicious Users'],
            ['admin.security.events', 'Risk Events'],
            ['admin.support.index', 'Support Queue'],
        ] as [$routeName, $heading]) {
            $this->get(route($routeName))->assertOk()->assertSee($heading);
        }
    }

    public function test_admin_can_view_wallet_and_settlement_detail_pages(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Settlement Cup']);

        $this->actingAs($admin)
            ->get(route('admin.wallet.show', $player))
            ->assertOk()
            ->assertSee('Wallet: '.$player->name);

        $this->get(route('admin.settlements.show', $tournament))
            ->assertOk()
            ->assertSee('Settlement: Settlement Cup')
            ->assertSee('Prize Configuration');
    }

    public function test_staff_can_view_moderation_pages_but_players_cannot(): void
    {
        $moderator = $this->makeUser('moderator');
        $player = $this->makeUser('player');

        $this->actingAs($moderator)->get(route('moderation.index'))->assertOk()->assertSee('Dispute Moderation Queue');
        $this->actingAs($moderator)->get(route('moderation.security'))->assertOk()->assertSee('Security Review');
        $this->actingAs($player)->get(route('moderation.security'))->assertStatus(403);
    }
}
