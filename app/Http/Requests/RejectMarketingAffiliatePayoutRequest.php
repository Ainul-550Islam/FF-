<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectMarketingAffiliatePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => 'required|string|min:3|max:255',
            'review_notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A rejection reason is required for partner audit compliance.',
            'rejection_reason.min' => 'Rejection reason must be at least 3 characters.',
        ];
    }

    public function rejectionReason(): string
    {
        return trim((string) $this->input('rejection_reason'));
    }

    public function reviewNotes(): ?string
    {
        return $this->filled('review_notes') ? trim((string) $this->input('review_notes')) : null;
    }
}
