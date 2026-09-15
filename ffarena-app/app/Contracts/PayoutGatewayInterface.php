<?php

namespace App\Contracts;

use App\Models\Payout;
use DomainException;

/**
 * Provider abstraction for prize payout execution (Phase 09).
 *
 * The application never fakes a successful external payout: adapters must
 * honestly report what they can and cannot do. Today the only shipped
 * provider is the internal wallet; external (manual) processing is supported
 * as a safe pending/manual workflow with no invented success.
 */
interface PayoutGatewayInterface
{
    /**
     * The stable provider identifier (stored on payouts.provider).
     */
    public function id(): string;

    /**
     * Whether this provider settles payouts internally (credits the
     * recipient's wallet via WalletService). Internal payouts complete
     * atomically during processing and need no manual confirmation.
     */
    public function isInternal(): bool;

    /**
     * Whether this provider can execute external (out-of-platform)
     * disbursements.
     */
    public function supportsExternal(): bool;

    /**
     * Execute an external disbursement for the given payout.
     *
     * @return array{status: string, provider_reference: ?string}
     *
     * @throws DomainException when external disbursement is not supported
     *                          (e.g. no live credentials or manual flow).
     */
    public function disburseExternal(Payout $payout): array;
}
