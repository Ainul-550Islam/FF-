<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PaymentService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

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

    public function creditWallet(Request $request, User $user, WalletService $wallets, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:1000000', // AUDIT FIX-09: cap manual adjustments (was unbounded — a typo could credit/debit crores).
            'reason' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $amountMinor = (int) round(((float) $data['amount']) * 100);
        $description = (string) ($data['description'] ?? $data['reason'] ?? 'Admin credit adjustment');

        // WalletService::credit(Wallet $wallet, int $amountMinor, string $type,
        // string $description, ?User $actor, ?string $referenceType,
        // ?int $referenceId). The previous call passed the wallet *id* as the
        // first argument, which the service interprets as the legacy
        // `credit($userId, …)` shape — the credit landed on the wrong wallet
        // (the player's balance stayed 0) and the description/actor/reference
        // arguments were shifted. Pass the resolved Wallet model and the
        // documented argument order instead.
        //
        // The audit row is written in the same transaction as the credit (and
        // with the loud record() API), so a manual wallet adjustment can never
        // happen without its audit trail.
        $actor = auth()->user();

        DB::transaction(function () use ($wallets, $user, $amountMinor, $description, $actor, $audit) {
            $wallets->credit(
                $wallets->walletFor($user),
                $amountMinor,
                LedgerEntry::TYPE_ADJUSTMENT,
                $description,
                $actor,
                'user',
                $user->id,
            );

            $audit->record($actor, 'wallet.credited', 'user', $user->id, [
                'target_user' => $user,
                'metadata' => [
                    'amount_minor' => $amountMinor,
                    'description' => $description,
                    'reference_type' => 'user',
                    'reference_id' => $user->id,
                ],
            ]);
        });

        return back()->with('success', 'Wallet credited.');
    }

    public function debitWallet(Request $request, User $user, WalletService $wallets, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:1000000', // AUDIT FIX-09: cap manual adjustments (was unbounded — a typo could credit/debit crores).
            'reason' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $amountMinor = (int) round(((float) $data['amount']) * 100);
        $description = (string) ($data['description'] ?? $data['reason'] ?? 'Admin debit adjustment');

        $actor = auth()->user();

        try {
            DB::transaction(function () use ($wallets, $user, $amountMinor, $description, $actor, $audit) {
                $wallets->debit(
                    $wallets->walletFor($user),
                    $amountMinor,
                    LedgerEntry::TYPE_ADJUSTMENT,
                    $description,
                    $actor,
                    'user',
                    $user->id,
                );

                $audit->record($actor, 'wallet.debited', 'user', $user->id, [
                    'target_user' => $user,
                    'metadata' => [
                        'amount_minor' => $amountMinor,
                        'description' => $description,
                        'reference_type' => 'user',
                        'reference_id' => $user->id,
                    ],
                ]);
            });

            return back()->with('success', 'Wallet debited.');
        } catch (DomainException|RuntimeException $e) {
            // Insufficient balance is an expected, user-correctable outcome —
            // surface it as a flashed error instead of an HTTP 500. The
            // wallet/ledger are untouched (the service rolls back).
            return back()->with('error', $e->getMessage());
        }
    }

    public function makeModerator(Request $request, AuditLogService $audit): RedirectResponse
    {
        // The admin dashboard form and the HTTP tests promote by *email*
        // (resources/views/admin/dashboard.blade.php posts `email`). Only
        // `user_id` used to be accepted, so a valid promotion silently failed
        // validation and the role never changed.
        $data = $request->validate([
            'email' => 'required_without:user_id|nullable|email|exists:users,email',
            'user_id' => 'required_without:email|nullable|integer|exists:users,id',
        ]);

        $user = isset($data['user_id'])
            ? User::findOrFail((int) $data['user_id'])
            : User::where('email', $data['email'])->firstOrFail();
        $before = $user->role;
        $user->role = 'moderator';
        $user->save();

        // AuditLog rows are append-only and `$fillable = []`; they may only be
        // written by AuditLogService (which whitelists the action, redacts the
        // payload and stamps the request id). The previous AuditLog::create()
        // call raised MassAssignmentException and the role change was never
        // audited.
        $audit->recordAdminAction(
            auth()->user(),
            'role.change',
            'user',
            $user->id,
            [
                'target_user' => $user,
                'before' => ['role' => $before],
                'after' => ['role' => 'moderator'],
            ],
        );

        return back()->with('success', "{$user->name} promoted to moderator.");
    }

    public function removeModerator(User $user, AuditLogService $audit): RedirectResponse
    {
        // Fail closed: this endpoint demotes *moderators*. An admin account is
        // never demoted through it (the demotion is refused and surfaced as a
        // flashed error, exactly as the security tests require).
        if ($user->role === 'admin') {
            return back()->with('error', 'Admin accounts cannot be demoted.');
        }

        $before = $user->role;
        $user->role = 'player';
        $user->save();

        $audit->recordAdminAction(
            auth()->user(),
            'role.change',
            'user',
            $user->id,
            [
                'target_user' => $user,
                'before' => ['role' => $before],
                'after' => ['role' => 'player'],
            ],
        );

        return back()->with('success', "{$user->name} demoted to player.");
    }
}
