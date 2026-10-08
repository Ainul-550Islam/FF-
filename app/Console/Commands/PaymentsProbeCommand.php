<?php

namespace App\Console\Commands;

use App\Services\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Providers gap (2026-10-07): payment pre-flight probe.
 *
 * Reports every registered provider's deploy readiness — enabled flag,
 * mode, credential presence (never values), callback/refund capability —
 * and fails on structural misconfiguration (bad mode, bad base URL,
 * missing return route). With --ping it also TLS-probes each provider
 * base URL: routability only, no credentials are used and no money moves.
 *
 * Intended as a non-blocking deploy-gate step and as the first page of
 * docs/PROVIDER_PILOT.md onboarding.
 */
class PaymentsProbeCommand extends Command
{
    protected $signature = 'payments:probe {--ping : TLS-probe each provider base URL (short timeout, no credentials, no money movement)}';

    protected $description = 'Report payment provider deploy readiness (config sanity, never secrets)';

    /**
     * Credential keys whose *presence* is reported per provider.
     *
     * @var array<string, string[]>
     */
    protected const SECRET_KEYS = [
        'bkash' => ['app_key', 'app_secret', 'username', 'password'],
        'nagad' => ['merchant_id', 'merchant_private_key', 'pg_public_key'],
        'rocket' => ['merchant_id', 'merchant_secret'],
        'card' => ['merchant_id', 'merchant_secret'],
        'bank' => ['account_name', 'account_number'],
        'sslcommerz' => ['store_id', 'store_password'],
    ];

    public function handle(PaymentGatewayManager $gateways): int
    {
        $problems = 0;
        $rows = [];

        foreach ($gateways->providers() as $id) {
            $gateway = $gateways->gateway($id);
            $config = (array) config('payments.providers.'.$id, []);
            $enabled = (bool) ($config['enabled'] ?? true);
            $mode = (string) ($config['mode'] ?? 'sandbox');
            $baseUrl = (string) ($config['base_url'] ?? '');

            $expectedModes = $id === 'bank' ? ['manual'] : ['sandbox', 'production'];
            if (! in_array($mode, $expectedModes, true)) {
                $this->error('['.$id.'] invalid mode "'.$mode.'" — expected '.implode('|', $expectedModes).'.');
                $problems++;
            }

            if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
                $this->error('['.$id.'] base_url is not a valid URL.');
                $problems++;
            }

            $keys = self::SECRET_KEYS[$id] ?? [];
            $set = 0;
            foreach ($keys as $key) {
                if (! empty($config[$key])) {
                    $set++;
                }
            }

            $ping = '—';
            if ($this->option('ping')) {
                $ping = $baseUrl === '' ? 'no base_url' : $this->tlsProbe($baseUrl);
            }

            $rows[] = [
                $id,
                $enabled ? 'yes' : 'no',
                $mode,
                $gateway->configured() ? 'yes' : 'no',
                $gateway->supportsCallbacks() ? 'yes' : 'no',
                $gateway->supportsRefunds() ? 'yes' : 'no',
                $set.'/'.count($keys).' set',
                $ping,
            ];

            if ($enabled && ! $gateway->configured()) {
                $this->warn('['.$id.'] enabled but not configured — checkout falls back to the manual TrxID flow.');
            }
        }

        $this->table(
            ['Provider', 'Enabled', 'Mode', 'Configured', 'Callbacks', 'Refunds', 'Secrets', 'Ping'],
            $rows
        );

        if (! Route::has('payments.callback')) {
            $this->error('Route [payments.callback] is missing — hosted payer returns have nowhere to land.');
            $problems++;
        }

        $inbound = (array) config('webhooks.inbound.providers', []);
        if ($inbound === []) {
            $this->warn('No webhooks.inbound.providers allowlist — inbound provider webhooks fall back to the shared secret.');
        } else {
            $this->info('Inbound webhook allowlist: '.implode(', ', array_keys($inbound)).'.');
        }

        if ($problems > 0) {
            $this->error('Probe found '.$problems.' structural problem(s).');

            return self::FAILURE;
        }

        $this->info('Probe passed: provider configuration is structurally sound.');

        return self::SUCCESS;
    }

    /**
     * TCP+TLS handshake only — proves DNS, egress and certificate trust for
     * the host without sending credentials or touching any API.
     */
    protected function tlsProbe(string $baseUrl): string
    {
        $parts = (array) parse_url($baseUrl);
        $host = (string) ($parts['host'] ?? '');

        if ($host === '') {
            return 'bad url';
        }

        $secure = ($parts['scheme'] ?? 'https') === 'https';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $target = ($secure ? 'ssl://' : '').$host;

        $start = microtime(true);
        $socket = @fsockopen($target, $port, $errno, $errstr, 5);

        if ($socket === false) {
            return 'FAIL ('.$errno.')';
        }

        fclose($socket);
        $ms = (int) ((microtime(true) - $start) * 1000);

        return 'ok '.$ms.'ms';
    }
}
