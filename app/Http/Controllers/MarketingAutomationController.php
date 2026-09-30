<?php

namespace App\Http\Controllers;

use App\Models\MarketingAutomation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingAutomationController extends Controller
{
    public function index(): View
    {
        $automations = MarketingAutomation::query()->orderBy('name')->get();

        return view('admin.marketing.automations', compact('automations'));
    }

    public function toggle(Request $request, MarketingAutomation $automation): RedirectResponse
    {
        $enabled = $request->has('enabled')
            ? (bool) $request->input('enabled')
            : ! $automation->enabled;

        $automation->enabled = $enabled;
        $automation->save();

        return back()->with('success', "Automation {$automation->name} is now ".($enabled ? 'enabled' : 'disabled').'.');
    }
}
