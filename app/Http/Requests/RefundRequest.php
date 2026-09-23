<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_id' => 'required|string|exists:payments,id',
            'external_id' => 'required|string',
            'amount_minor' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'idempotency_key' => 'required|string|min:8|max:100',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
