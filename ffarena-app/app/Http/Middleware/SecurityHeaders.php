<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16 — baseline HTTP security headers.
 *
 * Sets conservative, universally-safe headers on every response. HSTS is
 * only emitted over HTTPS (or when explicitly forced); CSP is opt-in via
 * config so it can be rolled out without breaking the existing UI.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->apply($request, $response);

        return $response;
    }

    protected function apply(Request $request, Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if (config('observability.security_headers.hsts_enable', true) && $request->isSecure()) {
            $maxAge = (int) config('observability.security_headers.hsts_max_age', 31536000);
            $hsts = 'max-age='.$maxAge;

            if (config('observability.security_headers.hsts_include_subdomains', false)) {
                $hsts .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        if (config('observability.security_headers.csp_enable', false)) {
            $response->headers->set('Content-Security-Policy', (string) config('observability.security_headers.csp_policy'));
        }
    }
}
