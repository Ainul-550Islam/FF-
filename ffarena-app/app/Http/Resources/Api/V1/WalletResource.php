<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wallet summary. Read-only for ordinary users; mutations only ever go
 * through WalletService.
 */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'balance' => Money::toDecimal($this->balanceMinor()),
            'balance_minor' => $this->balanceMinor(),
            'currency' => $this->currency ?? 'BDT',
            'status' => $this->status,
        ];
    }
}
