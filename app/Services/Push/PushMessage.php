<?php

namespace App\Services\Push;

/**
 * Phase 19 — a ready-to-deliver push message.
 *
 * Titles and bodies are already redacted by PushPayloadBuilder; this value
 * object must never carry passwords, OTP codes, tokens, risk scores, raw
 * financial figures or any other sensitive material.
 */
final class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $category,
        public readonly string $priority, // 'high' | 'normal'
        public readonly array $data,
    ) {}

    /**
     * The safe, structured data payload (notification id, type, entity and
     * deep link) delivered alongside the alert. Never contains secrets.
     */
    public function data(): array
    {
        return $this->data;
    }
}
