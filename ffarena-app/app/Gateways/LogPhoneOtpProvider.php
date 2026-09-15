<?php

namespace App\Gateways;

use App\Contracts\PhoneOtpProviderInterface;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Development/test OTP provider (Phase 14).
 *
 * Writes the code to the application log so local flows can be exercised
 * end-to-end without an SMS gateway. Configured only in non-production
 * environments; in production the SMS gateway provider is required.
 */
class LogPhoneOtpProvider implements PhoneOtpProviderInterface
{
    public function id(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return ! app()->environment('production');
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        Log::info('Phone OTP issued', ['phone' => $phone, 'code' => $code]);
    }
}
