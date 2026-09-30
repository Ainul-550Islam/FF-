<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $revenue = (float) (Payment::where('status', 'completed')->sum('amount_minor') / 100);
        $commission = $revenue * 0.08;

        $stats = [
            'tournaments' => Tournament::count(),
            'teams' => Team::count(),
            'verified_payments' => Payment::where('status', 'completed')->count(),
            'revenue' => $revenue,
            'commission' => $commission,
            'users' => User::count(),
            'payouts' => Payout::where('status', 'completed')->count(),
        ];

        $moderators = User::where('role', 'moderator')->orderBy('name')->get();
        $pendingPayments = Payment::with(['tournament', 'team'])->where('status', 'pending')->latest()->take(10)->get();

        return view('admin.dashboard', compact('stats', 'moderators', 'pendingPayments'));
    }

    public function payments(Request $request): View
    {
        $status = $request->input('status');
        $tournamentId = $request->input('tournament_id') ? (int) $request->input('tournament_id') : null;

        $statuses = ['pending', 'processing', 'completed', 'failed', 'refunded'];
        $tournaments = Tournament::query()->orderBy('name')->get();

        $payments = Payment::query()
            ->with(['tournament', 'team', 'payer'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tournamentId, fn ($q) => $q->where('tournament_id', $tournamentId))
            ->latest()
            ->paginate(25);

        return view('admin.payments', compact('payments', 'statuses', 'tournaments', 'status', 'tournamentId'));
    }

    public function payouts(): RedirectResponse
    {
        return redirect()->route('admin.payouts.index');
    }

    public function verifyPayment(Payment $payment, PaymentService $service): RedirectResponse
    {
        try {
            $service->verifyManually($payment, auth()->user());

            return back()->with('success', 'Payment verified successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function failPayment(Payment $payment, PaymentService $service): RedirectResponse
    {
        try {
            $service->markFailed($payment, auth()->user(), 'Marked failed by admin');

            return back()->with('success', 'Payment marked as failed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function refundPayment(Request $request, Payment $payment, PaymentService $service): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:255']);

        try {
            $service->refund($payment, auth()->user(), (string) $request->input('reason'));

            return back()->with('success', 'Payment refunded successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function wallet(?User $user = null, ?WalletService $wallets = null): View
    {
        $user = $user ?? auth()->user();
        $wallets = $wallets ?? app(WalletService::class);

        $wallet = $wallets->walletFor($user);
        $delta = $wallets->reconciliationDelta($wallet);
        $ledger = $wallets->listLedger($wallet->id);

        return view('admin.wallet', compact('user', 'wallet', 'delta', 'ledger'));
    }

    public function creditWallet(Request $request, User $user, WalletService $wallets): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $amountMinor = (int) round(((float) $request->input('amount')) * 100);
        $wallet = $wallets->walletFor($user);

        $wallets->credit(
            $wallet->id,
            $amountMinor,
            'admin_adjustment',
            'user',
            $user->id,
            (string) ($request->input('reason') ?: 'Admin credit adjustment'),
            auth()->id(),
        );

        return back()->with('success', 'Wallet credited.');
    }

    public function debitWallet(Request $request, User $user, WalletService $wallets): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $amountMinor = (int) round(((float) $request->input('amount')) * 100);
        $wallet = $wallets->walletFor($user);

        try {
            $wallets->debit(
                $wallet->id,
                $amountMinor,
                'admin_adjustment',
                'user',
                $user->id,
                (string) ($request->input('reason') ?: 'Admin debit adjustment'),
                auth()->id(),
            );

            return back()->with('success', 'Wallet debited.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function makeModerator(Request $request): RedirectResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $before = $user->role;
        $user->role = 'moderator';
        $user->save();

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.role_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'before' => ['role' => $before],
            'after' => ['role' => 'moderator'],
        ]);

        return back()->with('success', "{$user->name} promoted to moderator.");
    }

    public function removeModerator(User $user): RedirectResponse
    {
        $before = $user->role;
        $user->role = 'player';
        $user->save();

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.role_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'before' => ['role' => $before],
            'after' => ['role' => 'player'],
        ]);

        return back()->with('success', "{$user->name} demoted to player.");
    }
}
