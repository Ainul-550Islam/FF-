<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\LedgerEntry;

class WalletController extends Controller
{
    public function __construct() { $this->middleware(['auth','active']); }

    public function index(Request $request)
    {
        $user = $request->user();
        $wallets = Wallet::where('user_id', $user->id)->get();
        $primary = $wallets->firstWhere('currency','BDT') ?? $wallets->first();
        $ledger = LedgerEntry::where('user_id', $user->id)->orderBy('created_at','desc')->limit(20)->get();
        return view('wallet.index', compact('wallets','primary','ledger','user'));
    }

    public function ledger(Request $request)
    {
        $user = $request->user();
        $entries = LedgerEntry::where('user_id', $user->id)->orderBy('created_at','desc')->paginate(20);
        return view('wallet.index', ['wallets' => Wallet::where('user_id',$user->id)->get(), 'primary' => null, 'ledger' => $entries, 'user' => $user]);
    }
}
