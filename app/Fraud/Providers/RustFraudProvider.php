<?php

namespace App\Fraud\Providers;

use App\Services\RustFraudServiceAdapter;

class RustFraudProvider
{
    public function __construct(private RustFraudServiceAdapter $adapter) {}

    public function evaluate(array $data): array
    {
        return $this->adapter->evaluate($data);
    }
}
