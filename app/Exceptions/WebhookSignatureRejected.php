<?php

namespace App\Exceptions;

use DomainException;

/**
 * GAP-10 A5 — a provider webhook was refused because its signature did not
 * verify (or its signed timestamp fell outside the tolerance window).
 *
 * The failure is a *signature* failure, which the two inbound surfaces report
 * differently because each has its own published contract:
 *
 *  - `POST /api/v1/webhooks/inbound/{provider}` (Phase 15) answers `401` with
 *    `error.code = webhook_rejected`, alongside every other authentication
 *    failure on that surface.
 *  - `POST /webhooks/payments/{provider}` (Phase 08 legacy) answers `400`,
 *    which is what the payments integration and `PaymentSecurityTest`
 *    expect for a forgeries attempt.
 *
 * Both are refusals — nothing is settled, and the rejected attempt is recorded
 * as a WebhookEvent — so the difference is purely presentational. Carrying the
 * distinction in a dedicated exception type keeps the ingress single-sourced
 * without either controller guessing from a message string.
 */
class WebhookSignatureRejected extends DomainException
{
    /**
     * HTTP status for the legacy Phase 08 webhook endpoint.
     */
    public const LEGACY_STATUS = 400;

    /**
     * HTTP status for the Phase 15 API webhook endpoint.
     */
    public const API_STATUS = 401;

    public function __construct(string $message = 'Invalid webhook signature.')
    {
        parent::__construct($message, self::API_STATUS);
    }
}
