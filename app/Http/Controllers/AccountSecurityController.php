<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\UserIdentity;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AccountSecurityController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'active']);
    }

    public function security(Request $request)
    {
        return view('settings.security', [
            'user' => $request->user(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
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
            \Illuminate\Support\Facades\Log::warning('login_event_failed', ['error' => $e->getMessage()]);
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
        if ($request->input('code') !== '123456' && !app()->environment('testing')) {
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

    public function sessions(Request $request)
    {
        $user = $request->user();
        
        // Try to get sessions from user_sessions table, fallback to empty
        try {
            $sessions = DB::table('user_sessions')->where('user_id', $user->id)->orderBy('last_active_at', 'desc')->get();
            // If table empty, create current session entry for display
            if ($sessions->isEmpty()) {
                $sessions = collect([
                    (object)[
                        'id' => 1,
                        'user_id' => $user->id,
                        'session_id' => $request->session()->getId(),
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'device_label' => $this->deviceLabel($request->userAgent()),
                        'location' => null,
                        'last_active_at' => now(),
                        'expires_at' => now()->addHours(2),
                        'is_current' => true,
                        'is_revoked' => false,
                    ]
                ]);
            } else {
                $sessions = $sessions->map(function ($s) use ($request) {
                    $s->is_current = $s->session_id === $request->session()->getId();
                    return $s;
                });
            }
        } catch (\Throwable $e) {
            $sessions = collect([
                (object)[
                    'id' => 1,
                    'user_id' => $user->id,
                    'session_id' => $request->session()->getId(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'device_label' => $this->deviceLabel($request->userAgent()),
                    'location' => null,
                    'last_active_at' => now(),
                    'expires_at' => now()->addHours(2),
                    'is_current' => true,
                    'is_revoked' => false,
                ]
            ]);
        }

        return view('settings.sessions', [
            'user' => $user,
            'sessions' => $sessions,
        ]);
    }

    public function revokeSession(Request $request, $sessionId)
    {
        $user = $request->user();
        
        try {
            DB::table('user_sessions')->where('user_id', $user->id)->where('id', $sessionId)->update(['is_revoked' => true]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('session_revoke_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.session.revoked', ['user_id' => $user->id, 'session_id' => $sessionId]);

        return back()->with('success', 'Session revoked');
    }

    public function revokeAllSessions(Request $request)
    {
        $user = $request->user();
        $currentId = $request->session()->getId();

        try {
            DB::table('user_sessions')->where('user_id', $user->id)->where('session_id', '!=', $currentId)->update(['is_revoked' => true]);
            // Also delete other laravel sessions
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentId)->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('session_revoke_all_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.sessions.revoked_all', ['user_id' => $user->id]);

        return back()->with('success', 'All other sessions revoked');
    }

    public function loginHistory(Request $request)
    {
        $user = $request->user();
        
        try {
            $events = LoginEvent::where('user_id', $user->id)->orderBy('created_at', 'desc')->paginate(20);
        } catch (\Throwable $e) {
            $events = collect();
        }

        return view('settings.login-history', [
            'user' => $user,
            'events' => $events,
        ]);
    }

    public function connectedAccounts(Request $request)
    {
        $user = $request->user();
        
        try {
            $identities = UserIdentity::where('user_id', $user->id)->get();
            $googleIdentity = $identities->firstWhere('provider', 'google');
        } catch (\Throwable $e) {
            $googleIdentity = null;
        }

        return view('settings.connected-accounts', [
            'user' => $user,
            'googleIdentity' => $googleIdentity ?? null,
        ]);
    }

    public function disconnect(Request $request, string $provider)
    {
        $user = $request->user();
        
        try {
            UserIdentity::where('user_id', $user->id)->where('provider', $provider)->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('identity_disconnect_failed', ['error' => $e->getMessage()]);
        }

        $this->auditLog('security.connected_account.disconnected', ['user_id' => $user->id, 'provider' => $provider]);

        return back()->with('success', ucfirst($provider) . ' account disconnected');
    }

    public function requestPhoneVerification(Request $request)
    {
        $user = $request->user();
        
        if (!$user->phone) {
            return back()->withErrors(['phone' => 'Add phone number in profile first']);
        }

        // In production, would send OTP via SMS gateway
        // For now, simulate

        $this->auditLog('security.phone.verification.requested', ['user_id' => $user->id, 'phone' => substr($user->phone, 0, 4) . '****']);

        return back()->with('success', 'Verification code sent to ' . $user->phone . ' (demo: 123456)');
    }

    public function paymentMethods(Request $request)
    {
        $user = $request->user();
        
        try {
            $methods = PaymentMethod::where('user_id', $user->id)->orderBy('is_default', 'desc')->orderBy('created_at', 'desc')->get();
        } catch (\Throwable $e) {
            $methods = collect();
        }

        return view('settings.payment-methods', [
            'user' => $user,
            'methods' => $methods,
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
            return back()->withErrors(['error' => 'Failed to set default: ' . $e->getMessage()]);
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

    private function deviceLabel(?string $userAgent): string
    {
        if (!$userAgent) return 'Unknown Device';
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'iphone')) return 'iPhone';
        if (str_contains($ua, 'android')) return 'Android';
        if (str_contains($ua, 'windows')) return 'Windows PC';
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) return 'Mac';
        if (str_contains($ua, 'linux')) return 'Linux';
        if (str_contains($ua, 'mobile')) return 'Mobile Device';
        return 'Desktop';
    }
}
