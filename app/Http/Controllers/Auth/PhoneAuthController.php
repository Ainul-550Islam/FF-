<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\OtpChallenge;
use App\Models\UserIdentity;
use App\Services\LoginEventService;
use App\Services\PhoneOtpService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhoneAuthController extends Controller
{
    public function showPhoneForm()
    {
        return view('auth.phone-login');
    }

    public function showPhoneLogin()
    {
        return $this->showPhoneForm();
    }

    public function showVerifyForm()
    {
        return view('auth.phone-verify');
    }

    public function showPhoneVerify()
    {
        return $this->showVerifyForm();
    }

    public function requestOtp(Request $request, PhoneOtpService $otp)
    {
        $request->validate(['phone' => 'required|string']);

        try {
            $otp->issue(null, $request->input('phone'), OtpChallenge::PURPOSE_LOGIN);
        } catch (\Throwable $e) {
        }

        return redirect()->route('phone.verify')->with('phone', $request->input('phone'))->with('success', 'OTP sent');
    }

    public function requestPhoneOtp(Request $request, PhoneOtpService $otp)
    {
        return $this->requestOtp($request, $otp);
    }

    public function verifyOtp(Request $request, PhoneOtpService $otp, LoginEventService $loginEvents)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
        ]);

        $purpose = $request->input('purpose', OtpChallenge::PURPOSE_LOGIN);

        try {
            $challenge = $otp->verify(null, $request->input('phone'), $purpose, $request->input('code'));
            $normalized = $otp->normalize($request->input('phone'));

            $identity = UserIdentity::where('provider', 'phone')
                ->where('provider_subject', $normalized)
                ->first();

            if (! $identity || ! $identity->user) {
                return redirect()->route('phone.verify')->withErrors(['phone' => 'No account associated with this phone.']);
            }

            $user = $identity->user;
            Auth::login($user);

            $loginEvents->record(
                $user,
                LoginEvent::EVENT_LOGIN_PHONE,
                LoginEvent::STATUS_SUCCESS,
                $request,
                ['phone' => $normalized]
            );

            return redirect()->intended(route('home'));
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
    }

    public function verifyPhoneLogin(Request $request, PhoneOtpService $otp, LoginEventService $loginEvents)
    {
        return $this->verifyOtp($request, $otp, $loginEvents);
    }
}
