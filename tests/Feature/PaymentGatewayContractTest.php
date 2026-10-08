<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\PaymentGatewayManager;
use App\Support\CacheKeys;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Providers gap (2026-10-07): gateway registry contract.
 *
 * Every registered provider must honor its capability contract: hosted
 * gateways are server-queryable exactly when they claim callbacks,
 * manual methods stay honest (pending, no redirect, no fabricated
 * refunds), and `configured()` must reflect real credentials — never
 * report a provider as live when it cannot operate.
 */
class PaymentGatewayContractTest extends TestCase
{
    use RefreshDatabase;

    protected function manager(): PaymentGatewayManager
    {
        return app(PaymentGatewayManager::class);
    }

    /**
     * Pin every credential to absent so the honesty assertions hold under
     * any ambient .env (a developer or CI .env must never flip them).
     */
    protected function stripCredentials(): void
    {
        config([
            'payments.providers.bkash.app_key' => null,
            'payments.providers.bkash.app_secret' => null,
            'payments.providers.bkash.username' => null,
            'payments.providers.bkash.password' => null,
            'payments.providers.nagad.merchant_id' => null,
            'payments.providers.nagad.merchant_private_key' => null,
            'payments.providers.nagad.pg_public_key' => null,
            'payments.providers.rocket.merchant_id' => null,
            'payments.providers.rocket.merchant_secret' => null,
            'payments.providers.card.merchant_id' => null,
            'payments.providers.card.merchant_secret' => null,
            'payments.providers.bank.account_name' => null,
            'payments.providers.bank.account_number' => null,
            'payments.providers.sslcommerz.store_id' => null,
            'payments.providers.sslcommerz.store_password' => null,
        ]);
        Cache::forget(CacheKeys::PAYMENT_PROVIDER_STATUSES);
    }

    public function test_registry_lists_all_six_providers(): void
    {
        $this->assertEqualsCanonicalizing(
            ['bkash', 'nagad', 'rocket', 'card', 'bank', 'sslcommerz'],
            $this->manager()->providers()
        );
    }

    public function test_every_gateway_honors_the_interface(): void
    {
        foreach ($this->manager()->providers() as $id) {
            $gateway = $this->manager()->gateway($id);

            $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
            $this->assertSame($id, $gateway->id());
            $this->assertNotSame('', $gateway->label());
            $this->assertIsBool($gateway->supportsCallbacks());
            $this->assertIsBool($gateway->supportsRefunds());
            $this->assertIsBool($gateway->configured());
        }

        try {
            $this->manager()->gateway('paypal');
            $this->fail('Resolving an unknown provider must throw.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_queryable_exactly_matches_callback_capability(): void
    {
        $queryable = [];

        foreach ($this->manager()->providers() as $id) {
            if ($this->manager()->gateway($id) instanceof PaymentStatusQueryable) {
                $queryable[] = $id;
            }
        }

        // Hosted gateways are queryable; manual methods (Rocket, bank) are
        // not — the payer-return controller relies on exactly this split.
        $this->assertEqualsCanonicalizing(['bkash', 'nagad', 'card', 'sslcommerz'], $queryable);
    }

    public function test_manual_methods_stay_pending_without_redirect(): void
    {
        foreach (['rocket', 'bank'] as $id) {
            $payment = new Payment();
            $payment->provider_reference = 'MANUAL1';

            $result = $this->manager()->gateway($id)->createExternalPayment($payment);

            $this->assertSame(Payment::STATUS_PENDING, $result['status']);
            $this->assertNull($result['redirect_url']);
            $this->assertSame('MANUAL1', $result['provider_reference']);
        }
    }

    public function test_manual_methods_never_fabricate_refunds(): void
    {
        foreach (['rocket', 'bank'] as $id) {
            try {
                $this->manager()->gateway($id)->refundExternal(new Payment(), new Refund());
                $this->fail($id.' must not fabricate an external refund.');
            } catch (DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_configured_is_false_without_credentials(): void
    {
        $this->stripCredentials();

        $manager = $this->manager();

        $this->assertFalse($manager->gateway('bkash')->configured());
        $this->assertFalse($manager->gateway('nagad')->configured());
        $this->assertFalse($manager->gateway('rocket')->configured());
        $this->assertFalse($manager->gateway('bank')->configured());
        $this->assertFalse($manager->gateway('card')->configured());
        $this->assertFalse($manager->gateway('sslcommerz')->configured());
    }

    public function test_configured_turns_true_with_credentials(): void
    {
        $this->stripCredentials();

        config([
            'payments.providers.bkash.enabled' => true,
            'payments.providers.bkash.app_key' => 'k',
            'payments.providers.bkash.app_secret' => 's',
            'payments.providers.bkash.username' => 'u',
            'payments.providers.bkash.password' => 'p',
            'payments.providers.rocket.enabled' => true,
            'payments.providers.rocket.merchant_id' => 'm',
            'payments.providers.rocket.merchant_secret' => 's',
            'payments.providers.bank.enabled' => true,
            'payments.providers.bank.account_name' => 'FF Arena',
            'payments.providers.bank.account_number' => '123456',
        ]);

        $manager = $this->manager();

        $this->assertTrue($manager->gateway('bkash')->configured());
        $this->assertTrue($manager->gateway('rocket')->configured());
        $this->assertTrue($manager->gateway('bank')->configured());
    }

    public function test_statuses_expose_the_full_capability_shape(): void
    {
        $this->stripCredentials();

        foreach ($this->manager()->statuses() as $status) {
            foreach (['id', 'label', 'enabled', 'configured', 'mode', 'supports_callbacks', 'supports_refunds'] as $key) {
                $this->assertArrayHasKey($key, $status);
            }

            $this->assertContains($status['mode'], ['sandbox', 'production', 'manual']);
        }
    }

    public function test_enabled_providers_respects_the_flag(): void
    {
        $this->stripCredentials();

        config(['payments.providers.card.enabled' => false]);
        Cache::forget(CacheKeys::PAYMENT_PROVIDER_STATUSES);

        $ids = array_column($this->manager()->enabledProviders(), 'id');
        $this->assertNotContains('card', $ids);

        config(['payments.providers.card.enabled' => true]);
        Cache::forget(CacheKeys::PAYMENT_PROVIDER_STATUSES);

        $ids = array_column($this->manager()->enabledProviders(), 'id');
        $this->assertContains('card', $ids);
    }
}
