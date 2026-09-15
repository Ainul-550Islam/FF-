<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Gateways\BankGateway;
use App\Gateways\BkashGateway;
use App\Gateways\CardGateway;
use App\Gateways\NagadGateway;
use App\Gateways\RocketGateway;
use App\Gateways\SslCommerzGateway;
use App\Support\CacheKeys;
use DomainException;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves payment gateway adapters by provider id (Phase 08, extended
 * Phase 14 with bKash/Nagad/Rocket/card/bank/SSLCommerz adapters).
 *
 * Also reports honest per-provider status (configured/disabled/mode) for the
 * checkout UI, so a provider without credentials is never presented as live.
 */
class PaymentGatewayManager
{
    /**
     * Registered adapters, keyed by provider id.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct(
        BkashGateway $bkash,
        NagadGateway $nagad,
        RocketGateway $rocket,
        CardGateway $card,
        BankGateway $bank,
        SslCommerzGateway $sslcommerz,
    ) {
        foreach ([$bkash, $nagad, $rocket, $card, $bank, $sslcommerz] as $gateway) {
            $this->gateways[$gateway->id()] = $gateway;
        }
    }

    /**
     * Resolve a gateway by provider id.
     */
    public function gateway(string $provider): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$provider])) {
            throw new DomainException("Unknown payment provider: {$provider}");
        }

        return $this->gateways[$provider];
    }

    /**
     * The default provider id used for manual entry-fee payments.
     */
    public function defaultProvider(): string
    {
        return 'bkash';
    }

    /**
     * All registered provider ids.
     *
     * @return string[]
     */
    public function providers(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * Honest status snapshot for every provider (for the checkout UI and the
     * admin provider-configuration view).
     *
     * Phase 16 — cached for 60 seconds (cheap to rebuild; per-provider
     * `configured()` checks hit config only). Invalidation happens through
     * CacheInvalidationService::invalidateProviderStatuses().
     *
     * @return array<int, array{id: string, label: string, enabled: bool, configured: bool, mode: string, supports_callbacks: bool, supports_refunds: bool}>
     */
    public function statuses(): array
    {
        try {
            $cached = Cache::get(CacheKeys::PAYMENT_PROVIDER_STATUSES);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Never let a cache outage break checkout.
        }

        $statuses = $this->buildStatuses();

        try {
            Cache::put(CacheKeys::PAYMENT_PROVIDER_STATUSES, $statuses, 60);
        } catch (\Throwable) {
            // Non-authoritative cache — best-effort.
        }

        return $statuses;
    }

    /**
     * @return array<int, array{id: string, label: string, enabled: bool, configured: bool, mode: string, supports_callbacks: bool, supports_refunds: bool}>
     */
    protected function buildStatuses(): array
    {
        $statuses = [];

        foreach ($this->gateways as $id => $gateway) {
            $config = (array) config("payments.providers.{$id}", []);

            $statuses[] = [
                'id' => $id,
                'label' => $gateway->label(),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'configured' => $gateway->configured(),
                'mode' => (string) ($config['mode'] ?? 'sandbox'),
                'supports_callbacks' => $gateway->supportsCallbacks(),
                'supports_refunds' => $gateway->supportsRefunds(),
            ];
        }

        return $statuses;
    }

    /**
     * Providers that are enabled and may be offered at checkout.
     *
     * @return array<int, array{id: string, label: string, enabled: bool, configured: bool, mode: string, supports_callbacks: bool, supports_refunds: bool}>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(
            $this->statuses(),
            fn (array $status) => $status['enabled'],
        ));
    }
}
