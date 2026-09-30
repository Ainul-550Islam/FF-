<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle(GoogleAuthService $service)
    {
        try {
            return $service->redirect();
        } catch (\Throwable $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }
    }

    public function handleGoogleCallback(Request $request, GoogleAuthService $service)
    {
        try {
            if ($request->session()->pull('google_link_intent') && Auth::check()) {
                $service->linkToCurrentUser($request, Auth::user());

                return redirect()->route('settings.connected-accounts')->with('success', 'Google account linked.');
            }

            $user = $service->handleCallback($request);
            Auth::login($user);

            return redirect()->intended(route('home'));
        } catch (\Throwable $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }
    }

    public function redirect(GoogleAuthService $service)
    {
        return $this->redirectToGoogle($service);
    }

    public function callback(Request $request, GoogleAuthService $service)
    {
        return $this->handleGoogleCallback($request, $service);
    }
}
