<?php

namespace App\Gateways;

use App\Contracts\PhoneOtpProviderInterface;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Production SMS-gateway OTP provider (Phase 14).
 *
 * Delivers codes through a generic HTTP SMS gateway configured by
 * environment variables. `isConfigured()` is true only when an endpoint, API
 * key and sender id are present; `send()` refuses to run otherwise, so the
 * platform can never pretend an SMS was delivered.
 */
class SmsGatewayPhoneOtpProvider implements PhoneOtpProviderInterface
{
    public function id(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return ! empty(env('SMS_GATEWAY_ENDPOINT'))
            && ! empty(env('SMS_GATEWAY_API_KEY'))
            && ! empty(env('SMS_GATEWAY_SENDER'));
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withToken((string) env('SMS_GATEWAY_API_KEY'))
            ->post((string) env('SMS_GATEWAY_ENDPOINT'), [
                'to' => $phone,
                'from' => (string) env('SMS_GATEWAY_SENDER'),
                'text' => 'Your FF Arena verification code is '.$code.'. It expires in 5 minutes.',
            ]);

        if ($response->failed()) {
            throw new DomainException('The SMS gateway rejected the delivery.');
        }
    }
}
