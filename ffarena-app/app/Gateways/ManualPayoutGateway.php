<?php

namespace App\Gateways;

use App\Contracts\PayoutGatewayInterface;
use App\Models\Payout;
use DomainException;

/**
 * Manual payout adapter (Phase 09).
 *
 * For payouts settled outside the platform (bank transfer, bKash Send Money
 * by an agent, etc.) where no API integration exists. The application never
 * fakes a successful external transfer: payouts stay in `processing` until an
 * admin manually marks them completed (recording the external reference).
 */
class ManualPayoutGateway implements PayoutGatewayInterface
{
    public function id(): string
    {
        return 'manual';
    }

    public function isInternal(): bool
    {
        return false;
    }

    public function supportsExternal(): bool
    {
        return false;
    }

    public function disburseExternal(Payout $payout): array
    {
        throw new DomainException('Manual payouts have no external API — complete them by hand and record the reference.');
    }
}
