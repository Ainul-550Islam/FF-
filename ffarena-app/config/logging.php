<?php

use App\Support\Logging\DomainLogChannel;
use App\Support\Logging\RedactSensitiveDataProcessor;
use App\Support\Logging\RequestContextProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Phase 16 logging layout
|--------------------------------------------------------------------------
|
| Every file channel is defined on the "monolog" driver so it can carry the
| security processors on every line:
|
|   PsrLogMessageProcessor        — interpolates {placeholders} (Laravel default)
|   RequestContextProcessor       — attaches request_id / route / method / ids
|   RedactSensitiveDataProcessor  — scrubs secrets as a last-line defence
|
| Channel names `single` and `daily` are preserved, so existing
| LOG_CHANNEL/LOG_STACK values keep working unchanged. Dedicated channels
| (security, payments, webhooks, queue, audit, errors, metrics) each rotate
| daily into their own file — logs are separated by domain, not duplicated
| into every file.
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/laravel.log'),
            ],
            'formatter' => LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'daily' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/laravel.log'),
                'maxFiles' => (int) env('LOG_DAILY_DAYS', 14),
            ],
            'formatter' => LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        // Structured JSON-lines stream for the metrics/observability stack.
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/ffarena.jsonl'),
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [JsonFormatter::BATCH_MODE_JSON, true, false, false],
            'processors' => [
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER') ?: LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |------------------------------------------------------------------
        | Phase 16 — domain-separated channels
        |------------------------------------------------------------------
        | Each writes to its own daily-rotated file and carries the
        | correlation + redaction processors. Callers use them explicitly
        | (e.g. Log::channel('security')->warning(...)).
        */
        'security' => DomainLogChannel::config('security'),
        'payments' => DomainLogChannel::config('payments'),
        'webhooks' => DomainLogChannel::config('webhooks'),
        'queue' => DomainLogChannel::config('queue'),
        'audit' => DomainLogChannel::config('audit'),
        'errors' => DomainLogChannel::config('errors'),
        'metrics' => DomainLogChannel::config('metrics'),
    ],

];
