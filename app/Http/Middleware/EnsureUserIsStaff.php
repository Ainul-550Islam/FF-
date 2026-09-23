<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        // User::isStaff() already unions both generations (the is_staff /
        // is_admin flags and the admin / moderator roles), so the legacy
        // flag-only check would wrongly reject role-based moderators.
        if (! $user->isStaff()) {
            return response()->json(['error' => 'forbidden', 'message' => 'Staff required'], 403);
        }

        return $next($request);
    }
}
