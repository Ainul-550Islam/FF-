<?php

namespace App\Services;

use App\Models\MarketingEvent;
use App\Models\MarketingExperiment;
use App\Models\MarketingExperimentVariant;

/**
 * Phase 21 — deterministic A/B assignment.
 *
 * The variant a visitor sees is a pure function of (experiment, identity):
 * reassigning always returns the same answer, so the product never flips
 * between variants for the same person. Exposure is recorded once per
 * (experiment, identity) and is deliberately decoupled from conversions —
 * seeing a variant and converting are different funnel moments. Identity
 * is the authenticated user id when signed in, otherwise the anonymous
 * visitor cookie id — never anything client-posted.
 */
class MarketingExperimentService
{
    public const EXPOSURE_EVENT = 'experiment_exposure';

    public const CONVERSION_EVENT = 'experiment_conversion';

    /**
     * Assign a variant for an identity. Returns null when the experiment
     * is not running, the identity falls outside the traffic allocation,
     * or the deterministic buckets land on no variant (degenerate
     * allocations summing below 100).
     */
    public function assign(MarketingExperiment $experiment, string $identity): ?MarketingExperimentVariant
    {
        if (! $experiment->isRunning()) {
            return null;
        }

        $bucket = $this->bucket($experiment->key.':'.$identity);

        if ($bucket >= (int) $experiment->traffic_allocation) {
            return null;
        }

        $variants = $experiment->variants()->orderBy('id')->get();

        if ($variants->isEmpty()) {
            return null;
        }

        // Zero-allocation variants can never be picked: their cumulative
        // range is empty by construction.
        $cursor = 0;
        $selected = null;

        foreach ($variants as $variant) {
            $cursor += max(0, (int) $variant->allocation);

            if ($bucket % 100 < $cursor) {
                $selected = $variant;

                break;
            }
        }

        if ($selected === null) {
            return null;
        }

        $this->recordExposure($experiment, $selected, $identity);

        return $selected;
    }

    /**
     * Record a conversion for an identity. Only identities that were
     * actually exposed convert — exposure and conversion are separate
     * moments and are never conflated.
     *
     * @return array{ok:bool, recorded:bool}
     */
    public function convert(MarketingExperiment $experiment, string $identity, string $conversion): array
    {
        $exposed = MarketingEvent::query()
            ->where('name', self::EXPOSURE_EVENT)
            ->where('properties->experiment', $experiment->key)
            ->where('properties->identity', $identity)
            ->exists();

        if (! $exposed) {
            return ['ok' => true, 'recorded' => false];
        }

        MarketingEvent::create([
            'anonymous_id' => str_starts_with($identity, 'a:') ? substr($identity, 2) : null,
            'user_id' => str_starts_with($identity, 'u:') ? (int) substr($identity, 2) : null,
            'attribution_id' => null,
            'name' => self::CONVERSION_EVENT,
            'properties' => [
                'experiment' => $experiment->key,
                'identity' => $identity,
                'conversion' => mb_substr($conversion, 0, 64),
            ],
            'url' => null,
            'created_at' => now(),
        ]);

        return ['ok' => true, 'recorded' => true];
    }

    /**
     * Deterministic bucket in [0,100) for an identity — md5 keeps the
     * split stable across PHP versions and servers (no rand() anywhere).
     */
    protected function bucket(string $key): int
    {
        return (int) (hexdec(substr(md5($key), 0, 8)) % 100);
    }

    /**
     * Exposure is recorded exactly once per (experiment, identity).
     */
    protected function recordExposure(MarketingExperiment $experiment, MarketingExperimentVariant $variant, string $identity): void
    {
        $already = MarketingEvent::query()
            ->where('name', self::EXPOSURE_EVENT)
            ->where('properties->experiment', $experiment->key)
            ->where('properties->identity', $identity)
            ->exists();

        if ($already) {
            return;
        }

        $isUser = str_starts_with($identity, 'u:');

        MarketingEvent::create([
            'anonymous_id' => $isUser ? null : substr($identity, 2),
            'user_id' => $isUser ? (int) substr($identity, 2) : null,
            'attribution_id' => null,
            'name' => self::EXPOSURE_EVENT,
            'properties' => [
                'experiment' => $experiment->key,
                'variant' => $variant->key,
                'identity' => $identity,
            ],
            'url' => null,
            'created_at' => now(),
        ]);
    }
}
