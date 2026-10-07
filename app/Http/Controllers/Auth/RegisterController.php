<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\IpIntelligenceService;
use App\Services\NotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 14 — web (session) registration.
 *
 * The previous implementation accepted any role (a client could post
 * `role=admin` and receive an admin account), never audited the signup and
 * never sent the welcome notification. Roles are now restricted to
 * `player|organizer` at validation time and the same account engine the API
 * uses (AuditLogService, NotificationService, DeviceFingerprintService,
 * IpIntelligenceService) runs on registration.
 */
class RegisterController extends Controller
{
    public function __construct(
        protected AuditLogService $audit,
        protected NotificationService $notifications,
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {}

    public function showRegistrationForm(): View
    {
        return view('auth.register');
    }

    /**
     * POST /register
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|min:3|max:30|unique:users,username|regex:/^[a-zA-Z0-9_\.]+$/',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            // Reserved staff roles are refused at validation time: the
            // elevated roles (`admin`, `moderator`) can never be self-assigned.
            'role' => ['nullable', Rule::in(['player', 'organizer'])],
            'password' => 'required|string|min:8|confirmed',
            'avatar' => 'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
            'terms' => 'sometimes|accepted',
        ]);

        // Built attribute-by-attribute: `role`/`account_status` are not
        // mass-assignable, so a client can never smuggle them in.
        $user = new User();
        $user->name = $validated['name'];
        $user->username = $validated['username'] ?? ('user_'.Str::lower(Str::random(8)));
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->game_uid = $validated['game_uid'] ?? null;
        $user->password = Hash::make($validated['password']);
        $user->role = $validated['role'] ?? 'player';
        $user->is_active = true;
        $user->account_status = 'active';
        $user->timezone = 'Asia/Dhaka';
        $user->locale = 'en';
        $user->save();

        if ($request->hasFile('avatar')) {
            try {
                $file = $request->file('avatar');
                $filename = 'avatars/'.$user->id.'/'.Str::uuid().'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('', $filename, 'local');
                $user->forceFill(['avatar_path' => $path])->save();
            } catch (\Throwable) {
                // An avatar upload failure never blocks the signup.
            }
        }

        try {
            Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'BDT', 'balance_minor' => 0]);
        } catch (\Throwable) {
            // The wallet is created lazily by WalletService when first needed.
        }

        // Phase 10 — pseudonymous device + IP observations (never throw).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        // Phase 13 — audit the signup (append-only, written by the service).
        $this->audit->recordQuietly($user, 'auth.register', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['role' => $user->role, 'via' => 'web'],
        ]);

        // Phase 11 — welcome notification + verification link.
        $this->notifications->send(
            $user,
            Notification::TYPE_WELCOME,
            'Welcome to FF Arena',
            'Your account was created. Verify your email to secure it.',
            NotificationService::link('home'),
        );

        $this->sendVerificationNotification($user);

        event(new Registered($user));
        Auth::login($user);

        return redirect()->intended(route('home'))->with('success', 'Account created successfully!');
    }

    /**
     * Send the signed verification link, when email verification is available.
     */
    protected function sendVerificationNotification(User $user): void
    {
        try {
            if (! $user->hasVerifiedEmail()) {
                $user->sendEmailVerificationNotification();

                // Keep the signing URL reachable for local (log-mailer)
                // environments without leaking it into the response.
                URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                    'id' => $user->getKey(),
                    'hash' => sha1((string) $user->getEmailForVerification()),
                ]);
            }
        } catch (\Throwable) {
            // Never block registration on a mail transport problem.
        }
    }
}
