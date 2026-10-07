<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * GAP-10 A4 — password re-confirmation (step-up) screen.
 *
 * The admin money controls (payout approve / process / override / complete /
 * fail / cancel and admin refunds) are behind
 * App\Http\Middleware\EnsureRecentPasswordConfirmation. This controller is the
 * screen that satisfies it: the administrator re-enters the current password
 * and the confirmation window is stamped in the session.
 *
 * Contract:
 *  - `show()`   renders the form (or immediately returns the user to their
 *               intended destination when the window is still open).
 *  - `confirm()` verifies the current password and stamps
 *    `auth.password_confirmed_at`; every attempt (success and failure) is
 *    audited, because a failed step-up is a security-relevant event.
 *
 * The password is never logged, never stored and never echoed back.
 */
class ConfirmPasswordController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10800);

        // The window is still open: there is nothing to confirm, so send the
        // administrator straight back to the action they were attempting.
        if ($confirmedAt > 0 && $timeout > 0 && (time() - $confirmedAt) <= $timeout) {
            return redirect()->intended(route('home'));
        }

        return view('auth.confirm-password');
    }

    public function confirm(Request $request, AuditLogService $audit): RedirectResponse|JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if (! Hash::check((string) $request->input('password'), (string) $user->getAuthPassword())) {
            $audit->recordQuietly($user, 'auth.password_confirmation_failed', 'user', $user->id, [
                'target_user' => $user,
                'metadata' => ['guard' => 'web'],
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'password_confirmation_failed',
                    'message' => 'The password you entered is incorrect.',
                ], 422);
            }

            throw ValidationException::withMessages([
                'password' => 'The password you entered is incorrect.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->forget('url.intended');

        $audit->recordQuietly($user, 'auth.password_confirmed', 'user', $user->id, [
            'target_user' => $user,
            'metadata' => ['guard' => 'web'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'confirmed']);
        }

        return redirect()
            ->intended(route('home'))
            ->with('success', 'Password confirmed.');
    }
}
