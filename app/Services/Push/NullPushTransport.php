<?php

namespace App\Services\Push;

/**
 * Phase 19 — the honest "push is not configured" transport.
 *
 * When no push provider has credentials, the dispatcher still exists but
 * reports `isConfigured() === false` and never attempts delivery. Push is
 * disabled honestly; in-app and email notifications are unaffected.
 */
final class NullPushTransport implements PushTransport
{
    public function provider(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(PushMessage $message, string $token): PushResult
    {
        return PushResult::failed('push_unconfigured');
    }
}
