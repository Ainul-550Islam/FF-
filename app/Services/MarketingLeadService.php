<?php

namespace App\Services;

use App\Models\MarketingAutomation;
use App\Models\MarketingEvent;
use App\Models\MarketingLead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 20 — lead capture (newsletter / contact / organizer / partner).
 *
 * One row per email: a repeat capture refreshes the existing lead instead
 * of duplicating it, and re-subscribes it when it had opted out. Every lead
 * carries its own unsubscribe token so lifecycle mail has a working opt-out
 * from day one.
 */
class MarketingLeadService
{
    public function __construct(
        protected MarketingAttributionService $attribution,
        protected MarketingTrackingService $tracking,
        protected MarketingAutomationService $automation,
    ) {}

    /**
     * Create or refresh a lead from a validated request payload.
     */
    public function capture(array $data, Request $request): MarketingLead
    {
        $anonymousId = $this->attribution->anonymousId($request);
        $touch = $this->attribution->latestFor($anonymousId);

        $metadata = [];
        if (! empty($data['message'])) {
            $metadata['message'] = mb_substr((string) $data['message'], 0, 2000);
        }

        $lead = MarketingLead::query()->where('email', strtolower($data['email']))->first();

        if ($lead === null) {
            $lead = MarketingLead::create([
                'email' => strtolower($data['email']),
                'name' => $data['name'] ?? null,
                'user_id' => $request->user()?->id,
                'type' => $data['type'],
                'source' => $touch?->source,
                'medium' => $touch?->medium,
                'campaign' => $touch?->campaign,
                'metadata' => $metadata === [] ? null : $metadata,
                'unsubscribe_token' => (string) Str::uuid(),
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ]);
        } else {
            $lead->fill([
                'name' => $data['name'] ?? $lead->name,
                'user_id' => $lead->user_id ?? $request->user()?->id,
                'type' => $data['type'],
                'source' => $touch?->source ?? $lead->source,
                'medium' => $touch?->medium ?? $lead->medium,
                'campaign' => $touch?->campaign ?? $lead->campaign,
                'metadata' => $metadata === [] ? $lead->metadata : $metadata,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ])->save();
        }

        // Server-side funnel moment: recorded directly (lead_captured is
        // deliberately outside the client-postable §40 taxonomy).
        try {
            MarketingEvent::create([
                'anonymous_id' => $anonymousId,
                'user_id' => $lead->user_id,
                'attribution_id' => $touch?->id,
                'name' => 'lead_captured',
                'properties' => ['type' => $lead->type],
                'url' => mb_substr((string) $request->fullUrl(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Measurement must never fail capture.
        }

        // Phase 21 — lifecycle automation: a fresh lead can fire a welcome
        // sequence through the automation engine. Quiet by contract: a
        // broken automation must never fail lead capture (and a guest lead
        // with no linked user simply evaluates to zero sends).
        rescue(function () use ($lead): void {
            $user = $lead->user_id !== null ? User::find($lead->user_id) : null;
            $this->automation->evaluate(MarketingAutomation::TRIGGER_LEAD_SUBSCRIBED, $user);
        }, report: false);

        return $lead;
    }

    /**
     * Tokenised opt-out. Returns whether a lead was found and unsubscribed.
     */
    public function unsubscribe(string $token): bool
    {
        $lead = MarketingLead::query()->where('unsubscribe_token', $token)->first();

        if ($lead === null) {
            return false;
        }

        if ($lead->unsubscribed_at === null) {
            $lead->unsubscribed_at = now();
            $lead->save();
        }

        return true;
    }
}
