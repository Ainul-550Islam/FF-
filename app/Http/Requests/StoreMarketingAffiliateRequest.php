<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingAffiliateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces authentication; the applicant identity is
        // always the authenticated user, never a client-supplied id.
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional custom code; uniqueness is enforced against the table.
            'code' => 'nullable|alpha_num|between:4,24|unique:marketing_affiliates,code',
            'name' => 'nullable|string|max:120',
            'landing_url' => 'nullable|url|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'That affiliate code is already taken.',
            'code.alpha_num' => 'Codes may only contain letters and numbers.',
            'landing_url.url' => 'The landing URL must be a valid URL.',
        ];
    }

    /**
     * Server-decided fields only — user identity and status are never taken
     * from the client payload.
     *
     * @return array{code:?string, name:?string, landing_url:?string}
     */
    public function affiliateData(): array
    {
        return [
            'code' => $this->input('code') !== null ? strtoupper((string) $this->input('code')) : null,
            'name' => $this->input('name') !== null ? (string) $this->input('name') : null,
            'landing_url' => $this->input('landing_url') !== null ? (string) $this->input('landing_url') : null,
        ];
    }
}
