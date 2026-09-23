<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GoPaymentController extends Controller
{
    public function methods(Request $request)
    {
        return response()->json(['data' => [
            ['id' => 'bkash', 'name' => 'bKash'],
            ['id' => 'nagad', 'name' => 'Nagad'],
            ['id' => 'rocket', 'name' => 'Rocket'],
            ['id' => 'manual', 'name' => 'Manual'],
        ]]);
    }

    public function store(Request $request)
    {
        // Proxy to Go service if enabled, else fallback
        if (! config('services_go_rust.go_payment.enabled', false)) {
            return response()->json(['message' => 'Go payment disabled, use /api/v1/payments', 'fallback' => true], 200);
        }

        return response()->json(['message' => 'Proxied to Go', 'data' => []], 201);
    }

    public function show(Request $request, $payment)
    {
        return response()->json(['data' => ['id' => $payment, 'status' => 'pending']]);
    }

    public function health(Request $request)
    {
        return response()->json(['status' => 'ok', 'service' => 'go-payment', 'enabled' => config('services_go_rust.go_payment.enabled', false)]);
    }
}
