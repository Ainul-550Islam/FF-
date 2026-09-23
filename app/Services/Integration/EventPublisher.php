<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Log;

class EventPublisher
{
    public const VERSION = 'v1';

    public function publish(string $type, array $payload): void
    {
        if (! config('services_go_rust.events.enabled')) {
            return;
        }
        Log::channel('daily')->info('event published', ['type' => $type, 'version' => self::VERSION, 'payload' => $payload]);
    }

    public function publishPaymentCreated(array $payment): void
    {
        $this->publish('payment.created.'.self::VERSION, $payment);
    }

    public function publishPaymentSucceeded(array $payment): void
    {
        $this->publish('payment.succeeded.'.self::VERSION, $payment);
    }
}
