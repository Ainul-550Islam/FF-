<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PhoneAuthController extends Controller
{
    public function showPhoneForm()
    {
        return view('auth.phone-login');
    }

    public function requestOtp(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        return redirect()->route('auth.phone.verify.form')->with('success', 'OTP sent (demo: 123456)');
    }

    public function showVerifyForm()
    {
        return view('auth.phone-verify');
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['phone' => 'required|string', 'code' => 'required|string']);
        if ($request->input('code') !== '123456' && ! app()->environment('testing')) {
            return back()->withErrors(['code' => 'Invalid code, use 123456 in demo']);
        }

        return redirect()->route('home')->with('success', 'Phone verified');
    }
}
