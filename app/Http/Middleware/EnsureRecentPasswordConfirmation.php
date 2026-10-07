<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GAP-10 A4 — step-up authentication for admin money actions.
 *
 * Payout approval/disbursement and admin refunds move real money. A stolen or
 * left-open admin session must not be enough to release a payout, so these
 * actions require the administrator to have re-entered their password inside
 * `config('auth.password_timeout')` (default three hours, the same window
 * Laravel's own `password.confirm` middleware uses).
 *
 * The timestamp is read from the `auth.password_confirmed_at` session key —
 * the standard key written by Laravel's password-confirmation flow — so the
 * behaviour composes with the framework instead of inventing a parallel one:
 *
 *  - HTML clients are redirected to the `password.confirm` screen and returned
 *    to the intended action afterwards.
 *  - JSON/API clients get `423 Locked` with a machine-readable error, because a
 *    redirect would be silently followed as a success by most clients.
 *
 * Fail closed: when no confirmation has ever happened, or the confirmation has
 * expired, the action is refused. Nothing else is trusted (no "remember this
 * device" bypass is granted here).
 */
class EnsureRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $timeout = (int) config('auth.password_timeout', 10800);
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if ($timeout > 0 && $confirmedAt > 0 && (time() - $confirmedAt) <= $timeout) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'password_confirmation_required',
                'message' => 'This action requires you to confirm your password again.',
            ], 423);
        }

        // Preserve the intended action so the administrator lands where they
        // meant to go. Only the request URL is stored — never the payload.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()
            ->route('password.confirm')
            ->with('status', 'Please confirm your password to continue.');
    }
}
