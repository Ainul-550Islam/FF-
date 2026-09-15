<?php

namespace App\Services;

use App\Contracts\PayoutGatewayInterface;
use App\Gateways\ManualPayoutGateway;
use App\Gateways\WalletPayoutGateway;
use DomainException;

/**
 * Resolves payout gateway adapters by provider id (Phase 09).
 */
class PayoutGatewayManager
{
    /**
     * Registered adapters, keyed by provider id.
     *
     * @var array<string, PayoutGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct(WalletPayoutGateway $wallet, ManualPayoutGateway $manual)
    {
        $this->gateways[$wallet->id()] = $wallet;
        $this->gateways[$manual->id()] = $manual;
    }

    /**
     * Resolve a gateway by provider id.
     */
    public function gateway(string $provider): PayoutGatewayInterface
    {
        if (! isset($this->gateways[$provider])) {
            throw new DomainException("Unknown payout provider: {$provider}");
        }

        return $this->gateways[$provider];
    }

    /**
     * The default provider id used for prize payouts.
     */
    public function defaultProvider(): string
    {
        return (string) config('finance.default_payout_provider', 'wallet');
    }
}
