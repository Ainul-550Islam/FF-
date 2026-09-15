<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Platform staff only (admin or moderator). Distinct from the `admin`
 * middleware: staff can work the moderation/support queues but never gain
 * financial or global-security administration powers.
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->isStaff()) {
            abort(403, 'Staff access only.');
        }

        return $next($request);
    }
}
