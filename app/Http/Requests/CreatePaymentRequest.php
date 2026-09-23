<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'amount_minor' => 'required|integer|min:1|max:100000000',
            'currency' => 'required|string|size:3|in:BDT,USD,EUR',
            'provider' => 'required|string|in:manual,bkash,nagad,rocket',
            'external_id' => 'required|string|min:3|max:100|unique:payments,external_id',
            'idempotency_key' => 'required|string|min:8|max:100',
            'callback_url' => 'nullable|url|max:500',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Idempotency-Key is required for payment creation',
            'external_id.unique' => 'External ID already exists - use idempotency key for retry',
        ];
    }
}
