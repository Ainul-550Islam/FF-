<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\LedgerEntry;
use App\Models\Payout;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $wallets = Wallet::where('user_id',$user->id)->get();
        $primary = $wallets->firstWhere('currency','BDT') ?? $wallets->first();
        return response()->json(['data'=>['wallets'=>$wallets,'primary'=>$primary,'total_minor'=>$wallets->sum('balance_minor')]]);
    }

    public function ledger(Request $request)
    {
        $user = $request->user();
        $entries = LedgerEntry::where('user_id',$user->id)->orderBy('created_at','desc')->paginate(20);
        return response()->json(['data'=>$entries->items(),'meta'=>['total'=>$entries->total()]]);
    }

    public function payouts(Request $request)
    {
        $user = $request->user();
        $payouts = Payout::where('user_id',$user->id)->orderBy('created_at','desc')->paginate(20);
        return response()->json(['data'=>$payouts->items(),'meta'=>['total'=>$payouts->total()]]);
    }
}
