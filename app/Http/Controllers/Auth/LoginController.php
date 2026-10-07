<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\IpIntelligenceService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Phase 14 — web (session) authentication: login, logout, password reset.
 *
 * This controller used to be a stub: it wrote `login_events` rows with the
 * wrong event/status vocabulary (event `login` / `failed`, column
 * `successful`), never resolved the failing account, and its password reset
 * returned success without touching the password. It now runs the same
 * account engine as the API (`LoginEventService`, `AuditLogService`,
 * `DeviceFingerprintService`, `IpIntelligenceService`,
 * `NotificationService`) — there is no second account system.
 */
class LoginController extends Controller
{
    public function __construct(
        protected LoginEventService $loginEvents,
        protected AuditLogService $audit,
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
        protected NotificationService $notifications,
    ) {}

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * POST /login
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $remember = $request->boolean('remember');
        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], (string) $user->password)) {
            // Enumeration-safe: the caller always sees the same message, but
            // when the address belongs to an account the failure is recorded
            // against it (security timeline, abuse detection).
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request);

            return back()->withErrors(['email' => 'Invalid credentials'])->withInput();
        }

        if (! $user->isActive()) {
            // Deactivated/suspended accounts are rejected with the same
            // message as a bad password so the state is not disclosed.
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request, [
                'reason' => 'account_inactive',
            ]);

            return back()->withErrors(['email' => 'Invalid credentials'])->withInput();
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        // Phase 10 — pseudonymous device/IP observations (never throws).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PASSWORD, LoginEvent::STATUS_SUCCESS, $request);

        if ($this->loginEvents->isNewDevice($request, $user)) {
            $this->notifications->send(
                $user,
                \App\Models\Notification::TYPE_SUSPICIOUS_LOGIN,
                'New device sign-in',
                'Your account was just signed in from a new device.',
                NotificationService::link('settings.sessions'),
            );
        }

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'password', 'via' => 'web'],
        ]);

        return redirect()->intended(route('home'))->with('success', 'Welcome back!');
    }

    /**
     * POST /logout
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGOUT, LoginEvent::STATUS_SUCCESS, $request);
            $this->audit->recordQuietly($user, 'auth.logout', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Logged out');
    }

    public function showForgotForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * POST /forgot-password — enumeration-safe: the response never reveals
     * whether the address exists.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::broker()->sendResetLink($request->only('email'));

        return back()->with('success', 'If that email exists, a reset link has been sent.');
    }

    public function showResetForm(Request $request, ?string $token = null): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * POST /reset-password — actually resets the password through the
     * password broker (an invalid/expired token is rejected with a session
     * error on `email`, never a silent success).
     */
    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => null,
                ])->save();

                $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_RESET, LoginEvent::STATUS_SUCCESS, request());
                $this->audit->recordQuietly($user, 'auth.password_reset', 'user', $user->id, [
                    'target_user_id' => $user->id,
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Password reset');
        }

        return back()->withErrors(['email' => __($status)])->withInput($request->only('email'));
    }
}
