<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if ($user) {
            $isActive = true;
            if (method_exists($user, 'isActive')) {
                $isActive = $user->isActive();
            } elseif (isset($user->is_active)) {
                $isActive = (bool) $user->is_active;
            }

            if (!$isActive) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['error' => 'account_inactive', 'message' => 'Account is inactive or banned'], 403);
                }
                // For web, logout and redirect with message
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login')->with('error', 'Your account is inactive or banned. Contact support.');
            }
        }

        return $next($request);
    }
}
