<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentMethodsController extends Controller
{
    public function store(Request $request, PaymentMethodService $service): RedirectResponse
    {
        $request->validate([
            'provider' => 'required|in:'.implode(',', PaymentMethod::PROVIDERS),
            'label' => 'required|string|max:60',
            'identifier' => 'required|string|min:6|max:20',
        ]);

        try {
            $service->add(
                $request->user(),
                (string) $request->input('provider'),
                (string) $request->input('label'),
                (string) $request->input('identifier'),
            );

            return back()->with('success', 'Payment method added successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setDefault(PaymentMethod $method, PaymentMethodService $service): RedirectResponse
    {
        if ($method->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            $service->setDefault(auth()->user(), $method);

            return back()->with('success', 'Default payment method updated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PaymentMethod $method, PaymentMethodService $service): RedirectResponse
    {
        if ($method->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            $service->remove(auth()->user(), $method);

            return back()->with('success', 'Payment method removed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
