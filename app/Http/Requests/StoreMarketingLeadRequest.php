<?php

namespace App\Http\Requests;

use App\Models\MarketingLead;
use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = implode(',', (array) config('marketing.leads.types', MarketingLead::TYPES));

        return [
            // The endpoint decides the authoritative type server-side; a
            // client-posted type is only cross-checked, never trusted.
            'type' => 'nullable|in:'.$types,
            'email' => 'required|email:rfc|max:255',
            'name' => 'nullable|string|max:120',
            'message' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Unknown lead type.',
            'email.email' => 'Please provide a valid email address.',
        ];
    }

    /**
     * The lead type is fixed per endpoint; a client cannot post an arbitrary
     * type through the newsletter form.
     */
    public function forceType(string $type): array
    {
        return [
            'type' => $type,
            'email' => (string) $this->input('email'),
            'name' => $this->input('name') !== null ? (string) $this->input('name') : null,
            'message' => $this->input('message') !== null ? (string) $this->input('message') : null,
        ];
    }
}
