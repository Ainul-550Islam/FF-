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
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (method_exists($user, 'isStaff') && !$user->isStaff()) {
            return response()->json(['error' => 'forbidden', 'message' => 'Staff required'], 403);
        }
        if (isset($user->is_staff) && !$user->is_staff) {
            return response()->json(['error' => 'forbidden', 'message' => 'Staff required'], 403);
        }
        return $next($request);
    }
}
