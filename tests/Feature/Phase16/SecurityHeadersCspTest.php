<?php

namespace Tests\Feature\Phase16;

/**
 * GAP-10 A6 — Content-Security-Policy rollout modes.
 *
 * Pins the contract of the CSP branch in App\Http\Middleware\SecurityHeaders:
 *
 *  - CSP off  → neither header, no matter what else is configured;
 *  - CSP on + report_only → Content-Security-Policy-Report-Only, and the
 *    enforcing header must be absent (a report-only policy is not a
 *    guarantee, so it must never be confused with one);
 *  - CSP on + enforce → Content-Security-Policy, and the report-only header
 *    must be absent;
 *  - the configured policy string and the optional report-uri collector are
 *    propagated verbatim;
 *  - the shipped default policy is matched to this app's real Blade/JS usage
 *    (self-hosted assets, inline JSON-LD, no third-party CDNs) and stays
 *    report-only by default;
 *  - HSTS behaviour is untouched by all of the above.
 */
class SecurityHeadersCspTest extends Phase16TestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshot = $this->snapshotConfig([
            'observability.security_headers.csp_enable',
            'observability.security_headers.csp_report_only',
            'observability.security_headers.csp_policy',
            'observability.security_headers.csp_report_uri',
            'observability.security_headers.hsts_enable',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfig($this->snapshot);

        parent::tearDown();
    }

    public function test_csp_is_disabled_by_default(): void
    {
        config([
            'observability.security_headers.csp_enable' => false,
            'observability.security_headers.csp_report_only' => true,
        ]);

        $response = $this->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_report_only_mode_sends_the_report_only_header_and_not_the_enforcing_one(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => true,
            'observability.security_headers.csp_policy' => "default-src 'self'",
        ]);

        $response = $this->get('/');

        $this->assertSame(
            "default-src 'self'",
            $response->headers->get('Content-Security-Policy-Report-Only')
        );
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_enforcing_mode_sends_the_enforcing_header_and_not_the_report_only_one(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => false,
            'observability.security_headers.csp_policy' => "default-src 'self'",
        ]);

        $response = $this->get('/');

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_report_uri_is_appended_to_the_policy_when_configured(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => true,
            'observability.security_headers.csp_policy' => "default-src 'self'",
            'observability.security_headers.csp_report_uri' => 'https://csp-reports.ffarena.test/csp',
        ]);

        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertSame(
            "default-src 'self'; report-uri https://csp-reports.ffarena.test/csp",
            $policy
        );
    }

    public function test_no_report_uri_directory_is_added_when_unset(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => true,
            'observability.security_headers.csp_policy' => "default-src 'self'",
            'observability.security_headers.csp_report_uri' => null,
        ]);

        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringNotContainsString('report-uri', $policy);
    }

    public function test_csp_headers_are_also_applied_to_json_api_responses(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => false,
            'observability.security_headers.csp_policy' => "default-src 'self'",
        ]);

        $response = $this->getJson('/api/v1/tournaments');

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_shipped_default_policy_matches_app_usage_and_is_report_only(): void
    {
        // Read the shipped config file defaults, not the test process runtime
        // values, so this test still proves the default a fresh deployment gets.
        $defaults = require base_path('config/observability.php');
        $headers = $defaults['security_headers'];

        $this->assertTrue(
            (bool) $headers['csp_report_only'],
            'CSP must ship report-only so the first rollout cannot break the UI.'
        );
        $this->assertFalse(
            (bool) $headers['csp_enable'],
            'CSP stays opt-in until an operator turns it on.'
        );

        $policy = (string) $headers['csp_policy'];

        // Self-hosted only: no third-party CDNs, and 'self' must be the
        // default source.
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("style-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);

        // Blade views inline JSON-LD scripts and a little styling, so the
        // inline allowances must be present or enforcement would blank the UI.
        $this->assertStringContainsString("'unsafe-inline'", $policy);

        // Never allow wildcards or remote script origins.
        $this->assertStringNotContainsString('*', $policy);
        $this->assertStringNotContainsString('http://', $policy);
    }

    public function test_hsts_behaviour_is_unchanged_while_csp_is_enabled(): void
    {
        config([
            'observability.security_headers.csp_enable' => true,
            'observability.security_headers.csp_report_only' => false,
        ]);

        // Plain HTTP: no HSTS, but CSP still present.
        $plain = $this->get('/');

        $this->assertNull($plain->headers->get('Strict-Transport-Security'));
        $this->assertNotNull($plain->headers->get('Content-Security-Policy'));

        // Disabling CSP must not affect the HSTS decision either.
        config(['observability.security_headers.csp_enable' => false]);

        $noCsp = $this->get('/');

        $this->assertNull($noCsp->headers->get('Strict-Transport-Security'));
        $this->assertNull($noCsp->headers->get('Content-Security-Policy'));
    }
}
