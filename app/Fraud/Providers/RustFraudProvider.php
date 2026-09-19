<?php
namespace App\Fraud\Providers;
class RustFraudProvider{public function __construct(private \App\Services\RustFraudServiceAdapter $adapter){} public function evaluate(array $data): array{return $this->adapter->evaluate($data);}}
