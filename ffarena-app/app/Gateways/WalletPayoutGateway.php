<?php

namespace App\Gateways;

use App\Contracts\PayoutGatewayInterface;
use App\Models\Payout;
use DomainException;

/**
 * Internal wallet payout adapter (Phase 09).
 *
 * Prizes are settled by crediting the recipient's wallet through
 * WalletService (which writes the immutable ledger entry) inside the payout
 * transaction. There is no external network call, so nothing here can fail
 * part-way and no external success is ever invented.
 */
class WalletPayoutGateway implements PayoutGatewayInterface
{
    public function id(): string
    {
        return 'wallet';
    }

    public function isInternal(): bool
    {
        return true;
    }

    public function supportsExternal(): bool
    {
        return false;
    }

    public function disburseExternal(Payout $payout): array
    {
        throw new DomainException('The wallet provider settles payouts internally and has no external disbursement step.');
    }
}
