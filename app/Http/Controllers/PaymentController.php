<?php
namespace App\Http\Controllers;
use App\Models\Payment;
use Illuminate\Http\Request;
class PaymentController extends Controller
{
    public function __construct() { $this->middleware(['auth','active']); }
    public function methods(Request $request)
    {
        $methods = [['id'=>'bkash','name'=>'bKash','type'=>'mobile'],['id'=>'nagad','name'=>'Nagad','type'=>'mobile'],['id'=>'rocket','name'=>'Rocket','type'=>'mobile'],['id'=>'manual','name'=>'Manual','type'=>'bank']];
        return view('payment.methods', compact('methods'));
    }
    public function show(Request $request, Payment $payment) { return view('payment.show', compact('payment')); }
    public function pending(Request $request, Payment $payment) { return view('payment.pending', compact('payment')); }
}
