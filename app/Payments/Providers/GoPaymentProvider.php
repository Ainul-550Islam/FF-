<?php

namespace App\Payments\Providers;

use App\Services\GoPaymentGatewayAdapter;

class GoPaymentProvider
{
    public function __construct(private GoPaymentGatewayAdapter $adapter) {}

    public function createPayment(array $data): array
    {
        return $this->adapter->createPayment($data);
    }
}
