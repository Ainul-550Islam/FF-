<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\PaymentMethod;
use App\Services\AccountLifecycleService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\PaymentMethodService;
use App\Services\PhoneOtpService;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountSecurityController extends Controller
{
    public function security(Request $request, IdentityService $identities, ProfileService $profiles): View
    {
        $user = $request->user();

        return view('settings.security', [
            'user' => $user,
            'identities' => $identities->identitiesFor($user),
            'hasPassword' => $profiles->hasPassword($user),
        ]);
    }

    public function updatePassword(Request $request, ProfileService $profiles): RedirectResponse
    {
        $user = $request->user();
        $hasPassword = $profiles->hasPassword($user);

        $rules = [
            'password' => 'required|string|min:8|confirmed',
        ];

        if ($hasPassword) {
            $rules['current_password'] = 'required|string';
        }

        $request->validate($rules);

        try {
            if ($hasPassword) {
                $profiles->changePassword($user, (string) $request->input('current_password'), (string) $request->input('password'), $request);
            } else {
                $profiles->setPassword($user, (string) $request->input('password'), $request);
            }

            return back()->with('success', 'Password updated successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setup2fa(Request $request): View
    {
        return view('settings.security', [
            'user' => $request->user(),
            'identities' => app(IdentityService::class)->identitiesFor($request->user()),
            'hasPassword' => app(ProfileService::class)->hasPassword($request->user()),
        ]);
    }

    public function enable2fa(Request $request): RedirectResponse
    {
        return back()->with('success', 'Two-factor authentication enabled.');
    }

    public function disable2fa(Request $request): RedirectResponse
    {
        return back()->with('success', 'Two-factor authentication disabled.');
    }

    public function sessions(Request $request, SessionManagementService $service): View
    {
        return view('settings.sessions', [
            'sessions' => $service->sessionsFor($request->user()),
        ]);
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $user = $request->user();

        DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $user->id)
            ->delete();

        return back()->with('success', 'Session revoked.');
    }

    public function revokeAllSessions(Request $request, SessionManagementService $service): RedirectResponse
    {
        $user = $request->user();
        $service->revokeAllSessions($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function revokeOtherSessions(Request $request, SessionManagementService $service): RedirectResponse
    {
        $service->revokeOtherSessions($request->user());

        return back()->with('success', 'All other sessions have been signed out.');
    }

    public function linkGoogleRedirect(Request $request, GoogleAuthService $google): RedirectResponse
    {
        $request->session()->put('google_link_intent', true);

        return $google->redirect();
    }

    public function unlinkGoogle(Request $request, GoogleAuthService $google): RedirectResponse
    {
        try {
            $google->unlinkFromUser($request->user());

            return back()->with('success', 'Google Sign-In was disconnected from your account.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function linkPhone(Request $request, PhoneOtpService $otp): RedirectResponse
    {
        $request->validate(['phone' => 'required|string']);

        try {
            $otp->issue($request->user(), (string) $request->input('phone'), 'link');
            $request->session()->put('link_phone_number', (string) $request->input('phone'));

            return redirect()->route('phone.verify');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function verifyPhoneLink(Request $request, PhoneOtpService $otp, IdentityService $identities): RedirectResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
        ]);

        try {
            $challenge = $otp->verify(
                $request->user(),
                (string) $request->input('phone'),
                (string) $request->input('purpose', 'link'),
                (string) $request->input('code'),
            );

            $identities->linkPhone($request->user(), $challenge->phone);

            return redirect()->route('settings.connected-accounts')->with('success', 'Phone number linked.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function unlinkPhone(Request $request, IdentityService $identities): RedirectResponse
    {
        try {
            $identities->unlink($request->user(), 'phone');

            return back()->with('success', 'Phone number disconnected.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deactivate(Request $request, AccountLifecycleService $lifecycle): RedirectResponse
    {
        $user = $request->user();

        try {
            $lifecycle->deactivate($user, $user);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reactivate(Request $request, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->reactivate($request->user(), $request->user());

            return back()->with('success', 'Account reactivated successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function requestDeletion(Request $request, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->requestDeletion($request->user());

            return back()->with('success', 'Account deletion requested.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancelDeletion(Request $request, AccountLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->cancelDeletion($request->user());

            return back()->with('success', 'Account deletion request cancelled.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function loginHistory(Request $request): View
    {
        $events = LoginEvent::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('settings.login-history', compact('events'));
    }

    public function connectedAccounts(
        Request $request,
        IdentityService $identities,
        ProfileService $profiles,
        GoogleAuthService $google,
    ): View {
        $user = $request->user();

        return view('settings.connected-accounts', [
            'user' => $user,
            'identities' => $identities->identitiesFor($user),
            'hasPassword' => $profiles->hasPassword($user),
            'googleConfigured' => $google->isConfigured(),
            'phoneConfigured' => true,
        ]);
    }

    public function disconnect(Request $request, string $provider, IdentityService $identities, GoogleAuthService $google): RedirectResponse
    {
        try {
            if ($provider === 'google') {
                $google->unlinkFromUser($request->user());
            } else {
                $identities->unlink($request->user(), $provider);
            }

            return back()->with('success', ucfirst($provider).' disconnected.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function requestPhoneVerification(Request $request, PhoneOtpService $otp): RedirectResponse
    {
        $phone = $request->user()->phone ?? (string) $request->input('phone');

        if (! $phone) {
            return back()->with('error', 'No phone number provided.');
        }

        try {
            $otp->issue($request->user(), $phone, 'verify');

            return redirect()->route('phone.verify');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function paymentMethods(Request $request, PaymentMethodService $service): View
    {
        $providers = [
            ['id' => 'bkash', 'label' => 'bKash'],
            ['id' => 'nagad', 'label' => 'Nagad'],
            ['id' => 'rocket', 'label' => 'Rocket'],
            ['id' => 'card', 'label' => 'Card'],
            ['id' => 'bank', 'label' => 'Bank'],
        ];

        return view('settings.payment-methods', [
            'methods' => $service->listFor($request->user()),
            'providers' => $providers,
        ]);
    }

    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod, PaymentMethodService $service): RedirectResponse
    {
        try {
            $service->setDefault(auth()->user(), $paymentMethod);

            return back()->with('success', 'Default payment method set.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroyPaymentMethod(PaymentMethod $paymentMethod, PaymentMethodService $service): RedirectResponse
    {
        try {
            $service->remove(auth()->user(), $paymentMethod);

            return back()->with('success', 'Payment method removed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
