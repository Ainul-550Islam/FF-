<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentMethodService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Saved payment-method management (Phase 14). A method belongs to exactly
 * one user; every read/write is ownership-checked via the policy and again
 * in the service.
 */
class PaymentMethodsController extends Controller
{
    public function __construct(
        protected PaymentMethodService $methods,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function index()
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $user = auth()->user();

        return view('settings.payment-methods', [
            'methods' => $this->methods->listFor($user),
            'providers' => $this->gateways->enabledProviders(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PaymentMethod::class);

        $data = $request->validate([
            'provider' => 'required|in:'.implode(',', PaymentMethod::PROVIDERS),
            'label' => 'required|string|max:60',
            'identifier' => 'required|string|max:20',
        ]);

        try {
            $this->methods->add(auth()->user(), $data['provider'], $data['label'], $data['identifier']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment method saved.');
    }

    public function destroy(PaymentMethod $method)
    {
        $this->authorize('delete', $method);

        try {
            $this->methods->remove(auth()->user(), $method);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment method removed.');
    }

    public function setDefault(PaymentMethod $method)
    {
        $this->authorize('setDefault', $method);

        try {
            $this->methods->setDefault(auth()->user(), $method);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Default payment method updated.');
    }
}
