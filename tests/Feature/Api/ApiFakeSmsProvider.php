<?php

namespace Tests\Feature\Api;

use App\Contracts\PhoneOtpProviderInterface;

/**
 * Deterministic Phase 15 test double. Never touches the network — records the
 * code in memory so the OTP flow can be exercised end-to-end.
 */
class ApiFakeSmsProvider implements PhoneOtpProviderInterface
{
    /** @var array<string, string> phone => last code */
    public array $codes = [];

    public function id(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $phone, string $code): void
    {
        $this->codes[$phone] = $code;
    }

    public function lastCodeFor(string $phone): ?string
    {
        return $this->codes[$phone] ?? null;
    }
}
