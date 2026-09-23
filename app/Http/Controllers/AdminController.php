<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected WalletService $wallets,
        protected FraudRiskService $risk,
        protected AuditLogService $audit,
    ) {}

    public function dashboard()
    {
        $stats = [
            'tournaments' => Tournament::count(),
            'teams' => Team::count(),
            'verified_payments' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->count(),
            'revenue' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount'),
            'commission' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount') * 0.08,
        ];

        $pendingPayments = Payment::with(['team', 'tournament'])->where('status', 'pending')->latest()->limit(20)->get();
        $moderators = User::where('role', 'moderator')->orderBy('name')->get();

        return view('admin.dashboard', compact('stats', 'pendingPayments', 'moderators'));
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Payment list with status/tournament filters.
     */
    public function payments(Request $request)
    {
        $payments = Payment::query()
            ->with(['team', 'tournament', 'payer', 'refund'])
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $payments->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $payments->where('tournament_id', $tournamentId);
        }

        $payments = $payments->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);
        $statuses = [
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING,
            Payment::STATUS_PAID,
            Payment::STATUS_VERIFIED,
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ];

        return view('admin.payments', compact('payments', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    /**
     * Verify a pending payment (manual bKash verification) and confirm the
     * team — the legacy admin flow, now routed through the PaymentService.
     */
    public function verifyPayment(Payment $payment)
    {
        try {
            $this->payments->verifyManually($payment, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payment.verified', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
        ]);

        return back()->with('success', 'Payment verified. Team confirmed.');
    }

    /**
     * Fail a pending payment.
     */
    public function failPayment(Request $request, Payment $payment)
    {
        $reason = (string) $request->input('reason', '');

        try {
            $this->payments->markFailed($payment, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Phase 10 — record a failed-payment signal for the payer (additive;
        // never mutates the Phase 08 payment/wallet state).
        $this->risk->recordPaymentFailure($payment);

        $this->audit->recordQuietly(auth()->user(), 'payment.failed', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payment marked as failed.');
    }

    /**
     * Refund a settled payment (full amount), crediting the payer's wallet.
     */
    public function refundPayment(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $refund = $this->payments->refund($payment, auth()->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payment.refunded', 'payment', $payment->id, [
            'tournament_id' => $payment->tournament_id,
            'metadata' => ['amount_minor' => $refund->amount_minor],
        ]);

        return back()->with('success', 'Payment refunded (৳'.Money::toDecimal($refund->amount_minor).' credited to the payer).');
    }

    // ------------------------------------------------------------------
    // Wallets + ledger
    // ------------------------------------------------------------------

    /**
     * A user's wallet with its ledger history.
     */
    public function wallet(User $user)
    {
        $wallet = $this->wallets->walletFor($user);
        $ledger = $wallet->ledgerEntries()->with('actor')->limit(200)->get();
        $delta = $this->wallets->reconciliationDelta($wallet);

        return view('admin.wallet', compact('user', 'wallet', 'ledger', 'delta'));
    }

    /**
     * Credit a user's wallet (admin manual credit/deposit).
     */
    public function creditWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->credit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'wallet.credited', 'wallet', $wallet->id, [
            'target_user_id' => $user->id,
            'metadata' => ['amount_minor' => $minor],
        ]);

        return back()->with('success', 'Wallet credited.');
    }

    /**
     * Debit a user's wallet (admin manual adjustment).
     */
    public function debitWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->debit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'wallet.debited', 'wallet', $wallet->id, [
            'target_user_id' => $user->id,
            'metadata' => ['amount_minor' => $minor],
        ]);

        return back()->with('success', 'Wallet debited.');
    }

    // ------------------------------------------------------------------
    // Moderation roles (Phase 07)
    // ------------------------------------------------------------------

    /**
     * Promote a user to moderator (admin only — the route sits behind the
     * `admin` middleware, and `role` is never mass-assignable).
     */
    public function makeModerator(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isAdmin()) {
            return back()->with('error', 'Admins are already staff.');
        }

        if ($user->isModerator()) {
            return back()->with('error', $user->name.' is already a moderator.');
        }

        $previousRole = $user->role;

        $user->role = 'moderator';
        $user->save();

        $this->audit->recordQuietly(auth()->user(), 'role.change', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['role' => $previousRole],
            'after' => ['role' => 'moderator'],
        ]);

        return back()->with('success', $user->name.' is now a moderator.');
    }

    /**
     * Demote a moderator back to a regular player (admin only).
     */
    public function removeModerator(User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot demote an admin.');
        }

        if (! $user->isModerator()) {
            return back()->with('error', 'This user is not a moderator.');
        }

        $previousRole = $user->role;

        $user->role = 'player';
        $user->save();

        $this->audit->recordQuietly(auth()->user(), 'role.change', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['role' => $previousRole],
            'after' => ['role' => 'player'],
        ]);

        return back()->with('success', $user->name.' is no longer a moderator.');
    }
}
