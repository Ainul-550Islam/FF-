<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 14 — blocks deactivated/deleted accounts from the authenticated
 * area, except the security-settings page (where they can reactivate) and
 * logout. Guests pass through untouched.
 */
class EnsureActiveAccount
{
    /**
     * Route names a deactivated/deletion-pending user may still visit.
     */
    protected array $allow = [
        'settings.security',
        'settings.reactivate',
        'settings.deletion.cancel',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            foreach ($this->allow as $name) {
                if ($request->routeIs($name)) {
                    return $next($request);
                }
            }

            // Deactivated/deleted accounts are parked on their security
            // settings, where they can reactivate (or simply sign out).
            return redirect()->route('settings.security');
        }

        return $next($request);
    }
}
