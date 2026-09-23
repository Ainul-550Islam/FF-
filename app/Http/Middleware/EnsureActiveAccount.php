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

        if ($user && in_array($user->account_status ?? null, ['deactivated', 'deletion_pending', 'suspended'], true)) {
            // A deactivated or suspended account keeps access to its security
            // settings only (reactivation / deletion cancel / support live
            // there); everything else is bounced back to that screen.
            if (! $request->expectsJson() && ! $request->is('api/*') && ! $request->is('settings*') && ! $request->is('logout') && ! $request->is('login')) {
                return redirect()->route('settings.security')
                    ->with('warning', 'Your account is deactivated. Reactivate it from your security settings.');
            }

            return $next($request);
        }

        if ($user) {
            $isActive = true;
            if (method_exists($user, 'isActive')) {
                $isActive = $user->isActive();
            } elseif (isset($user->is_active)) {
                $isActive = (bool) $user->is_active;
            }

            if (! $isActive) {
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
