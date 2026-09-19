<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'unauthorized', 'message' => 'Authentication required'], 401);
            }
            return redirect()->route('login')->with('error', 'Please login to access admin area');
        }

        $isAdmin = false;
        if (method_exists($user, 'isAdmin')) {
            $isAdmin = $user->isAdmin();
        } elseif (isset($user->is_admin)) {
            $isAdmin = (bool) $user->is_admin;
        }

        if (!$isAdmin) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'forbidden', 'message' => 'Admin access required'], 403);
            }
            abort(403, 'Admin access required');
        }

        return $next($request);
    }
}
