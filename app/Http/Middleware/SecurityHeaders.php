<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        // HSTS - HTTP Strict Transport Security with preload
        // max-age=31536000 = 1 year, includeSubDomains, preload for HSTS preload list
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking - DENY all framing
        $response->headers->set('X-Frame-Options', 'DENY');

        // Disable XSS auditor (legacy, use CSP instead)
        $response->headers->set('X-XSS-Protection', '0');

        // Referrer policy - no referrer
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Content Security Policy with dynamic nonce support
        // default-src 'none' - block all by default
        // script-src with nonce for inline scripts that need it
        // frame-ancestors 'none' - prevent framing (similar to X-Frame-Options DENY but CSP level)
        // base-uri 'none' - prevent base tag injection
        // form-action 'none' - prevent form hijacking (adjust as needed for payment forms)
        $csp = $this->buildCspHeader($nonce, $request);
        $response->headers->set('Content-Security-Policy', $csp);

        // Additional CSP for older browsers or reporting
        // $response->headers->set('X-Content-Security-Policy', $csp);

        // Prevent Adobe products from loading data
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Cross-Origin policies
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Permissions Policy - disable sensitive features
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), speaker=()');

        // Cache control for sensitive endpoints
        if ($request->is('api/*') && $request->isMethod('GET')) {
            // For API GET, allow caching but with validation
            // For sensitive data, use no-store
            if ($request->is('api/*/payments/*') || $request->is('api/*/wallets/*')) {
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
            }
        }

        if ($request->is('api/*') && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        // Remove server information
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Add request ID if not already set
        if (!$response->headers->has('X-Request-ID')) {
            $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();
            $response->headers->set('X-Request-ID', $requestId);
        }

        // Add trace ID if available
        if ($request->attributes->has('trace_id')) {
            $response->headers->set('X-Trace-ID', $request->attributes->get('trace_id'));
        }

        // HSTS preload compliance - ensure header is present for preload submission
        // Requirements for hstspreload.org:
        // - max-age >= 31536000
        // - includeSubDomains
        // - preload
        // - Served over HTTPS
        // - Redirect from HTTP to HTTPS on same host

        return $response;
    }

    protected function buildCspHeader(string $nonce, Request $request): string
    {
        // For API endpoints, strict CSP
        if ($request->is('api/*')) {
            return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        }

        // For web endpoints, allow self with nonce for scripts and styles
        // Adjust based on your frontend needs
        $isDev = app()->environment('local', 'development');

        if ($isDev) {
            // In development, allow unsafe-eval for Vite HMR and self
            return implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
                "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self' ws: wss: http://localhost:* https://localhost:*",
                "frame-ancestors 'none'",
                "base-uri 'none'",
                "form-action 'self'",
            ]);
        }

        // Production CSP - strict with nonce
        return implode('; ', [
            "default-src 'none'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' https://api.ffarena.com https://payment.ffarena.com",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'self'",
        ]);
    }
}
