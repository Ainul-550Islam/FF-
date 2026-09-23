<?php

namespace App\Services;

use App\Models\MarketingPromoCode;
use App\Models\MarketingPromoRedemption;
use App\Models\Tournament;
use App\Models\User;
use Throwable;

/**
 * Phase 21 — promo code application.
 *
 * The server owns every number: the discount is recomputed from the
 * tournament's own entry fee, never from a client-supplied price.
 * Eligibility (active, window, global cap, minimum fee) is evaluated
 * server-side, idempotency comes first (an existing redemption is
 * returned as a duplicate instead of erroring), and settlement is
 * authoritative — what this method returns is what the checkout uses.
 * Payment state machines are never touched here.
 */
class MarketingPromoCodeService
{
    /**
     * Apply a code for a user in an optional tournament context.
     *
     * @param  array{code:string, tournament_id:?int}  $context
     * @return array{ok:bool, duplicate?:bool, code?:string, type?:string, discount_minor?:int, redemption_id?:int, error?:string}
     */
    public function apply(array $context, User $user): array
    {
        $code = MarketingPromoCode::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($context['code'])])
            ->first();

        if ($code === null) {
            return ['ok' => false, 'error' => 'Invalid promo code.'];
        }

        // Idempotency first: a repeat apply returns the stored redemption
        // instead of re-evaluating gates that may have closed since.
        $existing = MarketingPromoRedemption::query()
            ->where('promo_code_id', $code->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return [
                'ok' => true,
                'duplicate' => true,
                'code' => $code->code,
                'type' => $code->type,
                'discount_minor' => (int) $existing->discount_minor,
                'redemption_id' => $existing->id,
            ];
        }

        $tournament = $context['tournament_id'] !== null
            ? Tournament::query()->find($context['tournament_id'])
            : null;

        if ($tournament === null) {
            return ['ok' => false, 'error' => 'Choose a tournament to apply this code.'];
        }

        $feeMinor = $tournament->entryFeeMinor();

        $problem = $this->evaluate($code, $feeMinor);

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem];
        }

        try {
            $redemption = MarketingPromoRedemption::create([
                'promo_code_id' => $code->id,
                'user_id' => $user->id,
                'tournament_id' => $tournament->id,
                'discount_minor' => $this->computeDiscount($code, $feeMinor),
                'metadata' => [
                    'entry_fee_minor' => $feeMinor,
                    'tournament' => $tournament->slug,
                ],
            ]);
        } catch (Throwable) {
            // unique(promo_code_id, user_id) lost a race — treat as the
            // existing redemption.
            $existing = MarketingPromoRedemption::query()
                ->where('promo_code_id', $code->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing === null) {
                return ['ok' => false, 'error' => 'Could not apply the promo code right now.'];
            }

            return [
                'ok' => true,
                'duplicate' => true,
                'code' => $code->code,
                'type' => $code->type,
                'discount_minor' => (int) $existing->discount_minor,
                'redemption_id' => $existing->id,
            ];
        }

        return [
            'ok' => true,
            'duplicate' => false,
            'code' => $code->code,
            'type' => $code->type,
            'discount_minor' => (int) $redemption->discount_minor,
            'redemption_id' => $redemption->id,
        ];
    }

    /**
     * Server-side eligibility. Returns null when eligible, else the
     * human error for the 422 response.
     */
    protected function evaluate(MarketingPromoCode $code, int $feeMinor): ?string
    {
        if (! $code->active) {
            return 'This promo code is not active.';
        }

        if (! $code->isWithinWindow()) {
            return 'This promo code is outside its valid window.';
        }

        if ($code->max_redemptions !== null
            && $code->redemptions()->count() >= (int) $code->max_redemptions) {
            return 'This promo code has reached its redemption limit.';
        }

        if ($code->min_entry_fee_minor !== null && $feeMinor < (int) $code->min_entry_fee_minor) {
            return 'The entry fee is below the minimum for this promo code.';
        }

        return null;
    }

    /**
     * The discount for a fee: percent of the fee, or fixed capped at the
     * fee (a promo never pays the player).
     */
    public function computeDiscount(MarketingPromoCode $code, int $feeMinor): int
    {
        $discount = $code->type === MarketingPromoCode::TYPE_PERCENT
            ? (int) intdiv($feeMinor * (int) $code->value, 100)
            : (int) $code->value;

        return max(0, min($discount, $feeMinor));
    }
}
