<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookSignatureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Phase 15 — outbound webhook delivery worker.
 *
 * Signs and POSTs one event to a subscribed endpoint with exponential
 * backoff. A failed delivery is rescheduled (never marking business state);
 * after the configured consecutive-failure threshold the endpoint is
 * disabled and the delivery terminal. The delivery id makes retries
 * idempotent on the receiving side.
 */
class SendWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        protected WebhookEndpoint $endpoint,
        protected string $event,
        protected array $payload,
        protected string $deliveryId,
    ) {}

    public function handle(WebhookSignatureService $signatures): void
    {
        if (! $this->endpoint->isActive()) {
            $this->record(WebhookDelivery::STATUS_DISABLED, null, 'Endpoint is disabled.');

            return;
        }

        $rawBody = json_encode($this->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = time();
        $secret = Crypt::decryptString($this->endpoint->secret_encrypted);
        $signature = $signatures->sign($secret, $timestamp, $rawBody);

        $delivery = $this->delivery();

        try {
            $response = Http::timeout((int) config('webhooks.outbound.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-FFArena-Signature' => $signature,
                    'X-FFArena-Timestamp' => (string) $timestamp,
                    'X-FFArena-Event' => $this->event,
                    'X-FFArena-Delivery' => $this->deliveryId,
                ])
                ->post($this->endpoint->url, $this->payload);

            if ($response->successful()) {
                $this->succeed($delivery, $response->status());

                return;
            }

            $this->fail($delivery, $response->status(), 'HTTP '.$response->status());
        } catch (\Throwable $e) {
            $this->fail($delivery, null, mb_substr($e->getMessage(), 0, 255));
        }
    }

    /**
     * The persistent delivery row (created on first attempt).
     */
    protected function delivery(): WebhookDelivery
    {
        $delivery = WebhookDelivery::where('delivery_id', $this->deliveryId)->first();

        if ($delivery !== null) {
            return $delivery;
        }

        $delivery = new WebhookDelivery();
        $delivery->endpoint_id = $this->endpoint->id;
        $delivery->event = $this->event;
        $delivery->delivery_id = $this->deliveryId;
        $delivery->payload = $this->payload;
        $delivery->signature = ''; // signed per attempt, refreshed on retry
        $delivery->status = WebhookDelivery::STATUS_PENDING;
        $delivery->attempts = 0;
        $delivery->save();

        return $delivery;
    }

    protected function succeed(WebhookDelivery $delivery, int $status): void
    {
        $delivery->status = WebhookDelivery::STATUS_SUCCESS;
        $delivery->attempts = $delivery->attempts + 1;
        $delivery->last_status_code = $status;
        $delivery->delivered_at = now();
        $delivery->next_retry_at = null;
        $delivery->save();

        $this->endpoint->consecutive_failures = 0;
        $this->endpoint->last_success_at = now();
        $this->endpoint->save();
    }

    protected function fail(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $backoff = (array) config('webhooks.outbound.backoff', [10, 60, 300, 1800, 3600, 10800]);
        $threshold = (int) config('webhooks.outbound.disable_after_failures', 6);

        $this->endpoint->consecutive_failures = $this->endpoint->consecutive_failures + 1;
        $this->endpoint->save();

        $disable = $this->endpoint->consecutive_failures >= $threshold;

        $delivery->attempts = $attempts;
        $delivery->last_status_code = $status;
        $delivery->last_error = $error;

        if ($disable) {
            $delivery->status = WebhookDelivery::STATUS_DISABLED;
            $delivery->next_retry_at = null;

            $this->endpoint->status = WebhookEndpoint::STATUS_DISABLED;
            $this->endpoint->save();
        } elseif ($attempts >= count($backoff)) {
            $delivery->status = WebhookDelivery::STATUS_FAILED;
            $delivery->next_retry_at = null;
        } else {
            $delivery->status = WebhookDelivery::STATUS_PENDING;
            $delay = $backoff[$attempts - 1] ?? 60;
            $delivery->next_retry_at = now()->addSeconds($delay);

            static::dispatch($this->endpoint, $this->event, $this->payload, $this->deliveryId)
                ->delay($delay);
        }

        $delivery->save();
    }

    protected function record(string $status, ?int $code, string $error): void
    {
        $delivery = $this->delivery();
        $delivery->status = $status;
        $delivery->last_status_code = $code;
        $delivery->last_error = $error;
        $delivery->save();
    }
}
