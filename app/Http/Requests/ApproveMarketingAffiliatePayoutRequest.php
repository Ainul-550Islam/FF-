<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveMarketingAffiliatePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth, active, admin
        return true;
    }

    public function rules(): array
    {
        return [
            'review_notes' => 'nullable|string|max:500',
        ];
    }

    public function reviewNotes(): ?string
    {
        return $this->filled('review_notes') ? trim((string) $this->input('review_notes')) : null;
    }
}
