<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\UserIdentity;
use App\Services\AccountLifecycleService;
use App\Services\AuditLogService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Security settings, session management, login history, connected accounts
 * and the account lifecycle (Phase 14). Everything is self-service for the
 * authenticated user; admins have their own admin screens.
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
        protected IdentityService $identities,
        protected PhoneOtpService $otp,
        protected GoogleAuthService $google,
        protected SessionManagementService $sessions,
        protected LoginEventService $loginEvents,
        protected AccountLifecycleService $lifecycle,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
    ) {
    }

    // ------------------------------------------------------------------
    // Overview pages
    // ------------------------------------------------------------------

    public function security()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $identities = $this->identities->identitiesFor($user);

        return view('settings.security', [
            'user' => $user,
            'identities' => $identities,
            'hasPassword' => $this->profiles->hasPassword($user),
        ]);
    }

    public function connectedAccounts()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $identities = $this->identities->identitiesFor($user);

        return view('settings.connected-accounts', [
            'user' => $user,
            'identities' => $identities,
            'hasPassword' => $this->profiles->hasPassword($user),
            'googleConfigured' => $this->google->isConfigured(),
            'phoneConfigured' => app(\App\Contracts\PhoneOtpProviderInterface::class)->isConfigured(),
        ]);
    }

    public function sessions()
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        return view('settings.sessions', [
            'sessions' => $this->sessions->sessionsFor($user),
        ]);
    }

    public function loginHistory()
    {
        $user = auth()->user();
        $this->authorize('viewLoginHistory', $user);

        $events = $this->loginEvents->historyFor($user, 30);

        return view('settings.login-history', compact('events'));
    }

    // ------------------------------------------------------------------
    // Session revocation
    // ------------------------------------------------------------------

    public function revokeOtherSessions()
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        $this->sessions->revokeOtherSessions($user);

        return back()->with('success', 'All other sessions were signed out.');
    }

    public function revokeAllSessions(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        $this->sessions->revokeAllSessions($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You were signed out everywhere.');
    }

    // ------------------------------------------------------------------
    // Connected accounts — Google
    // ------------------------------------------------------------------

    public function linkGoogleRedirect(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        if ($this->identities->hasGoogle($user)) {
            return back()->with('error', 'Google is already connected to this account.');
        }

        $request->session()->put('google_link_intent', true);

        try {
            return $this->google->redirect();
        } catch (DomainException $e) {
            $request->session()->forget('google_link_intent');

            return back()->with('error', $e->getMessage());
        }
    }

    public function unlinkGoogle()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        try {
            $this->google->unlinkFromUser($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Google was disconnected from your account.');
    }

    // ------------------------------------------------------------------
    // Connected accounts — phone
    // ------------------------------------------------------------------

    public function linkPhone(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->issue($user, $phone, \App\Models\OtpChallenge::PURPOSE_LINK);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('phone.verify')
            ->with('phone', $phone)
            ->with('purpose', \App\Models\OtpChallenge::PURPOSE_LINK)
            ->with('success', 'We sent a verification code to that number.');
    }

    public function verifyPhoneLink(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'purpose' => 'required|in:login,signup,link,recovery',
            'code' => 'required|string|size:6',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->verify($user, $phone, $data['purpose'], $data['code']);
            $this->identities->linkPhone($user, $phone);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'phone',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_PHONE_LINKED,
            'Phone connected',
            'Your phone number was connected and verified.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.phone_verified', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return redirect()->route('settings.connected-accounts')->with('success', 'Phone connected and verified.');
    }

    public function unlinkPhone()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        try {
            $this->identities->unlink($user, UserIdentity::PROVIDER_PHONE);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_UNLINKED, LoginEvent::STATUS_SUCCESS, null, [
            'provider' => 'phone',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_PHONE_CHANGED,
            'Phone disconnected',
            'Your phone number was disconnected from your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.phone_unlinked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Phone disconnected.');
    }

    // ------------------------------------------------------------------
    // Account lifecycle (self-service)
    // ------------------------------------------------------------------

    public function deactivate(Request $request)
    {
        $user = auth()->user();
        $this->authorize('deactivate', $user);

        try {
            $this->lifecycle->deactivate($user, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Your account has been deactivated.');
    }

    public function reactivate()
    {
        $user = auth()->user();
        $this->authorize('reactivate', $user);

        try {
            $this->lifecycle->reactivate($user, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('settings.security')->with('success', 'Your account has been reactivated.');
    }

    public function requestDeletion()
    {
        $user = auth()->user();
        $this->authorize('requestDeletion', $user);

        try {
            $this->lifecycle->requestDeletion($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deletion requested. We will process it shortly.');
    }

    public function cancelDeletion()
    {
        $user = auth()->user();
        $this->authorize('requestDeletion', $user);

        try {
            $this->lifecycle->cancelDeletion($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deletion request cancelled.');
    }
}
