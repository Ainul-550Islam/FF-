<?php

namespace Tests\Feature\Phase17;

/**
 * Phase 17 — frontend performance regression checks: external (cacheable)
 * stylesheet instead of a render-blocking inline <style> block, deferred
 * JavaScript, favicon/site identity and a present meta description.
 */
class PerformanceTest extends Phase17TestCase
{
    public function test_layout_loads_external_stylesheet_instead_of_inline_block(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="', $html);
        $this->assertStringContainsString('css/app.css', $html);
        // The old layout shipped a large inline <style> block — it must be gone.
        $this->assertStringNotContainsString('<style>', $html);
    }

    public function test_shared_script_is_deferred(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('js/app.js', $html);
        $this->assertStringContainsString('defer', $html);
    }

    public function test_site_identity_assets_are_linked(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringContainsString('name="theme-color"', $html);
    }

    public function test_stylesheet_is_bounded_in_size(): void
    {
        $size = filesize(public_path('css/app.css'));

        // A generous ceiling for a full design system; guards against
        // accidental bloat (e.g. re-embedding a minified framework).
        $this->assertLessThan(120 * 1024, $size, 'public/css/app.css exceeds 120 KB');
    }

    public function test_shared_js_is_bounded_in_size(): void
    {
        $size = filesize(public_path('js/app.js'));

        $this->assertLessThan(40 * 1024, $size, 'public/js/app.js exceeds 40 KB');
    }
}
