<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');
        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();
            try {
                LoginEvent::create(['user_id' => Auth::id(), 'event' => 'login', 'ip_address' => $request->ip(), 'ip_hash' => hash('sha256', $request->ip()), 'user_agent' => $request->userAgent(), 'device_label' => str_contains(strtolower($request->userAgent()), 'mobile') ? 'Mobile' : 'Desktop', 'successful' => true]);
            } catch (\Throwable $e) {
            }

            return redirect()->intended(route('home'))->with('success', 'Welcome back!');
        }
        try {
            LoginEvent::create(['user_id' => null, 'event' => 'failed', 'ip_address' => $request->ip(), 'ip_hash' => hash('sha256', $request->ip()), 'user_agent' => $request->userAgent(), 'successful' => false, 'metadata' => ['email' => $request->input('email')]]);
        } catch (\Throwable $e) {
        }

        return back()->withErrors(['email' => 'Invalid credentials'])->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Logged out');
    }

    public function showForgotForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        return back()->with('success', 'Reset link sent if email exists');
    }

    public function showResetForm($token)
    {
        return view('auth.reset-password', compact('token'));
    }

    public function resetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required|confirmed|min:8', 'token' => 'required|string']);

        return redirect()->route('login')->with('success', 'Password reset');
    }
}
