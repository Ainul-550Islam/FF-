<?php

namespace App\Http\Controllers;

use App\Models\MarketingEvent;
use App\Models\MarketingExperiment;
use App\Services\MarketingAttributionService;
use App\Services\MarketingExperimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingExperimentController extends Controller
{
    public function adminIndex(): View
    {
        $experiments = MarketingExperiment::query()->with('variants')->latest()->get();

        $stats = [];
        foreach ($experiments as $exp) {
            $stats[$exp->id] = [];
            foreach ($exp->variants as $variant) {
                $exposures = MarketingEvent::query()
                    ->where('name', MarketingExperimentService::EXPOSURE_EVENT)
                    ->where('properties->experiment', $exp->key)
                    ->where('properties->variant', $variant->key)
                    ->count();

                $conversions = MarketingEvent::query()
                    ->where('name', MarketingExperimentService::CONVERSION_EVENT)
                    ->where('properties->experiment', $exp->key)
                    ->count();

                $stats[$exp->id][$variant->key] = [
                    'exposures' => $exposures,
                    'conversions' => $conversions,
                ];
            }
        }

        return view('admin.marketing.experiments', compact('experiments', 'stats'));
    }

    public function adminStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:64|unique:marketing_experiments,key',
            'name' => 'required|string|max:190',
            'description' => 'nullable|string|max:500',
            'traffic_allocation' => 'required|integer|min:0|max:100',
            'variants' => 'required|array|min:2',
            'variants.*.key' => 'required|string|max:32',
            'variants.*.name' => 'required|string|max:120',
            'variants.*.allocation' => 'required|integer|min:0|max:100',
        ]);

        $experiment = MarketingExperiment::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'traffic_allocation' => $data['traffic_allocation'],
            'status' => 'running',
            'starts_at' => now(),
        ]);

        foreach ($data['variants'] as $v) {
            $experiment->variants()->create([
                'key' => $v['key'],
                'name' => $v['name'],
                'allocation' => $v['allocation'],
            ]);
        }

        return redirect()->route('admin.marketing.experiments.index')->with('success', 'Experiment created successfully.');
    }

    public function assign(
        Request $request,
        string $key,
        MarketingExperimentService $service,
        MarketingAttributionService $attribution,
    ): JsonResponse {
        $experiment = MarketingExperiment::query()->where('key', $key)->firstOrFail();

        $user = $request->user();
        $identity = $user ? 'u:'.$user->id : 'a:'.$attribution->anonymousId($request);

        $variant = $service->assign($experiment, $identity);

        return response()->json([
            'ok' => true,
            'variant' => $variant?->key,
        ]);
    }

    public function convert(
        Request $request,
        string $key,
        MarketingExperimentService $service,
        MarketingAttributionService $attribution,
    ): JsonResponse {
        $experiment = MarketingExperiment::query()->where('key', $key)->firstOrFail();

        $user = $request->user();
        $identity = $user ? 'u:'.$user->id : 'a:'.$attribution->anonymousId($request);
        $conversion = (string) $request->input('conversion', 'conversion');

        $result = $service->convert($experiment, $identity, $conversion);

        return response()->json($result);
    }
}
