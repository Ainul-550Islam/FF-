<?php

namespace App\Services\Push;

/**
 * Phase 19 — the outcome of a single push send attempt.
 *
 * Transports never throw into the dispatcher; they always return a result.
 * `invalidToken` tells the dispatcher the device registration is dead and
 * should be deactivated. `retryable` signals a transient/provider failure
 * that a queue worker may retry.
 */
final class PushResult
{
    public function __construct(
        public readonly bool $ok = false,
        public readonly bool $invalidToken = false,
        public readonly bool $retryable = false,
        public readonly string $reason = '',
    ) {}

    public static function delivered(): self
    {
        return new self(ok: true);
    }

    public static function invalidToken(string $reason = 'unregistered'): self
    {
        return new self(invalidToken: true, reason: $reason);
    }

    public static function failed(string $reason, bool $retryable = false): self
    {
        return new self(retryable: $retryable, reason: $reason);
    }
}
