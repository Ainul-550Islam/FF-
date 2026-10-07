<?php

namespace Tests\Feature\Hosting;

use App\Http\Middleware\SecurityHeaders;
use App\Support\TrustedProxyConfig;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Hosting readiness — the application as it is actually deployed.
 *
 * GAP-10 (finding F-25): `config/trustedproxy.php` shipped with the repository
 * but nothing consumed it, so behind the load balancer that terminates TLS
 * Laravel could not see the real client address or the real scheme. That is not
 * cosmetic — it disables HSTS (SecurityHeaders only emits it for a secure
 * request), makes every IP-keyed rate limit share one bucket (so the limiter
 * protects nothing and can 429 the whole internet), and generates http://
 * absolute URLs and signed links on an https:// site.
 *
 * These tests state the guarantee in BOTH directions, because trusting a
 * forwarded header is only safe when the peer is one you listed:
 *
 *   1. no proxy configured  → the headers are ignored (a client cannot spoof);
 *   2. listed proxy peer    → the headers are believed (real IP, https);
 *   3. a peer OUTSIDE the list → still ignored (the list is not decorative).
 *
 * They drive the same middleware Laravel installs, constructed from the same
 * configuration the application uses, so they exercise the production path
 * rather than a paraphrase of it.
 */
class ProductionHostingTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('trustedproxy.proxies', null);
        Config::set('trustedproxy.headers', 'X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO|X_FORWARDED_AWS_ELB');

        // `TrustProxies` keeps its configuration in STATIC properties, so
        // without this a value set in one test would leak into the next and
        // the fail-closed assertions would pass for the wrong reason.
        TrustProxies::flushState();

        // `Request::setTrustedProxies()` is static state on the Symfony
        // request too.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * Builds the middleware the way the application's provider configures it.
     *
     * @param  string|array<int, string>|null  $proxies
     */
    protected function middlewareTrusting(string|array|null $proxies): TrustProxies
    {
        TrustProxies::flushState();

        if ($proxies === null) {
            // Fail-closed: nothing registered, so the middleware keeps the
            // framework default of trusting no proxy.
            return new TrustProxies();
        }

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(TrustedProxyConfig::headerMask());

        return new TrustProxies();
    }

    /**
     * Runs a request through the trusted-proxy middleware and returns what the
     * application would see: [client ip, isSecure, host].
     *
     * @param  array<string, string>  $headers
     * @return array{ip: string, secure: bool, host: string}
     */
    protected function throughProxyMiddleware(string|array|null $proxies, string $peer, array $headers = []): array
    {
        // The request is created against the application's real host; any
        // spoofed host travels ONLY in the X-Forwarded-Host header, so the
        // assertion cannot pass (or fail) because of how the fixture was built.
        $request = Request::create(
            'http://ffarena.test/health',
            'GET',
            server: ['REMOTE_ADDR' => $peer],
        );

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        // `TrustProxies` has no constructor arguments in this framework
        // version: the values are applied through the same static switches the
        // application uses (`TrustProxies::at()` / `::withHeaders()`), which
        // is exactly what App\\Providers\\TrustedProxyServiceProvider does.
        $middleware = $this->middlewareTrusting($proxies);

        $seen = [];

        $middleware->handle($request, function (Request $request) use (&$seen): Response {
            $seen = [
                'ip' => $request->ip(),
                'secure' => $request->isSecure(),
                'host' => $request->getHost(),
            ];

            return new Response('ok');
        });

        return $seen;
    }

    // ----------------------------------------------------------------------
    // 1. Fail-closed default: no proxies configured
    // ----------------------------------------------------------------------

    public function test_without_configuration_no_forwarded_header_is_believed(): void
    {
        $seen = $this->throughProxyMiddleware(null, '198.51.100.9', [
            'X-Forwarded-For' => '203.0.113.77',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example.com',
        ]);

        $this->assertSame('198.51.100.9', $seen['ip'], 'A forged X-Forwarded-For must not become the client IP.');
        $this->assertFalse($seen['secure'], 'A forged X-Forwarded-Proto must not make the request look like HTTPS.');
        $this->assertSame('ffarena.test', $seen['host'], 'A forged X-Forwarded-Host must not change the host.');
    }

    public function test_the_default_configuration_trusts_nothing(): void
    {
        Config::set('trustedproxy.proxies', null);

        $this->assertNull(TrustedProxyConfig::proxies(), 'The default must be "trust nothing".');
        $this->assertStringContainsString('No trusted proxies', TrustedProxyConfig::describe());
    }

    // ----------------------------------------------------------------------
    // 2. A configured proxy is believed — this is what makes HSTS, rate
    //    limits and absolute URLs correct behind a load balancer
    // ----------------------------------------------------------------------

    public function test_a_configured_proxy_supplies_the_real_client_ip_and_scheme(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.0/8');

        $seen = $this->throughProxyMiddleware(TrustedProxyConfig::proxies(), '10.0.0.5', [
            'X-Forwarded-For' => '203.0.113.77',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'ffarena.example',
        ]);

        $this->assertSame('203.0.113.77', $seen['ip'], 'The client IP must come from the forwarded header for a trusted peer.');
        $this->assertTrue($seen['secure'], 'HTTPS must be visible so HSTS is emitted and https:// URLs are generated.');
        $this->assertSame('ffarena.example', $seen['host']);
    }

    public function test_a_trusted_proxy_enables_hsts_on_the_response(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.0/8');
        Config::set('observability.security_headers.hsts_enable', true);

        $request = Request::create('http://ffarena.test/health', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5']);
        $request->headers->set('X-Forwarded-For', '203.0.113.77');
        $request->headers->set('X-Forwarded-Proto', 'https');

        $middleware = $this->middlewareTrusting(TrustedProxyConfig::proxies());

        /** @var Response $response */
        $response = $middleware->handle($request, function (Request $request): Response {
            // Run the real security-header middleware, exactly as the kernel
            // does globally, so the assertion covers the shipped behaviour.
            return app(SecurityHeaders::class)
                ->handle($request, fn (): Response => new Response('ok'));
        });

        $this->assertTrue(
            $response->headers->has('Strict-Transport-Security'),
            'With a trusted proxy the request is secure, so HSTS must be sent — otherwise the site never teaches browsers to use HTTPS.',
        );
    }

    // ----------------------------------------------------------------------
    // 3. The list is a boundary, not a formality
    // ----------------------------------------------------------------------

    public function test_a_peer_outside_the_configured_list_is_not_trusted(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.0/8');
        $proxies = TrustedProxyConfig::proxies();

        $this->assertTrue(TrustedProxyConfig::trusts($proxies, '10.1.2.3'), 'A peer inside the range must be trusted.');
        $this->assertFalse(TrustedProxyConfig::trusts($proxies, '198.51.100.9'), 'A peer outside the range must not be trusted.');
        $this->assertFalse(TrustedProxyConfig::trusts($proxies, '10.0.0.256'), 'An invalid peer must never be trusted.');
    }

    public function test_subnet_boundaries_are_computed_exactly(): void
    {
        $this->assertTrue(TrustedProxyConfig::trusts('10.0.0.0/8', '10.255.255.255'));
        $this->assertFalse(TrustedProxyConfig::trusts('10.0.0.0/8', '11.0.0.1'));
        $this->assertTrue(TrustedProxyConfig::trusts('192.168.0.0/16', '192.168.42.7'));
        $this->assertFalse(TrustedProxyConfig::trusts('192.168.0.0/16', '192.169.0.1'));
        $this->assertTrue(TrustedProxyConfig::trusts('203.0.113.10/32', '203.0.113.10'));
        $this->assertFalse(TrustedProxyConfig::trusts('203.0.113.10/32', '203.0.113.11'));
        $this->assertTrue(TrustedProxyConfig::trusts('2001:db8::/32', '2001:db8::1'));
        $this->assertFalse(TrustedProxyConfig::trusts('2001:db8::/32', '2001:db9::1'));
        // A v4 peer must never match a v6 range, or vice versa.
        $this->assertFalse(TrustedProxyConfig::trusts('::/0', '203.0.113.10'));
    }

    public function test_a_wildcard_is_explicit_and_documented_as_such(): void
    {
        $this->assertSame('*', TrustedProxyConfig::proxies('*'));
        $this->assertTrue(TrustedProxyConfig::trusts('*', '203.0.113.10'));

        Config::set('trustedproxy.proxies', '*');

        $this->assertStringContainsString('every peer', TrustedProxyConfig::describe());
    }

    // ----------------------------------------------------------------------
    // 4. Configuration parsing is defensive
    // ----------------------------------------------------------------------

    public function test_a_single_proxy_is_accepted(): void
    {
        $this->assertSame(['10.0.0.5'], TrustedProxyConfig::proxies('10.0.0.5'));
        $this->assertTrue(TrustedProxyConfig::trusts(TrustedProxyConfig::proxies('10.0.0.5'), '10.0.0.5'));
        $this->assertFalse(TrustedProxyConfig::trusts(TrustedProxyConfig::proxies('10.0.0.5'), '10.0.0.6'));
    }

    public function test_a_list_is_split_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.5', '192.168.0.0/16'],
            TrustedProxyConfig::proxies(' 10.0.0.5 , 192.168.0.0/16 '),
        );
    }

    public function test_an_invalid_entry_is_dropped_rather_than_trusted(): void
    {
        // The typo must not become a trusted proxy, and it must not take the
        // site down either: the valid entries still apply.
        $this->assertSame(['10.0.0.5'], TrustedProxyConfig::proxies('10.0.0.5,not-an-ip'));
        $this->assertNull(TrustedProxyConfig::proxies('not-an-ip'), 'Nothing valid means nothing is trusted.');
        $this->assertNull(TrustedProxyConfig::proxies(''), 'An empty value means nothing is trusted.');
        $this->assertNull(TrustedProxyConfig::proxies('   '));
    }

    public function test_the_header_mask_maps_every_documented_token(): void
    {
        $mask = TrustedProxyConfig::headerMask('X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO');

        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
            $mask,
        );

        // `HEADER_X_FORWARDED_AWS_ELB` is a PRESET that overlaps the four
        // individual flags, so the summary lists it as a preset rather than as
        // a fifth header name; that is why the shipped default spec resolves to
        // four named headers plus the preset.
        $this->assertSame(
            ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'],
            TrustedProxyConfig::trustedHeaderNames($mask),
        );
        // The preset is a subset of the four flags, so a spec that names the
        // individual headers (which the shipped default also does) is reported
        // as those headers — not as the preset.
        $this->assertSame(
            ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port'],
            TrustedProxyConfig::trustedHeaderNames(TrustedProxyConfig::headerMask()),
        );

        // Named only when the preset IS the whole mask.
        $this->assertSame(
            ['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'AWS ELB preset'],
            TrustedProxyConfig::trustedHeaderNames(TrustedProxyConfig::headerMask('X_FORWARDED_AWS_ELB')),
        );
    }

    public function test_the_shipped_default_header_spec_is_still_parseable(): void
    {
        // config/trustedproxy.php defaults to the five standard tokens; a typo
        // there must fail loudly rather than boot with a mask of zero.
        $default = 'X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO|X_FORWARDED_AWS_ELB';

        $mask = TrustedProxyConfig::headerMask($default);

        $this->assertNotSame(0, $mask);
        $this->assertSame(
            Request::HEADER_X_FORWARDED_AWS_ELB,
            $mask & Request::HEADER_X_FORWARDED_AWS_ELB,
            'The AWS ELB preset the shipped config names must be part of the mask.',
        );
        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
            $mask,
            'The preset is a subset of the four flags, so the shipped default spec '
                .'resolves to the four standard headers.',
        );
    }

    public function test_an_unparseable_header_spec_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TrustedProxyConfig::headerMask('X-EVERYTHING|X-ANYTHING');
    }

    // ----------------------------------------------------------------------
    // 5. Production environment gate (both directions)
    // ----------------------------------------------------------------------

    public function test_the_shipped_env_template_is_rejected_by_the_production_gate(): void
    {
        $script = base_path('deploy/validate-env.py');

        if (! is_file($script)) {
            $this->markTestSkipped('deploy/validate-env.py is not present in this checkout.');
        }

        $output = [];
        $exit = 0;
        exec(
            escapeshellcmd(PHP_BINARY === '' ? 'python3' : 'python3')
            .' '.escapeshellarg($script)
            .' --env-file '.escapeshellarg(base_path('.env.example'))
            .' --production --no-process-env 2>&1',
            $output,
            $exit,
        );

        $this->assertNotSame(
            0,
            $exit,
            "The shipped template must NOT satisfy the production gate:\n".implode("\n", $output),
        );
        $this->assertStringContainsString('FAIL', implode("\n", $output));
    }

    public function test_a_fully_configured_production_env_passes_the_gate(): void
    {
        $script = base_path('deploy/validate-env.py');

        if (! is_file($script)) {
            $this->markTestSkipped('deploy/validate-env.py is not present in this checkout.');
        }

        // A complete, NON-SECRET production environment: placeholders that are
        // shaped like real values (never real credentials — this file is a
        // fixture). It proves the gate accepts a correct deployment rather
        // than rejecting everything.
        $env = [
            'APP_NAME="FF Arena"',
            'APP_ENV=production',
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG=false',
            'APP_URL=https://ffarena.example',
            'APP_TIMEZONE=Asia/Dhaka',
            'LOG_CHANNEL=stack',
            'LOG_LEVEL=warning',
            'DB_CONNECTION=pgsql',
            // The gate treats 127.0.0.1 as a placeholder for DB_HOST (a real
            // deployment points at a database host or socket), so the fixture
            // uses a private-network address.
            'DB_HOST=10.0.0.10',
            'DB_PORT=5432',
            'DB_DATABASE=ffarena',
            'DB_USERNAME=ffarena_app',
            'DB_PASSWORD=fixture-not-a-real-password',
            'SESSION_DRIVER=redis',
            'SESSION_SECURE_COOKIE=true',
            'SESSION_SAME_SITE=lax',
            'CACHE_STORE=redis',
            'QUEUE_CONNECTION=redis',
            'REDIS_HOST=127.0.0.1',
            'REDIS_PASSWORD=fixture-not-a-real-password',
            'MAIL_MAILER=smtp',
            'MAIL_HOST=smtp.example.com',
            'MAIL_PORT=587',
            'MAIL_USERNAME=fixture',
            'MAIL_PASSWORD=fixture-not-a-real-password',
            'MAIL_ENCRYPTION=tls',
            'MAIL_FROM_ADDRESS=noreply@ffarena.example',
            'BACKUP_DISK=local',
            'BACKUP_OFFSITE_DISK=backup-offsite',
            'BACKUP_OFFSITE_REQUIRED=true',
            'BACKUP_ENCRYPTION_RECIPIENT=age1fixture0000000000000000000000000000000000000000000000000000000',
            'WAL_ARCHIVE_ENABLED=true',
            'PAYMENT_WEBHOOK_SECRET=fixture-webhook-secret-not-real-0123456789abcdef',
            'SECURITY_CSP_ENABLE=true',
            'SECURITY_CSP_REPORT_ONLY=true',
            'SECURITY_CSP_POLICY=default-src \'self\'; script-src \'self\'; object-src \'none\'; frame-ancestors \'self\'',
            'BACKUP_OFFSITE_BUCKET=ffarena-backups',
            'BACKUP_OFFSITE_KEY=fixture-offsite-key-not-real',
            'BACKUP_OFFSITE_SECRET=fixture-offsite-secret-not-real',
            'METRICS_DRIVER=prometheus',
            'FEATURE_PROMETHEUS=true',
            'METRICS_SCRAPE_TOKEN=fixture-scrape-token-value',
            'TRUSTED_PROXIES=10.0.0.0/8',
            'TRUSTED_PROXY_HEADERS=X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO',
        ];

        $file = tempnam(sys_get_temp_dir(), 'ffarena-env-');
        file_put_contents($file, implode("\n", $env)."\n");

        try {
            $output = [];
            $exit = 0;
            exec(
                'python3 '.escapeshellarg($script)
                .' --env-file '.escapeshellarg($file)
                .' --production --no-process-env 2>&1',
                $output,
                $exit,
            );

            $this->assertSame(
                0,
                $exit,
                "A complete production environment was rejected by the gate:\n".implode("\n", $output),
            );
            $this->assertStringContainsString('PASS', implode("\n", $output));
        } finally {
            @unlink($file);
        }
    }

    // ----------------------------------------------------------------------
    // 6. Debug output never leaks from a production-shaped application
    // ----------------------------------------------------------------------

    public function test_debug_output_is_disabled_under_the_test_profile(): void
    {
        // The same configuration a production deployment must set. A stray
        // APP_DEBUG=true prints stack traces, file paths and SQL to the
        // internet; the gate above fails a production env that sets it, and
        // this asserts the running configuration is not in that state either.
        $this->assertFalse((bool) config('app.debug'), 'APP_DEBUG must be false outside local development.');
    }

    public function test_trusted_proxy_summary_names_the_trusted_headers(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.0/8,192.168.0.0/16');

        $summary = TrustedProxyConfig::describe();

        $this->assertStringContainsString('10.0.0.0/8', $summary);
        $this->assertStringContainsString('192.168.0.0/16', $summary);
        $this->assertStringContainsString('X-Forwarded-For', $summary);
    }
}
