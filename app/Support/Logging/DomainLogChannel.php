<?php

namespace App\Support\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Phase 16 — builds the per-domain log channel definitions (security,
 * payments, webhooks, queue, audit, errors, metrics). Kept as a plain
 * array-returning helper so config/logging.php stays closure-free and
 * `php artisan config:cache` remains safe.
 */
final class DomainLogChannel
{
    /**
     * @return array<string, mixed>
     */
    public static function config(string $name): array
    {
        return [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/'.$name.'.log'),
                'maxFiles' => (int) env('LOG_DAILY_DAYS', 14),
            ],
            'formatter' => LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ];
    }
}
