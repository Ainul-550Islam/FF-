<?php

namespace App\Http\Controllers;

use App\Models\MarketingAutomation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 21 — admin lifecycle-automation management.
 *
 * Listing shows every automation with the trigger taxonomy; toggle flips
 * only the enabled flag (payload content is edited via seeders/migrations —
 * the admin surface never becomes a free-form code executor).
 */
class MarketingAutomationController extends Controller
{
    public function index(): View
    {
        return view('admin.marketing.automations', [
            'automations' => MarketingAutomation::query()->orderBy('trigger')->orderBy('id')->get(),
            'triggers' => MarketingAutomation::TRIGGERS,
        ]);
    }

    public function toggle(Request $request, MarketingAutomation $automation): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $automation->forceFill(['enabled' => (bool) $data['enabled']])->save();

        return back()->with(
            'success',
            $automation->enabled
                ? 'Automation "'.$automation->name.'" enabled.'
                : 'Automation "'.$automation->name.'" disabled.'
        );
    }
}
