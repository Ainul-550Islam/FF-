<?php

namespace App\Services;

use App\Models\MarketingAttribution;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 20 — first-party acquisition attribution.
 *
 * One row per (anonymous visitor, campaign key). First-touch rows are
 * immutable; the last-touch columns are refreshed on every qualifying
 * visit so the newest campaign a visitor saw is always measurable without
 * rewriting acquisition history. A business conversion later attaches the
 * attribution row to the user — ads connect to revenue without ever
 * storing a third-party identifier.
 */
class MarketingAttributionService
{
    /** Request attribute under which the derived visitor id is memoized. */
    public const IDENTITY_ATTRIBUTE = 'marketing_anonymous_id';

    /** Campaign key used when a visit carries no campaign signal at all. */
    public const DIRECT_KEY = '(direct)';

    /**
     * Derive the stable anonymous visitor id, creating one when absent.
     * Memoized per request so the attribution row, the funnel events and
     * the response cookie all carry exactly one identity.
     */
    public function anonymousId(Request $request): string
    {
        $memoized = $request->attributes->get(self::IDENTITY_ATTRIBUTE);

        if (is_string($memoized) && $memoized !== '') {
            return $memoized;
        }

        $cookieName = (string) config('marketing.first_party.cookie');
        $id = (string) $request->cookie($cookieName, '');

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $id = (string) Str::uuid();
        }

        $request->attributes->set(self::IDENTITY_ATTRIBUTE, $id);

        return $id;
    }

    /**
     * Record a landing: the first touch per (visitor, campaign) is
     * immutable, later visits refresh the last-touch columns only.
     */
    public function capture(Request $request, string $anonymousId): ?MarketingAttribution
    {
        if (! (bool) config('marketing.first_party.enabled', true)) {
            return null;
        }

        $utm = [];
        $content = null;
        $term = null;

        foreach (MarketingAttribution::UTM_KEYS as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                match ($key) {
                    'utm_source' => $utm['source'] = mb_substr($value, 0, 120),
                    'utm_medium' => $utm['medium'] = mb_substr($value, 0, 120),
                    'utm_campaign' => $utm['campaign'] = mb_substr($value, 0, 120),
                    'utm_content' => $content = mb_substr($value, 0, 255),
                    'utm_term' => $term = mb_substr($value, 0, 255),
                    default => null,
                };
            }
        }

        $clickType = null;
        $clickId = null;

        foreach (MarketingAttribution::CLICK_ID_KEYS as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $clickType = $key;
                $clickId = mb_substr($value, 0, 255);

                break;
            }
        }

        $referrer = (string) $request->headers->get('referer', '');
        $referrerHost = null;

        if ($referrer !== '') {
            $host = parse_url($referrer, PHP_URL_HOST);
            $host = is_string($host) ? mb_strtolower($host) : '';
            $internalHost = (string) parse_url((string) config('app.url', ''), PHP_URL_HOST);

            if ($host !== '' && $host !== mb_strtolower($internalHost)) {
                $referrerHost = mb_substr($host, 0, 120);
            }
        }

        $campaign = $utm['campaign'] ?? null;
        $campaignKey = $campaign ?? ($clickType ?? $referrerHost ?? self::DIRECT_KEY);

        $row = MarketingAttribution::query()->firstOrCreate(
            [
                'anonymous_id' => $anonymousId,
                'campaign_key' => $campaignKey,
            ],
            [
                'user_id' => $request->user()?->id,
                'source' => $utm['source'] ?? null,
                'medium' => $utm['medium'] ?? null,
                'campaign' => $campaign,
                'content' => $content,
                'term' => $term,
                'click_id_type' => $clickType,
                'click_id' => $clickId,
                'landing_path' => mb_substr((string) $request->path(), 0, 500),
                'referrer_host' => $referrerHost,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        // Last touch refresh: newest signal wins for measurement, the
        // first-touch columns stay untouched.
        $row->fill([
            'source' => $utm['source'] ?? $row->source,
            'medium' => $utm['medium'] ?? $row->medium,
            'campaign' => $campaign ?? $row->campaign,
            'content' => $content ?? $row->content,
            'term' => $term ?? $row->term,
            'user_id' => $row->user_id ?? $request->user()?->id,
            'last_seen_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * The visitor's most recent attribution row (lead source enrichment).
     */
    public function latestFor(string $anonymousId): ?MarketingAttribution
    {
        return MarketingAttribution::query()
            ->where('anonymous_id', $anonymousId)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Bind every anonymous touch of this visitor to the user (called on
     * login/registration — quiet, never blocks auth).
     */
    public function attachUser(string $anonymousId, int $userId): int
    {
        return MarketingAttribution::query()
            ->where('anonymous_id', $anonymousId)
            ->whereNull('user_id')
            ->update(['user_id' => $userId]);
    }
}
