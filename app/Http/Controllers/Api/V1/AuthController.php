<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\IdentityService;
use App\Services\IpIntelligenceService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — mobile/API authentication.
 *
 * Reuses the Phase 14 account engine (IdentityService, PhoneOtpService,
 * AccountLifecycleService, FraudRiskService, LoginEventService) — there is
 * no second account system. Every login/registration issues a personal
 * access token shown exactly once.
 */
class AuthController extends Controller
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected PhoneOtpService $otp,
        protected IdentityService $identities,
        protected GoogleIdTokenVerifierInterface $googleVerifier,
        protected ApiTokenService $tokens,
    ) {}

    /**
     * POST /api/v1/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:60|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            'role' => ['required', Rule::in(['player', 'organizer'])],
            'password' => 'required|min:6|confirmed',
        ]);

        // role is validated above (player|organizer only) and set explicitly —
        // it is NOT mass-assignable, so a client cannot self-register as admin.
        $user = new User();
        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->game_uid = $data['game_uid'] ?? null;
        $user->role = $data['role'];
        $user->password = Hash::make($data['password']);
        $user->account_status = 'active';
        $user->privacy = 'public';
        $user->save();

        // Phase 10 — pseudonymous device + IP observations (never throws).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        // Phase 13 — audit the signup.
        $this->audit->recordQuietly($user, 'auth.register', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['role' => $user->role, 'via' => 'api'],
        ]);

        // Phase 11 — welcome + email verification link.
        $this->notifications->send(
            $user,
            Notification::TYPE_WELCOME,
            'Welcome to FF Arena',
            'Your account was created. Verify your email to secure it.',
            NotificationService::link('home'),
        );

        $this->sendVerificationNotification($user);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::created([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * POST /api/v1/auth/login  (email + password)
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], (string) $user->password)) {
            // Enumeration-safe: identical failure for unknown email and bad
            // password; recorded against a matching account if one exists.
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request);

            return ApiResponse::error('invalid_credentials', 'Invalid email or password.', [], 401);
        }

        if (! $user->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PASSWORD, LoginEvent::STATUS_SUCCESS, $request);

        if ($this->loginEvents->isNewDevice($request, $user)) {
            $this->notifications->send(
                $user,
                Notification::TYPE_SUSPICIOUS_LOGIN,
                'New device sign-in',
                'Your account was just signed in from a new device.',
                NotificationService::link('settings.sessions'),
            );
        }

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'password', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * POST /api/v1/auth/google  (id_token → account)
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => 'required|string',
        ]);

        if (! $this->googleVerifier->isConfigured()) {
            return ApiResponse::error('not_configured', 'Google Sign-In is not configured.', [], 503);
        }

        try {
            $googleUser = $this->googleVerifier->verify($data['id_token']);
        } catch (DomainException $e) {
            return ApiResponse::error('invalid_id_token', $e->getMessage(), [], 401);
        }

        $result = $this->identities->resolveGoogle($googleUser);
        $user = $result['user'];

        if (! $user->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_GOOGLE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'google', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
            'created' => (bool) $result['created'],
        ], [], $result['created'] ? 201 : 200);
    }

    /**
     * POST /api/v1/auth/otp/request
     */
    public function otpRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'purpose' => ['required', Rule::in(['login', 'signup'])],
        ]);

        $user = $request->user();

        try {
            $this->otp->issue($user, $data['phone'], $data['purpose']);
        } catch (DomainException $e) {
            return ApiResponse::error('otp_request_failed', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(['status' => 'sent'], ['message' => 'A verification code was sent to your phone.']);
    }

    /**
     * POST /api/v1/auth/otp/verify  (phone login)
     */
    public function otpVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'purpose' => ['required', Rule::in(['login', 'signup'])],
            'code' => 'required|string|max:10',
        ]);

        $user = $request->user();

        try {
            $challenge = $this->otp->verify($user, $data['phone'], $data['purpose'], $data['code']);
        } catch (DomainException $e) {
            return ApiResponse::error('invalid_code', $e->getMessage(), [], 422);
        }

        // `login` purpose resolves (or creates) the account via the verified
        // phone identity; `signup` links the verified phone to the current
        // user. Mirrors the Phase 14 phone-login flow.
        $identityUser = $this->identities->userFor('phone', $challenge->phone);

        if ($data['purpose'] === 'signup') {
            if ($user === null) {
                return ApiResponse::error('unauthenticated', 'Authentication is required to link a phone.', [], 401);
            }

            $this->identities->linkPhone($user, $challenge->phone);

            $token = $this->issueDefaultToken($user, 'mobile-app', $request);

            return ApiResponse::data([
                'user' => new MeResource($user),
                'token' => $token->plainTextToken,
                'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
            ]);
        }

        if ($identityUser === null) {
            return ApiResponse::error('no_account', 'No account is linked to this phone number.', [], 404);
        }

        if (! $identityUser->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->loginEvents->record($identityUser, LoginEvent::EVENT_LOGIN_PHONE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($identityUser, 'auth.login', 'user', $identityUser->id, [
            'target_user_id' => $identityUser->id,
            'metadata' => ['provider' => 'phone', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($identityUser, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($identityUser),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * Issue the default first-party token (all non-admin scopes).
     */
    protected function issueDefaultToken(User $user, string $name, Request $request)
    {
        return $this->tokens->issue($user, $name, (array) config('api.scopes', []), null, null, $request);
    }

    protected function sendVerificationNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $minutes = (int) config('account.verification.verify_link_minutes', 60);
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1((string) $user->email),
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_EMAIL_VERIFY,
            'Verify your email',
            'Please verify your email address to secure your account.',
            $link,
        );
    }
}
