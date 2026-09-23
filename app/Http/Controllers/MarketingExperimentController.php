<?php

namespace App\Http\Controllers;

use App\Models\MarketingExperiment;
use App\Services\MarketingAttributionService;
use App\Services\MarketingExperimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 21 — A/B experiment endpoints.
 *
 * Public assign/convert are identity-safe (user id or the anonymous cookie
 * — never a client-posted identity), deterministic and throttled. The admin
 * surface stays inside the admin middleware group: creating an experiment
 * immediately runs it with its declared variants.
 */
class MarketingExperimentController extends Controller
{
    public function __construct(
        protected MarketingExperimentService $experiments,
        protected MarketingAttributionService $attribution,
    ) {}

    /**
     * GET /marketing/experiments/{key}/assign — the variant for this
     * visitor, or null when outside the experiment.
     */
    public function assign(Request $request, string $key): JsonResponse
    {
        $experiment = MarketingExperiment::query()->where('key', $key)->first();

        if ($experiment === null) {
            abort(404);
        }

        $identity = $this->identityFor($request);

        $variant = $this->experiments->assign($experiment, $identity);

        return response()->json([
            'ok' => true,
            'experiment' => $experiment->key,
            'variant' => $variant?->key,
        ]);
    }

    /**
     * POST /marketing/experiments/{key}/convert — a conversion moment for
     * an exposed identity. Exposure and conversion are different moments;
     * converting without a prior exposure is honestly reported, not faked.
     */
    public function convert(Request $request, string $key): JsonResponse
    {
        $experiment = MarketingExperiment::query()->where('key', $key)->first();

        if ($experiment === null) {
            abort(404);
        }

        $data = $request->validate([
            'conversion' => 'required|string|max:64',
        ]);

        $result = $this->experiments->convert(
            $experiment,
            $this->identityFor($request),
            (string) $data['conversion']
        );

        return response()->json($result);
    }

    /**
     * Admin listing with honest per-variant funnel numbers.
     */
    public function adminIndex(): View
    {
        $experiments = MarketingExperiment::query()->with('variants')->orderByDesc('id')->get();

        $exposureRows = MarketingEvent::query()
            ->where('name', MarketingExperimentService::EXPOSURE_EVENT)
            ->get(['properties']);
        $conversionRows = MarketingEvent::query()
            ->where('name', MarketingExperimentService::CONVERSION_EVENT)
            ->get(['properties']);

        $stats = [];

        foreach ($exposureRows as $row) {
            $key = (string) ($row->properties['experiment'] ?? '');
            $variant = (string) ($row->properties['variant'] ?? '');

            if ($key !== '' && $variant !== '') {
                $stats[$key][$variant]['exposures'] = ($stats[$key][$variant]['exposures'] ?? 0) + 1;
            }
        }

        foreach ($conversionRows as $row) {
            $key = (string) ($row->properties['experiment'] ?? '');
            $variant = (string) ($row->properties['variant'] ?? '');

            if ($key !== '' && $variant !== '') {
                $stats[$key][$variant]['conversions'] = ($stats[$key][$variant]['conversions'] ?? 0) + 1;
            }
        }

        $byKey = [];

        foreach ($experiments as $experiment) {
            foreach ($experiment->variants as $variant) {
                $byKey[$experiment->id][$variant->key] = [
                    'exposures' => $stats[$experiment->key][$variant->key]['exposures'] ?? 0,
                    'conversions' => $stats[$experiment->key][$variant->key]['conversions'] ?? 0,
                ];
            }
        }

        return view('admin.marketing.experiments', [
            'experiments' => $experiments,
            'stats' => $byKey,
        ]);
    }

    /**
     * Admin create: key is unique alpha-dash, 1–8 variants, and the
     * experiment goes live immediately with the declared allocation.
     */
    public function adminStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => 'required|alpha_dash|max:64|unique:marketing_experiments,key',
            'name' => 'required|string|max:190',
            'description' => 'nullable|string|max:500',
            'traffic_allocation' => 'required|integer|between:1,100',
            'variants' => 'required|array|min:1|max:8',
            'variants.*.key' => 'required|alpha_dash|max:32',
            'variants.*.name' => 'required|string|max:120',
            'variants.*.allocation' => 'required|integer|between:0,100',
        ]);

        $experiment = MarketingExperiment::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => MarketingExperiment::STATUS_RUNNING,
            'traffic_allocation' => (int) $data['traffic_allocation'],
            'starts_at' => now(),
        ]);

        foreach ($data['variants'] as $variant) {
            $experiment->variants()->create([
                'key' => $variant['key'],
                'name' => $variant['name'],
                'allocation' => (int) $variant['allocation'],
            ]);
        }

        return back()->with('success', 'Experiment '.$experiment->key.' is running.');
    }

    /**
     * Identity is decided server-side: the authenticated user, else the
     * anonymous visitor cookie — prefixed by kind so ledgers never mix them.
     */
    protected function identityFor(Request $request): string
    {
        $userId = $request->user()?->id;

        return $userId !== null
            ? 'u:'.$userId
            : 'a:'.$this->attribution->anonymousId($request);
    }
}
