<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAccountController extends Controller
{
    public function index(Request $request): View
    {
        $q = (string) $request->input('q', '');
        $role = (string) $request->input('role', '');
        $status = (string) $request->input('status', '');

        $query = User::query();

        if ($q !== '') {
            // Case-insensitive on every dialect (PostgreSQL `like` is
            // case-sensitive, SQLite's is not) and wildcard-safe: the needle
            // is lowercased and LIKE metacharacters are escaped.
            $needle = '%'.mb_strtolower(addcslashes($q, '%_\\')).'%';
            $query->where(function ($sq) use ($needle) {
                $sq->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(username) like ?', [$needle])
                    ->orWhereRaw('lower(email) like ?', [$needle]);
            });
        }

        if ($role !== '') {
            $query->where('role', $role);
        }

        if ($status !== '') {
            $query->where('account_status', $status);
        }

        $users = $query->latest()->paginate(25)->withQueryString();

        return view('admin.accounts.index', compact('users', 'q', 'role', 'status'));
    }

    public function show(User $user): View
    {
        $subject = $user;
        $identities = $user->identities()->get();
        $identity = $user->identityVerification ?? (new IdentityVerification())->forceFill(['status' => 'unverified']);
        $riskProfile = $user->fraudRiskProfile;
        $restrictions = $user->restrictions()->get();
        $loginEvents = $user->loginEvents()->latest()->take(20)->get();
        $paymentMethods = $user->paymentMethods()->get();
        $auditHistory = AuditLog::query()
            ->where(function ($q) use ($user) {
                $q->where('target_user_id', $user->id)
                    ->orWhere(function ($sq) use ($user) {
                        $sq->where('entity_type', 'user')
                            ->where('entity_id', $user->id);
                    });
            })
            ->latest()
            ->take(20)
            ->get();

        return view('admin.accounts.show', compact(
            'subject',
            'identities',
            'identity',
            'riskProfile',
            'restrictions',
            'loginEvents',
            'paymentMethods',
            'auditHistory'
        ));
    }

    public function revokeSessions(User $user, SessionManagementService $service): RedirectResponse
    {
        $service->revokeAllForUser($user, auth()->user());

        return back()->with('success', 'User sessions revoked.');
    }

    public function deactivate(User $user, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->deactivate($user, auth()->user());

            return back()->with('success', 'Account deactivated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reactivate(User $user, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->reactivate($user, auth()->user());

            return back()->with('success', 'Account reactivated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function delete(User $user, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->executeDeletion($user, auth()->user());

            return back()->with('success', 'Account deleted.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
