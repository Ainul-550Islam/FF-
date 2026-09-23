<?php

namespace App\Services;

use App\Models\MarketingAffiliate;
use App\Models\MarketingAffiliateReferral;
use App\Models\MarketingAttribution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 21 — affiliate program.
 *
 * One affiliate per user, server-validated codes, idempotent click
 * recording (one row per affiliate + visitor) and server-side signup
 * crediting (once per user, never for self-referrals). Crediting never
 * rewrites history: a referral row is credited by attaching the referred
 * user, and the unique constraint makes double credit impossible.
 */
class MarketingAffiliateService
{
    public function __construct(
        protected MarketingAttributionService $attribution,
        protected MarketingTrackingService $tracking,
    ) {}

    /**
     * Register the authenticated user as an affiliate (idempotent: one
     * affiliate row per user — a repeat call returns the existing row).
     */
    public function register(User $user, array $data = []): MarketingAffiliate
    {
        $existing = MarketingAffiliate::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $code = isset($data['code']) && $data['code'] !== ''
            ? strtoupper((string) $data['code'])
            : $this->generateCode();

        return MarketingAffiliate::create([
            'user_id' => $user->id,
            'code' => $code,
            'name' => $data['name'] ?? $user->name,
            'status' => MarketingAffiliate::STATUS_ACTIVE,
            'landing_url' => $data['landing_url'] ?? null,
            'approved_at' => now(),
        ]);
    }

    /**
     * A unique upper-case alnum code (configurable length).
     */
    protected function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random((int) config('marketing.affiliate.code_length', 8)));
        } while (MarketingAffiliate::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * The active affiliate for a click code — null when unknown, pending
     * or suspended (the controller 404s those).
     */
    public function findActiveByCode(string $code): ?MarketingAffiliate
    {
        $affiliate = MarketingAffiliate::query()->where('code', strtoupper($code))->first();

        if ($affiliate === null || ! $affiliate->isActive()) {
            return null;
        }

        return $affiliate;
    }

    /**
     * Record a /r/{code} landing. Idempotent per (affiliate, visitor):
     * one referral row per visitor, the click timestamp stays first-touch.
     */
    public function recordClick(MarketingAffiliate $affiliate, Request $request): MarketingAffiliateReferral
    {
        $anonymousId = $this->attribution->anonymousId($request);

        $referral = MarketingAffiliateReferral::query()->firstOrCreate(
            [
                'affiliate_id' => $affiliate->id,
                'anonymous_id' => $anonymousId,
            ],
            [
                'landing_path' => mb_substr('/r/'.$affiliate->code, 0, 500),
                'status' => MarketingAffiliateReferral::STATUS_CLICKED,
                'clicked_at' => now(),
            ]
        );

        try {
            $this->tracking->record('referral_click', [
                'code' => $affiliate->code,
                'affiliate_id' => $affiliate->id,
            ], $request);
        } catch (Throwable) {
            // Measurement must never break the redirect.
        }

        return $referral;
    }

    /**
     * Server-side crediting at signup: the visitor's most recent uncredited
     * referral click becomes this user's referral. Never credits
     * self-referrals — both by account identity and by attribution
     * ownership (a brand-new account from the affiliate's own browser is
     * still the affiliate's own visitor).
     */
    public function attachReferralToUser(User $user): ?MarketingAffiliateReferral
    {
        $request = app('request');

        try {
            $anonymousId = $this->attribution->anonymousId($request);
        } catch (Throwable) {
            return null;
        }

        /** @var MarketingAffiliateReferral|null $referral */
        $referral = MarketingAffiliateReferral::query()
            ->where('anonymous_id', $anonymousId)
            ->whereNull('referred_user_id')
            ->orderByDesc('clicked_at')
            ->orderByDesc('id')
            ->first();

        if ($referral === null) {
            return null;
        }

        $affiliate = $referral->affiliate;

        if ($affiliate === null || ! $affiliate->isActive()) {
            return null;
        }

        // Self-referral: same account…
        if ($affiliate->user_id !== null && $affiliate->user_id === $user->id) {
            return null;
        }

        // …or the affiliate's own browser (attribution ownership).
        $ownedByAffiliate = MarketingAttribution::query()
            ->where('anonymous_id', $anonymousId)
            ->where('user_id', $affiliate->user_id)
            ->exists();

        if ($affiliate->user_id !== null && $ownedByAffiliate) {
            return null;
        }

        // unique(referred_user_id) makes double credit impossible; a race
        // lands here as a query exception → quiet, no partial state.
        $referral->forceFill([
            'referred_user_id' => $user->id,
            'attribution_id' => MarketingAttribution::query()
                ->where('anonymous_id', $anonymousId)
                ->orderByDesc('id')
                ->value('id'),
            'status' => MarketingAffiliateReferral::STATUS_SIGNED_UP,
            'signed_up_at' => now(),
        ])->save();

        $affiliate->forceFill(['last_conversion_at' => now()])->save();

        try {
            $request = $request ?? null;
            if ($request !== null) {
                $this->tracking->recordConversion('referral_signup', $user, [
                    'code' => $affiliate->code,
                    'affiliate_id' => $affiliate->id,
                ]);
            }
        } catch (Throwable) {
            // Quiet: crediting already persisted; measurement is optional.
        }

        return $referral;
    }

    /**
     * Dashboard payload: the user's own affiliate row and honest funnel
     * stats (own code only — never another affiliate's data).
     */
    public function dashboardFor(User $user): ?array
    {
        $affiliate = MarketingAffiliate::query()->where('user_id', $user->id)->first();

        if ($affiliate === null) {
            return null;
        }

        $clicks = $affiliate->referrals()->count();
        $signups = $affiliate->referrals()->whereNotNull('referred_user_id')->count();

        return [
            'affiliate' => $affiliate,
            'stats' => [
                'clicks' => $clicks,
                'signups' => $signups,
                'conversion_rate' => $clicks > 0 ? (int) round(($signups / $clicks) * 100) : 0,
                'latest' => $affiliate->referrals()->latest('clicked_at')->latest('id')->take(10)->get(),
            ],
        ];
    }
}
