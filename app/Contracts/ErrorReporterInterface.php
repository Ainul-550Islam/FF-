<?php

namespace App\Contracts;

use Throwable;

/**
 * Phase 16 — provider-neutral error reporting.
 *
 * The only seam the application talks to when surfacing an exception or an
 * operational error. The default implementation is log-only; a hosted error
 * tracker (e.g. Sentry) can be plugged in later without touching call sites.
 *
 * Implementations MUST NOT throw: reporting must never take down the request
 * that triggered it, and MUST redact secrets before persisting anything.
 */
interface ErrorReporterInterface
{
    /**
     * Report a throwable with safe, already-redacted context.
     *
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void;

    /**
     * Report a standalone message (e.g. a recovered failure that never threw).
     *
     * @param  array<string, mixed>  $context
     */
    public function captureMessage(string $message, string $level = 'error', array $context = []): void;

    /**
     * Whether a real external sink is active. Log-only reporters return true
     * (logging is always available) — used by call sites to avoid
     * double-reporting.
     */
    public function isConfigured(): bool;
}
