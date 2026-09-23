<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\AuditLogService;
use App\Services\IdentityService;
use App\Services\IdentityVerificationService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin account administration (Phase 14).
 *
 * Admins may inspect account status, verification state, linked providers,
 * restrictions, security events and payment methods, and may revoke sessions,
 * deactivate/reactivate and delete (anonymize) accounts. Organizers and
 * moderators get none of these global controls.
 */
class AdminAccountController extends Controller
{
    public function __construct(
        protected IdentityService $identities,
        protected IdentityVerificationService $identityVerification,
        protected SessionManagementService $sessions,
        protected AccountLifecycleService $lifecycle,
        protected AuditLogService $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('adminAccounts', User::class);

        $users = User::query()->orderByDesc('id');

        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            $users->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $role = (string) $request->query('role', '');
        if ($role !== '' && in_array($role, ['admin', 'organizer', 'moderator', 'player'], true)) {
            $users->where('role', $role);
        }

        $status = (string) $request->query('status', '');
        if ($status !== '' && in_array($status, ['active', 'deactivated', 'deletion_pending', 'deleted'], true)) {
            $users->where('account_status', $status);
        }

        $users = $users->paginate(25)->withQueryString();

        return view('admin.accounts.index', compact('users', 'q', 'role', 'status'));
    }

    public function show(User $user)
    {
        $this->authorize('adminAccount', $user);

        return view('admin.accounts.show', [
            'subject' => $user,
            'identities' => $this->identities->identitiesFor($user),
            'loginEvents' => $user->loginEvents()->limit(50)->get(),
            'sessions' => $this->sessions->sessionsFor($user),
            'restrictions' => $user->restrictions()->with('actor', 'liftedBy')->orderByDesc('id')->get(),
            'identity' => $this->identityVerification->effectiveStatus($user),
            'paymentMethods' => $user->paymentMethods()->orderByDesc('id')->get(),
            'riskProfile' => $user->riskProfile()->first(),
            'auditHistory' => $this->audit->relatedHistory('user', $user->id, 50),
        ]);
    }

    public function revokeSessions(User $user)
    {
        $this->authorize('adminRevokeSessions', $user);

        $count = $this->sessions->revokeAllForUser($user, auth()->user());

        return back()->with('success', "Revoked {$count} session(s) for {$user->name}.");
    }

    public function deactivate(User $user)
    {
        $this->authorize('adminDeactivate', $user);

        try {
            $this->lifecycle->deactivate($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Account deactivated.');
    }

    public function reactivate(User $user)
    {
        $this->authorize('adminDeactivate', $user);

        try {
            $this->lifecycle->reactivate($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Account reactivated.');
    }

    public function delete(User $user)
    {
        $this->authorize('adminDelete', $user);

        try {
            $this->lifecycle->executeDeletion($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.accounts.index')->with('success', 'Account deleted (anonymized).');
    }
}
