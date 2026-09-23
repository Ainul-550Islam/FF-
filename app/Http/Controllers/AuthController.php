<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\OtpChallenge;
use App\Models\RiskEvent;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\FraudRiskService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\IpIntelligenceService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected FraudRiskService $risk,
        protected PhoneOtpService $otp,
        protected IdentityService $identities,
        protected GoogleAuthService $google,
    ) {}

    // ------------------------------------------------------------------
    // Registration / login / logout
    // ------------------------------------------------------------------

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:60|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            'role' => 'required|in:player,organizer',
            'password' => 'required|min:6|confirmed',
        ]);

        // role is validated above (player|organizer only) and set explicitly —
        // it is NOT mass-assignable, so a client cannot self-register as admin.
        $user = new User();
        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->game_uid = $data['game_uid'] ?? null;
        $user->role = $data['role'];
        $user->password = Hash::make($data['password']);
        $user->account_status = 'active';
        $user->privacy = 'public';
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        // Phase 10 — record pseudonymous device + IP observations for the
        // new account (never throws; observation only).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        // Phase 13 — audit the signup.
        $this->audit->recordQuietly($user, 'auth.register', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['role' => $user->role],
        ]);

        // Phase 11 — welcome + (if unverified) email verification link.
        $this->notifications->send(
            $user,
            Notification::TYPE_WELCOME,
            'Welcome to FF Arena',
            'Your account was created. Verify your email to secure it.',
            NotificationService::link('home'),
        );

        $this->sendVerificationNotification($user);

        return redirect()->route('home')->with('success', 'Welcome to FF Arena, '.$user->name.'!');
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = $request->user();

            // Phase 10 — refresh pseudonymous device + IP observations.
            $this->devices->register($request, $user);
            $this->ipIntel->observe($request, $user);

            // Phase 14 — security history + risk signals.
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PASSWORD, LoginEvent::STATUS_SUCCESS, $request);

            if ($this->loginEvents->isNewDevice($request, $user)) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'auth', [
                    'context' => 'password_login_new_device',
                ]);

                $this->notifications->send(
                    $user,
                    Notification::TYPE_SUSPICIOUS_LOGIN,
                    'New device sign-in',
                    'Your account was just signed in from a new device.',
                    NotificationService::link('settings.sessions'),
                );
            }

            $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
                'target_user_id' => $user->id,
                'metadata' => ['provider' => 'password'],
            ]);

            return redirect()->intended(route('home'));
        }

        // Record the failed attempt against a matching account (internal
        // only — the email is never echoed to the visitor).
        $user = User::where('email', $credentials['email'])->first();
        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request);

        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGOUT, LoginEvent::STATUS_SUCCESS);
            $this->audit->recordQuietly($user, 'auth.logout', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return redirect()->route('home');
    }

    // ------------------------------------------------------------------
    // Password reset (enumeration-safe)
    // ------------------------------------------------------------------

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user !== null) {
            // Laravel's broker creates a short-lived token + sends the reset
            // email. Enumeration-safe: the visitor always gets the same
            // generic response whether or not the account exists.
            Password::sendResetLink(['email' => $user->email]);

            $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_RESET, LoginEvent::STATUS_SUCCESS, $request);

            $this->notifications->send(
                $user,
                Notification::TYPE_PASSWORD_RESET,
                'Password reset requested',
                'A password reset link was requested for your account.',
                NotificationService::link('login'),
            );

            $this->audit->recordQuietly($user, 'auth.password_reset', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return back()->with('success', 'If that email address is registered, we have sent a password reset link.');
    }

    public function showResetPassword(string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => request()->query('email', '')]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->password = $password; // hashed via the model cast
                $user->save();

                $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_RESET, LoginEvent::STATUS_SUCCESS, request());

                $this->notifications->send(
                    $user,
                    Notification::TYPE_PASSWORD_RESET,
                    'Password reset',
                    'Your password was reset successfully.',
                    NotificationService::link('login'),
                );

                $this->audit->recordQuietly($user, 'auth.password_reset', 'user', $user->id, [
                    'target_user_id' => $user->id,
                ]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or has expired.']);
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now sign in.');
    }

    // ------------------------------------------------------------------
    // Email verification (server-generated signed URLs)
    // ------------------------------------------------------------------

    public function showVerifyEmail()
    {
        $user = request()->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return view('auth.verify-email');
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'This verification link is invalid or has expired.');
        }

        $user = User::findOrFail($id);

        if (! hash_equals(sha1((string) $user->email), (string) $hash)) {
            abort(403, 'This verification link does not match the account.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();

            $this->loginEvents->record($user, LoginEvent::EVENT_EMAIL_VERIFIED, LoginEvent::STATUS_SUCCESS, $request);

            $this->notifications->send(
                $user,
                Notification::TYPE_EMAIL_VERIFY,
                'Email verified',
                'Your email address has been verified.',
                NotificationService::link('settings.security'),
            );

            $this->audit->recordQuietly($user, 'auth.email_verified', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return redirect()->route('home')->with('success', 'Your email address has been verified.');
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $this->sendVerificationNotification($user);

        return back()->with('success', 'A fresh verification link has been sent to your email.');
    }

    // ------------------------------------------------------------------
    // Phone login (OTP)
    // ------------------------------------------------------------------

    public function showPhoneLogin()
    {
        return view('auth.phone-login');
    }

    public function showPhoneVerify()
    {
        return view('auth.phone-verify', [
            'phone' => session('phone', old('phone')),
            'purpose' => session('purpose', old('purpose', 'login')),
        ]);
    }

    public function requestPhoneOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->issue(null, $phone, OtpChallenge::PURPOSE_LOGIN);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // Enumeration-safe: reveal nothing about whether the number is
        // registered; just proceed to code entry.
        return redirect()
            ->route('phone.verify')
            ->with('phone', $phone)
            ->with('purpose', OtpChallenge::PURPOSE_LOGIN)
            ->with('success', 'We sent a verification code to that number.');
    }

    public function verifyPhoneLogin(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'purpose' => 'required|in:login,signup,link,recovery',
            'code' => 'required|string|size:6',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->verify(null, $phone, $data['purpose'], $data['code']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $user = $this->identities->userFor(UserIdentity::PROVIDER_PHONE, $phone);

        if ($user === null) {
            // No account owns this verified number yet — invite sign-up
            // (never reveals an existing account's details).
            return redirect()->route('register')
                ->with('success', 'No account uses this number yet — create one to continue.')
                ->withInput(['phone' => $phone]);
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->with('error', 'This account is deactivated.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PHONE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'phone'],
        ]);

        return redirect()->intended(route('home'))->with('success', 'Signed in with your phone.');
    }

    // ------------------------------------------------------------------
    // Google Sign-In (OAuth / OIDC)
    // ------------------------------------------------------------------

    public function redirectToGoogle()
    {
        try {
            return $this->google->redirect();
        } catch (DomainException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }
    }

    public function handleGoogleCallback(Request $request)
    {
        // A signed-in user arriving here with the link intent is connecting
        // Google to their existing account (not signing in).
        if (auth()->check() && $request->session()->pull('google_link_intent') === true) {
            try {
                $this->google->linkToCurrentUser($request, auth()->user());
            } catch (DomainException $e) {
                return redirect()->route('settings.connected-accounts')->with('error', $e->getMessage());
            }

            return redirect()->route('settings.connected-accounts')->with('success', 'Google was connected to your account.');
        }

        try {
            $user = $this->google->handleCallback($request);
        } catch (DomainException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->with('error', 'This account is deactivated.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'))->with('success', 'Signed in with Google.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Send a server-generated, temporary signed verification URL through the
     * notification system (in-app + best-effort email).
     */
    protected function sendVerificationNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $minutes = (int) config('account.verification.verify_link_minutes', 60);
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1((string) $user->email),
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_EMAIL_VERIFY,
            'Verify your email',
            'Click the link to verify your email address for FF Arena.',
            $link,
        );
    }
}
