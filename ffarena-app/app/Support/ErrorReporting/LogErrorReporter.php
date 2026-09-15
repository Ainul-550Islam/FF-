<?php

namespace App\Support\ErrorReporting;

use App\Contracts\ErrorReporterInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 16 — the always-available, vendor-free error reporter.
 *
 * Writes a redacted, structured error line to the `errors` log channel with
 * the request correlation context. It is also the safe fallback when a hosted
 * tracker is requested but not configured, so the application is never left
 * without an error trail.
 */
class LogErrorReporter implements ErrorReporterInterface
{
    public function __construct(protected string $channel = 'errors') {}

    public function report(Throwable $exception, array $context = []): void
    {
        Log::channel($this->channel)->error($exception->getMessage(), array_merge([
            'exception' => $exception::class,
            'code' => $exception->getCode(),
            'file' => $this->relativePath($exception->getFile()).':'.$exception->getLine(),
        ], $context));
    }

    public function captureMessage(string $message, string $level = 'error', array $context = []): void
    {
        Log::channel($this->channel)->log($level, $message, $context);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Strip the machine-specific base path so log lines don't leak the
     * deployment layout.
     */
    protected function relativePath(string $file): string
    {
        $base = base_path();

        return str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/') : $file;
    }
}
