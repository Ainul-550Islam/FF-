<?php

namespace App\Services;

use App\Models\MarketingAttribution;
use App\Models\MarketingEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 20 — first-party conversion-event pipeline (§40 taxonomy).
 *
 * Client events arrive via POST /marketing/event and are strictly
 * validated against the configured taxonomy — the funnel is a contract,
 * not a free-form log. Server-side moments (registration, payment) are
 * recorded through recordConversion() and never depend on client JS.
 * Everything is quiet by contract: measurement must never break product.
 */
class MarketingTrackingService
{
    public function __construct(
        protected MarketingAttributionService $attribution,
    ) {}

    /**
     * Record a validated client event from the current request.
     *
     * @return array{ok:bool, error?:string}
     */
    public function recordFromRequest(Request $request): array
    {
        if (! (bool) config('marketing.events.enabled', true)) {
            return ['ok' => true];
        }

        $name = (string) $request->input('name', '');
        $properties = $request->input('properties');

        if (! in_array($name, (array) config('marketing.events.allowed'), true)) {
            return ['ok' => false, 'error' => 'Unknown event name.'];
        }

        if ($properties !== null && ! is_array($properties)) {
            return ['ok' => false, 'error' => 'Properties must be an object.'];
        }

        if (is_array($properties) && count($properties) > (int) config('marketing.events.max_properties', 20)) {
            return ['ok' => false, 'error' => 'Too many properties.'];
        }

        try {
            $this->record($name, is_array($properties) ? $properties : [], $request);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'Could not record event.'];
        }

        return ['ok' => true];
    }

    /**
     * Record an event bound to the current visitor identity. Quiet by
     * contract — callers never depend on measurement succeeding.
     */
    public function record(string $name, array $properties, Request $request): ?MarketingEvent
    {
        if (! (bool) config('marketing.events.enabled', true)) {
            return null;
        }

        if (! in_array($name, (array) config('marketing.events.allowed'), true)) {
            return null;
        }

        $anonymousId = $this->attribution->anonymousId($request);
        $attribution = MarketingAttribution::query()
            ->where('anonymous_id', $anonymousId)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();

        return MarketingEvent::create([
            'anonymous_id' => $anonymousId,
            'user_id' => $request->user()?->id,
            'attribution_id' => $attribution?->id,
            'name' => $name,
            'properties' => $this->sanitizeProperties($properties),
            'url' => mb_substr((string) $request->fullUrl(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * Server-side conversion moment (registration, payment outcome…).
     * Never requires client JS or consent state — it is first-party
     * measurement of our own product truth.
     */
    public function recordConversion(string $name, ?User $user, array $properties = []): ?MarketingEvent
    {
        if (! (bool) config('marketing.events.enabled', true)) {
            return null;
        }

        if (! in_array($name, (array) config('marketing.events.allowed'), true)) {
            return null;
        }

        $request = app('request');
        $anonymousId = null;

        try {
            if ($request !== null) {
                $anonymousId = $this->attribution->anonymousId($request);
            }
        } catch (Throwable) {
            $anonymousId = null;
        }

        $attribution = $anonymousId !== null
            ? MarketingAttribution::query()->where('anonymous_id', $anonymousId)->orderByDesc('id')->first()
            : null;

        return MarketingEvent::create([
            'anonymous_id' => $anonymousId ?? (string) Str::uuid(),
            'user_id' => $user?->id,
            'attribution_id' => $attribution?->id,
            'name' => $name,
            'properties' => $this->sanitizeProperties($properties),
            'url' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Bind every anonymous touch/event of the current visitor identity to
     * the user who just registered or logged in. Never blocks auth.
     */
    public function attachVisitor(User $user): void
    {
        $request = app('request');

        try {
            $anonymousId = $this->attribution->anonymousId($request);
        } catch (Throwable) {
            return;
        }

        $this->attribution->attachUser($anonymousId, $user->id);

        MarketingEvent::query()
            ->where('anonymous_id', $anonymousId)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    /**
     * Properties are bounded scalars only — never objects, never huge.
     *
     * @return array<string,string|int|bool>|null
     */
    protected function sanitizeProperties(array $properties): ?array
    {
        $clean = [];

        foreach (array_slice($properties, 0, (int) config('marketing.events.max_properties', 20), true) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[mb_substr((string) $key, 0, 64)] = $value === null ? null : mb_substr((string) $value, 0, 255);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
