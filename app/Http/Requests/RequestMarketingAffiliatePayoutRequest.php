<?php

namespace App\Http\Requests;

use App\Models\MarketingAffiliate;
use Illuminate\Foundation\Http\FormRequest;

class RequestMarketingAffiliatePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Must be authenticated and must have an active affiliate profile
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return MarketingAffiliate::query()
            ->where('user_id', $user->id)
            ->where('status', MarketingAffiliate::STATUS_ACTIVE)
            ->exists();
    }

    public function rules(): array
    {
        return [
            // Client may request a partial amount (min ৳10); if omitted, server withdraws all available
            'amount_bdt' => 'nullable|numeric|min:10|max:1000000',
            'notes' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_bdt.min' => 'Minimum payout request is ৳10.00.',
            'amount_bdt.max' => 'Requested payout amount exceeds platform limit.',
        ];
    }

    /**
     * Requested amount in minor units (poisha), or null for full available balance.
     */
    public function requestedMinor(): ?int
    {
        if ($this->filled('amount_bdt')) {
            return (int) round(((float) $this->input('amount_bdt')) * 100);
        }

        return null;
    }

    public function requestNotes(): ?string
    {
        return $this->filled('notes') ? (string) $this->input('notes') : null;
    }
}
