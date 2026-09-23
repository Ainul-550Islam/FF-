<?php

namespace App\Http\Requests;

use App\Models\MarketingPushSubscription;
use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => 'required|in:'.implode(',', MarketingPushSubscription::PROVIDERS),

            // FCM delivery tokens are opaque strings; web-push endpoints are
            // HTTPS URLs. Both are size-capped so oversized payloads are
            // rejected before they touch storage.
            'endpoint' => 'required|string|min:16|max:500',
            'keys' => 'nullable|array|max:2',
            'keys.p256dh' => 'nullable|string|max:255',
            'keys.auth' => 'nullable|string|max:255',

            // Client identity fields are not accepted: the subscriber is the
            // authenticated user or the anonymous visitor cookie, decided
            // server-side.
            'topics' => 'nullable|array|max:10',
            'topics.*' => 'string|max:32',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('provider') === MarketingPushSubscription::PROVIDER_WEB) {
                $endpoint = (string) $this->input('endpoint', '');

                if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with($endpoint, 'https://')) {
                    $validator->errors()->add('endpoint', 'Web-push endpoints must be HTTPS URLs.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'endpoint.required' => 'A push endpoint or token is required.',
            'endpoint.min' => 'The endpoint looks malformed.',
            'endpoint.max' => 'The endpoint is too large.',
            'provider.in' => 'Unknown push provider.',
        ];
    }

    /**
     * @return array{provider:string, endpoint:string, keys:?array<string,string>, topics:?array<int,string>}
     */
    public function subscriptionData(): array
    {
        $keys = $this->input('keys');
        $keys = is_array($keys) ? $keys : null;

        $topics = $this->input('topics');
        $topics = is_array($topics) ? array_values(array_slice($topics, 0, 10)) : null;

        return [
            'provider' => (string) $this->input('provider'),
            'endpoint' => (string) $this->input('endpoint'),
            'keys' => $keys !== null ? array_slice($keys, 0, 2) : null,
            'topics' => $topics !== null ? array_map(fn ($t) => mb_substr((string) $t, 0, 32), $topics) : null,
        ];
    }
}
