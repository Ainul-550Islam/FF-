<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Payout;
use App\Services\IdentityVerificationService;
use App\Services\WalletService;
use Illuminate\Http\Request;

/**
 * The authenticated user's wallet: balance, ledger history, payment history
 * (Phase 08), their own prize-payout history (Phase 09) and identity
 * verification status (Phase 10).
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
        protected IdentityVerificationService $identity,
    ) {}

    public function index()
    {
        $user = auth()->user();
        $wallet = $this->wallets->walletFor($user);
        $identity = $this->identity->effectiveStatus($user);

        $ledger = $wallet->ledgerEntries()->with('actor')->limit(100)->get();

        $payments = Payment::query()
            ->where(function ($q) use ($user) {
                $q->where('payer_user_id', $user->id)
                    ->orWhereHas('team', fn ($t) => $t->where('captain_id', $user->id));
            })
            ->with(['tournament', 'team', 'refund'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Only the authenticated user's own payouts are ever shown.
        $payouts = Payout::query()
            ->where('recipient_user_id', $user->id)
            ->with(['tournament', 'team'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('wallet.index', compact('wallet', 'ledger', 'payments', 'payouts', 'identity'));
    }

    /**
     * Full ledger view (same wallet page — `/wallet/ledger`).
     */
    public function ledger(Request $request)
    {
        return $this->index();
    }
}
