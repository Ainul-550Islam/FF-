<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Phase 16 — last-line defence against credential leakage in logs.
 *
 * Recursively scrubs records for well-known sensitive keys and value shapes
 * (bearer tokens, "password", "secret", OTP codes, authorization headers…)
 * and replaces them with "[REDACTED]". It runs on every structured channel so
 * a stray context array can never persist a secret.
 */
class RedactSensitiveDataProcessor implements ProcessorInterface
{
    /**
     * Keys (or substrings) whose values are always scrubbed.
     *
     * @var array<int, string>
     */
    protected array $sensitiveKeys = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'bearer',
        'otp',
        'verification_code',
        'access_token',
        'refresh_token',
        'client_secret',
        'webhook_secret',
        'private_key',
        'app_key',
        'session_id',
        'credit_card',
        'card_number',
        'cvv',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactValue($record->context),
            extra: $this->redactValue($record->extra),
        );
    }

    protected function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $value[$key] = '[REDACTED]';

                    continue;
                }

                $value[$key] = $this->redactValue($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        return $value;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' ', '.'], '_', $key));

        foreach ($this->sensitiveKeys as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function redactString(string $value): string
    {
        // "Bearer <token>" (HTTP Authorization header values).
        $value = preg_replace('/\bbearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'bearer [REDACTED]', $value);

        // "password=<anything until whitespace/comma>".
        $value = preg_replace('/\b(password|passwd|secret|api[_-]?key|token)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $value);

        return (string) $value;
    }
}
