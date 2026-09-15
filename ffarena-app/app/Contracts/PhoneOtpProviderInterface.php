<?php

namespace App\Contracts;

use DomainException;

/**
 * Provider abstraction for delivering phone OTP codes (Phase 14).
 *
 * The application never fabricates delivery: adapters honestly report whether
 * they are configured. In development/test the log provider simply records
 * the code to the application log (and the test provider returns it
 * deterministically); in production a real SMS gateway is required and
 * `send()` throws until it is configured.
 */
interface PhoneOtpProviderInterface
{
    /**
     * The stable provider identifier.
     */
    public function id(): string;

    /**
     * Whether this provider can actually deliver codes.
     */
    public function isConfigured(): bool;

    /**
     * Deliver a code to a phone number. Best-effort by contract: callers use
     * OtpService which never lets a delivery failure break the flow.
     *
     * @throws DomainException when the provider is not configured.
     */
    public function send(string $phone, string $code): void;
}
