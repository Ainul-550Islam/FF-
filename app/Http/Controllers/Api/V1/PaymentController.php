<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function methods(Request $request)
    {
        return response()->json(['data'=>[
            ['id'=>'bkash','name'=>'bKash','type'=>'mobile_wallet','enabled'=>true,'requires_internet'=>true],
            ['id'=>'nagad','name'=>'Nagad','type'=>'mobile_wallet','enabled'=>true,'requires_internet'=>true],
            ['id'=>'rocket','name'=>'Rocket','type'=>'mobile_wallet','enabled'=>true,'requires_internet'=>false,'note'=>'Manual verification fallback'],
            ['id'=>'manual','name'=>'Manual','type'=>'bank','enabled'=>true,'requires_internet'=>false],
        ]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required','in:bkash,nagad,rocket,manual'],
            'amount_minor' => ['required','integer','min:100'],
            'currency' => ['nullable','in:BDT'],
            'idempotency_key' => ['nullable','string'],
        ]);

        $user = $request->user();
        $wallet = Wallet::firstOrCreate(['user_id'=>$user->id,'currency'=>$validated['currency'] ?? 'BDT'], ['balance_minor'=>0]);

        $payment = Payment::create([
            'user_id'=>$user->id,
            'wallet_id'=>$wallet->id,
            'provider'=>$validated['provider'],
            'external_id'=> 'pay_'.Str::uuid(),
            'amount_minor'=>$validated['amount_minor'],
            'currency'=>$validated['currency'] ?? 'BDT',
            'status'=>'created',
            'idempotency_key'=>$validated['idempotency_key'] ?? (string) Str::uuid(),
        ]);

        return response()->json(['data'=>$payment,'message'=>'Payment created'], 201);
    }

    public function show(Request $request, Payment $payment)
    {
        if ($payment->user_id !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json(['error'=>'forbidden'], 403);
        }
        return response()->json(['data'=>$payment]);
    }
}
