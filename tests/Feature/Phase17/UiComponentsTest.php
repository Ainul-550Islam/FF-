<?php

namespace Tests\Feature\Phase17;

use Illuminate\Support\Facades\Blade;

/**
 * Phase 17 — reusable Blade components render the expected accessible markup.
 */
class UiComponentsTest extends Phase17TestCase
{
    public function test_alert_component_announces_errors_assertively(): void
    {
        $html = Blade::render('<x-alert type="error">Account suspended.</x-alert>');

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('alert alert-error', $html);
        $this->assertStringContainsString('Account suspended.', $html);
    }

    public function test_alert_component_announces_success_politely(): void
    {
        $html = Blade::render('<x-alert type="success">Saved.</x-alert>');

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('alert alert-success', $html);
    }

    public function test_button_component_renders_link_when_href_given(): void
    {
        $html = Blade::render('<x-button href="/tournaments" variant="primary">Browse</x-button>');

        $this->assertStringContainsString('<a href="/tournaments"', $html);
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString('>Browse</a>', $html);
    }

    public function test_button_component_renders_button_when_href_absent(): void
    {
        $html = Blade::render('<x-button type="submit" variant="green">Save</x-button>');

        $this->assertStringContainsString('<button type="submit"', $html);
        $this->assertStringContainsString('class="btn btn-green"', $html);
    }

    public function test_status_pill_component_maps_status_to_text(): void
    {
        $html = Blade::render('<x-status-pill status="waitlisted" />');

        $this->assertStringContainsString('class="pill waitlisted"', $html);
        $this->assertStringContainsString('Waitlisted', $html);
    }

    public function test_status_pill_component_honours_custom_label(): void
    {
        $html = Blade::render('<x-status-pill status="checked" label="Check-in open" />');

        $this->assertStringContainsString('class="pill checked"', $html);
        $this->assertStringContainsString('Check-in open', $html);
    }

    public function test_empty_state_component_renders_title_and_guidance(): void
    {
        $html = Blade::render('<x-empty-state title="No results" icon="📊">Check back soon.</x-empty-state>');

        $this->assertStringContainsString('empty-state', $html);
        $this->assertStringContainsString('No results', $html);
        $this->assertStringContainsString('Check back soon.', $html);
    }
}
