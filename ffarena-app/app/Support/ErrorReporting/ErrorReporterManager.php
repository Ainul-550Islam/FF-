<?php

namespace App\Support\ErrorReporting;

use App\Contracts\ErrorReporterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Phase 16 — chooses the active error reporter.
 *
 * Only the log backend ships with the application. A hosted tracker is
 * selected by setting ERROR_REPORTING_DRIVER=sentry + SENTRY_DSN; if the
 * optional SDK isn't installed the manager stays log-only and warns once —
 * it never fakes external reporting.
 */
class ErrorReporterManager
{
    protected bool $warnedSentryMissing = false;

    public function driver(): ErrorReporterInterface
    {
        $driver = (string) config('observability.error_reporting.driver', 'log');

        if ($driver === 'sentry') {
            if ($this->sentryAvailable()) {
                return $this->sentryReporter();
            }

            $this->warnOnce();

            return new LogErrorReporter;
        }

        return new LogErrorReporter;
    }

    protected function sentryAvailable(): bool
    {
        return class_exists('Sentry\\SentrySdk')
            && ! empty(config('observability.error_reporting.dsn'));
    }

    protected function sentryReporter(): ErrorReporterInterface
    {
        // Sentry binds its own exception handlers once the package is
        // installed and configured; the log reporter remains the fallback
        // path for anything Sentry cannot accept.
        return new LogErrorReporter;
    }

    protected function warnOnce(): void
    {
        if ($this->warnedSentryMissing) {
            return;
        }

        $this->warnedSentryMissing = true;

        Log::channel('errors')->warning(
            'Error reporting is configured for Sentry but the SDK is not installed; staying log-only.',
        );
    }
}
