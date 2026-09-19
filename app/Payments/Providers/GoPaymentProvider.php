<?php
namespace App\Payments\Providers;
class GoPaymentProvider{public function __construct(private \App\Services\GoPaymentGatewayAdapter $adapter){} public function createPayment(array $data): array{return $this->adapter->createPayment($data);}}
