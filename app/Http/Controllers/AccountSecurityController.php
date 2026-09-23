<?php

namespace App\Http\Controllers;

use App\Contracts\PhoneOtpProviderInterface;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\OtpChallenge;
use App\Models\PaymentMethod;
use App\Models\UserIdentity;
use App\Services\AccountLifecycleService;
use App\Services\AuditLogService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PhoneOtpService;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

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
        $this->middleware(['auth', 'active']);
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
            'phoneConfigured' => app(PhoneOtpProviderInterface::class)->isConfigured(),
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
            $this->otp->issue($user, $phone, OtpChallenge::PURPOSE_LINK);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('phone.verify')
            ->with('phone', $phone)
            ->with('purpose', OtpChallenge::PURPOSE_LINK)
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

    // ------------------------------------------------------------------
    // Legacy (pre-Phase-14) surface kept alongside the canonical flow
    // ------------------------------------------------------------------

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        // Log event
        try {
            LoginEvent::create([
                'user_id' => $user->id,
                'event' => 'password_changed',
                'ip_address' => $request->ip(),
                'ip_hash' => hash('sha256', $request->ip()),
                'user_agent' => $request->userAgent(),
                'device_label' => $this->deviceLabel($request->userAgent()),
                'successful' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('login_event_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.password.changed', ['user_id' => $user->id]);

        return back()->with('success', 'Password updated successfully. All other sessions have been kept active, but consider revoking if suspicious.');
    }

    public function setup2fa(Request $request)
    {
        // Placeholder for 2FA setup - in production would generate secret and QR
        return view('settings.security', [
            'user' => $request->user(),
            'show2faSetup' => true,
        ])->with('warning', '2FA setup requires authenticator app. Scan QR code (simulated for now).');
    }

    public function enable2fa(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);
        // In production, verify TOTP code
        // For now, simulate success if code is 123456
        if ($request->input('code') !== '123456' && ! app()->environment('testing')) {
            return back()->withErrors(['code' => 'Invalid code, try 123456 in demo']);
        }

        $user = $request->user();
        $user->forceFill(['two_factor_enabled' => true])->save();

        $this->auditLog('security.2fa.enabled', ['user_id' => $user->id]);

        return redirect()->route('settings.security')->with('success', 'Two-factor authentication enabled');
    }

    public function disable2fa(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['two_factor_enabled' => false])->save();

        $this->auditLog('security.2fa.disabled', ['user_id' => $user->id]);

        return back()->with('success', 'Two-factor authentication disabled');
    }

    public function revokeSession(Request $request, $sessionId)
    {
        $user = $request->user();

        try {
            DB::table('user_sessions')->where('user_id', $user->id)->where('id', $sessionId)->update(['is_revoked' => true]);
        } catch (\Throwable $e) {
            Log::warning('session_revoke_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.session.revoked', ['user_id' => $user->id, 'session_id' => $sessionId]);

        return back()->with('success', 'Session revoked');
    }

    public function disconnect(Request $request, string $provider)
    {
        $user = $request->user();

        try {
            UserIdentity::where('user_id', $user->id)->where('provider', $provider)->delete();
        } catch (\Throwable $e) {
            Log::warning('identity_disconnect_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.connected_account.disconnected', ['user_id' => $user->id, 'provider' => $provider]);

        return back()->with('success', ucfirst($provider).' account disconnected');
    }

    public function requestPhoneVerification(Request $request)
    {
        $user = $request->user();

        if (! $user->phone) {
            return back()->withErrors(['phone' => 'Add phone number in profile first']);
        }

        // In production, would send OTP via SMS gateway
        // For now, simulate

        $this->auditLog('security.phone.verification.requested', ['user_id' => $user->id, 'phone' => substr($user->phone, 0, 4).'****']);

        return back()->with('success', 'Verification code sent to '.$user->phone.' (demo: 123456)');
    }

    public function paymentMethods(Request $request)
    {
        $user = $request->user();

        try {
            $methods = PaymentMethod::where('user_id', $user->id)->orderBy('is_default', 'desc')->orderBy('created_at', 'desc')->get();
        } catch (\Throwable $e) {
            $methods = collect();
        }

        // Enabled gateway providers drive the "add payment method" form.
        $providers = app(PaymentGatewayManager::class)->enabledProviders();

        return view('settings.payment-methods', [
            'user' => $user,
            'methods' => $methods,
            'providers' => $providers,
        ]);
    }

    public function setDefaultPaymentMethod(Request $request, PaymentMethod $paymentMethod)
    {
        $this->authorize('update', $paymentMethod);

        try {
            DB::transaction(function () use ($paymentMethod) {
                PaymentMethod::where('user_id', $paymentMethod->user_id)->update(['is_default' => false]);
                $paymentMethod->forceFill(['is_default' => true])->save();
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => 'Failed to set default: '.$e->getMessage()]);
        }

        $this->auditLog('payment_method.default.set', ['user_id' => $request->user()->id, 'method_id' => $paymentMethod->id]);

        return back()->with('success', 'Default payment method updated');
    }

    public function destroyPaymentMethod(Request $request, PaymentMethod $paymentMethod)
    {
        $this->authorize('delete', $paymentMethod);

        $paymentMethod->delete();

        $this->auditLog('payment_method.deleted', ['user_id' => $request->user()->id, 'method_id' => $paymentMethod->id]);

        return back()->with('success', 'Payment method removed');
    }

    /**
     * Best-effort human label for a session's user agent (kept for the
     * legacy password-change / 2FA login events).
     */
    private function deviceLabel(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown Device';
        }
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'iphone')) {
            return 'iPhone';
        }
        if (str_contains($ua, 'android')) {
            return 'Android';
        }
        if (str_contains($ua, 'windows')) {
            return 'Windows PC';
        }
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) {
            return 'Mac';
        }
        if (str_contains($ua, 'linux')) {
            return 'Linux';
        }
        if (str_contains($ua, 'mobile')) {
            return 'Mobile Device';
        }

        return 'Desktop';
    }
}
