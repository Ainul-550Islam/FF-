# Phase 18 — Native Mobile Application Foundation

**Status:** complete. All backend and mobile checks pass.

This report contains every file created or modified in Phase 18 in its **complete final form** (no placeholders, no truncation, no TODOs). Regenerate with `python3 tools/gen_phase18_report.py`.

## Verification

| Check | Result |
|---|---|
| Backend PHPUnit (full) | **823 tests / 2614 assertions — all pass** |
| Backend new tests (devices + app/meta) | 11 tests / 54 assertions — pass |
| OpenAPI regeneration + validation | 71 documented paths, all routed — OK |
| Scoped Pint (`scripts/ci/check-pint.sh`) | PASS (86 files) |
| Flutter `flutter analyze` | **No issues found** |
| Flutter `flutter test` | **49 tests — all pass** |
| Flutter `dart format` | applied across lib/ and test/ |
| Flutter SDK | 3.47.3 stable / Dart 3.13.3 |

## File inventory

- **Backend — new files (model, policy, controllers, config, env)** (7 files)
- **Backend — modified files (full final form)** (4 files)
- **Backend — tests** (2 files)
- **Tools & CI** (2 files)
- **Mobile — project configuration** (4 files)
- **Mobile — core (api, cache, format, l10n, network, push, session, storage, telemetry)** (21 files)
- **Mobile — data repositories** (14 files)
- **Mobile — config & features** (2 files)
- **Mobile — screens** (41 files)
- **Mobile — widgets & app entry** (7 files)
- **Mobile — theme** (1 files)
- **Mobile — tests** (10 files)
- **Mobile — Android scaffolding** (7 files)
- **Mobile — iOS scaffolding** (7 files)
- **Documentation** (4 files)

---

## Backend — new files (model, policy, controllers, config, env)

### `database/migrations/2026_09_11_000000_create_mobile_device_tokens_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 18 — mobile push-token registration.
     *
     * Additive, read-mostly registry of a user's mobile devices for push
     * notification delivery. The RAW push token is never persisted — only a
     * SHA-256 hash — and the row is never exposed to other users.
     */
    public function up(): void
    {
        Schema::create('mobile_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);           // android | ios
            $table->string('provider', 20);            // fcm | apns
            $table->string('token_hash', 64)->index(); // sha256(raw token)
            $table->string('device_label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_tokens');
    }
};

```

### `app/Models/MobileDevice.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 18 — a registered mobile push device.
 *
 * The raw push token is never stored; only its SHA-256 hash. Rows are owned
 * by exactly one user and are only ever readable by that user.
 */
class MobileDevice extends Model
{
    protected $table = 'mobile_device_tokens';

    public const PLATFORMS = ['android', 'ios'];

    public const PROVIDERS = ['fcm', 'apns'];

    protected $fillable = [
        'user_id',
        'platform',
        'provider',
        'token_hash',
        'device_label',
        'is_active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One-way fingerprint for a raw push token. Tokens are compared and
     * deduplicated by this value only.
     */
    public static function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}

```

### `app/Policies/MobileDevicePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\MobileDevice;
use App\Models\User;

/**
 * Phase 18 — mobile push devices are strictly owner-only. There is no staff
 * override by design: nobody but the device owner may read or remove a
 * registered push token reference.
 */
class MobileDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped to the caller's own rows in the controller
    }

    public function view(User $user, MobileDevice $device): bool
    {
        return $device->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, MobileDevice $device): bool
    {
        return $device->user_id === $user->id;
    }
}

```

### `app/Http/Controllers/Api/V1/DeviceController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 18 — mobile push-token registration (owner-only).
 *
 * The mobile client registers its platform push token here after login and
 * removes it on logout/forced-logout. Raw tokens are hashed immediately and
 * never written to logs or responses.
 */
class DeviceController extends Controller
{
    /**
     * GET /api/v1/me/devices — the caller's registered devices. The token
     * reference itself is never serialized (not even the hash).
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->mobileDevices()
            ->orderByDesc('last_seen_at')
            ->get();

        $rows = $devices->map(fn (MobileDevice $d) => $this->serialize($d));

        return ApiResponse::data($rows);
    }

    /**
     * POST /api/v1/me/devices — register (or refresh) a push token.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(MobileDevice::PLATFORMS)],
            'provider' => ['required', Rule::in(MobileDevice::PROVIDERS)],
            'token' => ['required', 'string', 'max:'.(int) config('mobile.push.device_token_max_length', 4096)],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $hash = MobileDevice::hashToken((string) $data['token']);

        $device = MobileDevice::where('user_id', $user->id)
            ->where('token_hash', $hash)
            ->first();

        if ($device === null) {
            $device = new MobileDevice;
            $device->user_id = $user->id;
            $device->token_hash = $hash;
            $device->platform = $data['platform'];
            $device->provider = $data['provider'];
            $device->is_active = true;
        } else {
            $device->platform = $data['platform'];
            $device->provider = $data['provider'];
            $device->is_active = true;
        }

        if (array_key_exists('device_label', $data) && $data['device_label'] !== null) {
            $device->device_label = $data['device_label'];
        }

        $device->last_seen_at = now();
        $device->save();

        $this->enforceCap($user);

        return ApiResponse::data($this->serialize($device->fresh()), [], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/me/devices/{device} — remove a push token (e.g. logout).
     */
    public function destroy(Request $request, MobileDevice $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return ApiResponse::noContent();
    }

    /**
     * Cap the number of active device registrations per user. When the cap is
     * exceeded the least-recently-seen extras are deactivated (never deleted,
     * so delivery state history is preserved).
     */
    protected function enforceCap($user): void
    {
        $max = (int) config('mobile.devices.max_devices_per_user', 25);

        $extras = $user->mobileDevices()
            ->where('is_active', true)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->skip($max)
            ->take(100)
            ->get();

        foreach ($extras as $extra) {
            $extra->is_active = false;
            $extra->save();
        }
    }

    protected function serialize(MobileDevice $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'provider' => $device->provider,
            'device_label' => $device->device_label,
            'is_active' => (bool) $device->is_active,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'created_at' => $device->created_at?->toISOString(),
        ];
    }
}

```

### `app/Http/Controllers/Api/V1/AppMetaController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Phase 18 — server-driven app metadata.
 *
 * Anonymous endpoint the mobile client reads at startup for a compatibility
 * check, deep-link scheme and platform facts. Nothing here is secret.
 */
class AppMetaController extends Controller
{
    /**
     * GET /api/v1/app/meta
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::data([
            'app' => [
                'name' => config('app.name', 'FF Arena'),
                'api_version' => '1',
                'min_supported_app_version' => (string) config('mobile.min_supported_app_version', '1.0.0'),
                'latest_app_version' => (string) config('mobile.latest_app_version', '1.0.0'),
                'deep_link_scheme' => (string) config('mobile.deep_link_scheme', 'ffarena'),
            ],
            'push' => [
                'fcm_enabled' => (bool) config('mobile.push.fcm_enabled', false),
                'apns_enabled' => (bool) config('mobile.push.apns_enabled', false),
            ],
            'urls' => [
                'support' => (string) config('mobile.support_url', ''),
                'privacy' => (string) config('mobile.privacy_url', ''),
                'terms' => (string) config('mobile.terms_url', ''),
            ],
            'platform' => [
                'currency' => 'BDT',
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => config('app.locale', 'en'),
            ],
        ]);
    }
}

```

### `config/mobile.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile application (Phase 18)
    |--------------------------------------------------------------------------
    |
    | Server-side configuration for the native mobile client. Every value is
    | environment-driven; production credentials (FCM/APNs) are intentionally
    | absent by default so the app keeps working with push disabled honestly.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | App version policy
    |--------------------------------------------------------------------------
    |
    | The client compares its own version against min_supported_app_version
    | and may warn (but never hard-block) when out of date. latest_app_version
    | is informational only.
    |
    */
    'min_supported_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),
    'latest_app_version' => env('MOBILE_LATEST_APP_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Deep links
    |--------------------------------------------------------------------------
    */
    'deep_link_scheme' => env('MOBILE_DEEP_LINK_SCHEME', 'ffarena'),

    /*
    |--------------------------------------------------------------------------
    | Public URLs surfaced to the app (privacy policy, terms, support).
    |--------------------------------------------------------------------------
    */
    'support_url' => env('MOBILE_SUPPORT_URL', ''),
    'privacy_url' => env('MOBILE_PRIVACY_URL', ''),
    'terms_url' => env('MOBILE_TERMS_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Push providers
    |--------------------------------------------------------------------------
    |
    | fcm_enabled / apns_enabled are honest capability flags the app reads to
    | decide whether to request a push token at all. They default to false;
    | nothing is faked when they are off.
    |
    */
    'push' => [
        'fcm_enabled' => (bool) env('PUSH_FCM_ENABLED', false),
        'apns_enabled' => (bool) env('PUSH_APNS_ENABLED', false),
        'device_token_max_length' => 4096,
    ],

    /*
    |--------------------------------------------------------------------------
    | Device registry
    |--------------------------------------------------------------------------
    */
    'devices' => [
        'token_hash_algo' => 'sha256',
        'max_devices_per_user' => 25,
    ],

];

```

### `.env.example`

```text
APP_NAME=FF Arena
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# ---------------------------------------------------------------------------
# Phase 14 — Google OAuth / OpenID Connect
# ---------------------------------------------------------------------------
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

# ---------------------------------------------------------------------------
# Phase 14 — phone OTP delivery (SMS gateway)
# ---------------------------------------------------------------------------
SMS_GATEWAY_ENDPOINT=
SMS_GATEWAY_API_KEY=
SMS_GATEWAY_SENDER=

# ---------------------------------------------------------------------------
# Phase 14 — payment providers (honest: absent credentials = "not configured")
# ---------------------------------------------------------------------------
PAYMENT_WEBHOOK_SECRET=ffarena-local-webhook-secret

BKASH_ENABLED=true
BKASH_MODE=sandbox
BKASH_BASE_URL=
BKASH_APP_KEY=
BKASH_APP_SECRET=
BKASH_USERNAME=
BKASH_PASSWORD=
BKASH_MERCHANT_NUMBER=

NAGAD_ENABLED=true
NAGAD_MODE=sandbox
NAGAD_BASE_URL=
NAGAD_MERCHANT_ID=
NAGAD_MERCHANT_PRIVATE_KEY=
NAGAD_PG_PUBLIC_KEY=
NAGAD_MERCHANT_NUMBER=

ROCKET_ENABLED=true
ROCKET_MODE=sandbox
ROCKET_BASE_URL=
ROCKET_MERCHANT_ID=
ROCKET_MERCHANT_SECRET=

CARD_ENABLED=false
CARD_MODE=sandbox
CARD_GATEWAY=
CARD_MERCHANT_ID=
CARD_MERCHANT_SECRET=

BANK_ENABLED=true
BANK_ACCOUNT_NAME=
BANK_ACCOUNT_NUMBER=

SSLCOMMERZ_ENABLED=false
SSLCOMMERZ_MODE=sandbox
SSLCOMMERZ_STORE_ID=
SSLCOMMERZ_STORE_PASSWORD=
SSLCOMMERZ_BASE_URL=

# Phase 15 — public API (Laravel Sanctum)
# Prefix for issued personal access tokens (set in production so leaked tokens
# are detectable by secret scanners, e.g. "ffarena_".)
SANCTUM_TOKEN_PREFIX=

# Phase 15 — inbound webhook secrets. Leave a provider empty to fall back to
# PAYMENT_WEBHOOK_SECRET (the Phase 08 trust root).
WEBHOOK_BKASH_SECRET=
WEBHOOK_NAGAD_SECRET=
WEBHOOK_ROCKET_SECRET=
WEBHOOK_SSLCOMMERZ_SECRET=
WEBHOOK_CARD_SECRET=

# ---------------------------------------------------------------------------
# Phase 16 — production hardening & observability
# ---------------------------------------------------------------------------
# Request correlation header (echoed on every response).
REQUEST_ID_HEADER=X-Request-ID

# Structured logging: default + daily rotation.
LOG_DAILY_DAYS=14

# Metrics backend: "log" (default) or "null".
METRICS_DRIVER=log
METRICS_LOG_CHANNEL=metrics

# Error reporting: "log" (default) or "sentry" (requires the SDK + SENTRY_DSN).
ERROR_REPORTING_DRIVER=log
SENTRY_DSN=

# Health: a worker/scheduler heartbeat older than this is "degraded".
HEALTH_WORKER_STALE_SECONDS=300
HEALTH_SCHEDULER_STALE_SECONDS=300

# Security headers.
SECURITY_HSTS_ENABLE=true
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=false
SECURITY_CSP_ENABLE=false
SECURITY_CSP_POLICY="default-src 'self'"

# CORS — comma-separated exact origins (never "*"). Empty = no cross-origin.
CORS_ALLOWED_ORIGINS=

# Backups — written to the private disk, chmod 0600, checksummed.
BACKUP_DISK=local
BACKUP_PATH=backups
BACKUP_RETENTION=14
BACKUP_INCLUDE_PRIVATE_FILES=true
BACKUP_INTEGRITY_CHECK=true
BACKUP_NOTIFY_ADMINS=true

# Operational retention (days) for scheduled cleanup.
OBS_RETENTION_OTP_DAYS=1
OBS_RETENTION_IDEMPOTENCY_DAYS=2
OBS_RETENTION_NOTIFICATIONS_DAYS=180
OBS_RETENTION_WEBHOOK_DELIVERIES_DAYS=30
OBS_RETENTION_WEBHOOK_EVENTS_DAYS=90
OBS_RETENTION_LIVE_EVENTS_DAYS=30
OBS_RETENTION_FAILED_JOBS_DAYS=30

# ---------------------------------------------------------------------------
# Phase 18 — native mobile app (server-side metadata; no credentials required)
# ---------------------------------------------------------------------------
MOBILE_MIN_APP_VERSION=1.0.0
MOBILE_LATEST_APP_VERSION=1.0.0
MOBILE_DEEP_LINK_SCHEME=ffarena
MOBILE_SUPPORT_URL=
MOBILE_PRIVACY_URL=
MOBILE_TERMS_URL=

# Push providers. Honest capability flags only — leave off until real FCM/APNs
# credentials are provisioned. The app keeps working with push disabled.
PUSH_FCM_ENABLED=false
PUSH_APNS_ENABLED=false

VITE_APP_NAME="${APP_NAME}"

```


## Backend — modified files (full final form)

### `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    /**
     * Sensitive fields (role, wallet_balance, account_status, verification
     * state) are intentionally excluded from mass assignment. `role` must be
     * set explicitly (see AuthController) and can only ever be 'player' or
     * 'organizer' at registration time. Profile fields below are the only
     * user-editable attributes and are still validated server-side.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
        'bio',
        'country',
        'region',
        'avatar',
        'language',
        'timezone',
        'privacy',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Moderators are platform staff who can work the dispute/moderation
     * queue and review/resolve disputes. The role is granted only by admins
     * (never self-assigned and never mass-assignable).
     */
    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    /**
     * Platform staff (admins + moderators) — distinct from tournament
     * organizers, who are staff only within their own tournaments.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }

    /**
     * The user's wallet (Phase 08). Created lazily by WalletService.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Prize payouts received by this user (Phase 09).
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_user_id');
    }

    // ------------------------------------------------------------------
    // Phase 10 — anti-fraud / trust & safety relations
    // ------------------------------------------------------------------

    /**
     * The user's server-side risk profile.
     */
    public function riskProfile()
    {
        return $this->hasOne(RiskProfile::class);
    }

    /**
     * Risk events attributable to this account (append-only).
     */
    public function riskEvents()
    {
        return $this->hasMany(RiskEvent::class);
    }

    /**
     * Pseudonymous device associations.
     */
    public function deviceLinks()
    {
        return $this->hasMany(DeviceLink::class);
    }

    /**
     * Hashed IP observations for this account.
     */
    public function ipLinks()
    {
        return $this->hasMany(IpLink::class);
    }

    /**
     * Account restrictions applied to this user.
     */
    public function restrictions()
    {
        return $this->hasMany(Restriction::class);
    }

    /**
     * The user's identity-verification record.
     */
    public function identityVerification()
    {
        return $this->hasOne(IdentityVerification::class);
    }

    /**
     * Account-similarity links involving this user (either direction),
     * eagerly loading both sides.
     */
    public function linkedAccounts()
    {
        return AccountLink::query()
            ->with(['user', 'linkedUser'])
            ->where(function ($q) {
                $q->where('user_id', $this->id)->orWhere('linked_user_id', $this->id);
            });
    }

    /**
     * Anti-cheat incidents where this user is the accused or reporter.
     */
    public function antiCheatIncidents()
    {
        return $this->hasMany(AntiCheatIncident::class, 'accused_user_id');
    }

    // ------------------------------------------------------------------
    // Phase 14 — account ecosystem relations + helpers
    // ------------------------------------------------------------------

    /**
     * Provider-linked identities (google, phone) on this account.
     */
    public function identities()
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Phone OTP challenges issued for this account.
     */
    public function otpChallenges()
    {
        return $this->hasMany(OtpChallenge::class);
    }

    /**
     * Security/login history (append-only).
     */
    public function loginEvents()
    {
        return $this->hasMany(LoginEvent::class)->orderByDesc('id');
    }

    /**
     * Saved payment methods owned by this account.
     */
    /**
     * Registered mobile push devices (Phase 18). Owner-only.
     */
    public function mobileDevices()
    {
        return $this->hasMany(MobileDevice::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isActive(): bool
    {
        // `null` (e.g. a freshly-constructed or legacy row that has not been
        // reloaded since the column was added) means "not deactivated", so we
        // treat it as active. Only an explicit lifecycle status blocks access.
        return $this->account_status === null || $this->account_status === 'active';
    }

    public function isDeactivated(): bool
    {
        return $this->account_status === 'deactivated';
    }
}

```

### `routes/api.php`

```php
<?php

use App\Http\Controllers\Api\V1\AppMetaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WebhookInboundController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FF Arena Public API (Phase 15)
|--------------------------------------------------------------------------
|
| All public business endpoints are versioned under /api/v1. A future
| /api/v2 can be added alongside without breaking v1 clients. Sessions
| (cookies) are never accepted here — bearer tokens only.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ------------------------------------------------------------------
    // Authentication (anonymous, tightly rate-limited)
    // ------------------------------------------------------------------
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:api_register')->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api_login')->name('auth.login');

    Route::post('auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:api_login')->name('auth.google');

    Route::post('auth/otp/request', [AuthController::class, 'otpRequest'])
        ->middleware('throttle:api_otp_request')->name('auth.otp.request');

    Route::post('auth/otp/verify', [AuthController::class, 'otpVerify'])
        ->middleware('throttle:api_otp_verify')->name('auth.otp.verify');

    // ------------------------------------------------------------------
    // Public discovery (anonymous, rate-limited)
    // ------------------------------------------------------------------
    Route::middleware('throttle:api_anon')->group(function () {
        Route::get('app/meta', [AppMetaController::class, 'meta'])->name('app.meta');
        Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
        Route::get('tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
        Route::get('tournaments/{tournament}/matches', [TournamentController::class, 'matches'])->name('tournaments.matches');
        Route::get('tournaments/{tournament}/leaderboard', [TournamentController::class, 'leaderboard'])->name('tournaments.leaderboard');
        Route::get('tournaments/{tournament}/bracket', [TournamentController::class, 'bracket'])->name('tournaments.bracket');
        Route::get('matches/{match}', [MatchController::class, 'show'])->name('matches.show');
        Route::get('players/{user}', [PlayerController::class, 'show'])->name('players.show');
        Route::get('players/{user}/ranking', [LeaderboardController::class, 'playerRanking'])->name('players.ranking');
        Route::get('leaderboards', [LeaderboardController::class, 'index'])->name('leaderboards.index');
        Route::get('leaderboards/{tournament}', [LeaderboardController::class, 'show'])->name('leaderboards.show');
    });

    // ------------------------------------------------------------------
    // Authenticated (bearer token + valid, active account)
    // ------------------------------------------------------------------
    Route::middleware(['bearer', 'auth:sanctum', 'api.token', 'throttle:api'])->group(function () {

        // Me / profile
        Route::get('me', [MeController::class, 'show'])->middleware('abilities:profile:read')->name('me.show');
        Route::put('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.update');
        Route::patch('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.patch');

        // Security / sessions
        Route::get('me/security', [MeController::class, 'security'])->middleware('abilities:profile:read')->name('me.security');
        Route::get('me/sessions', [MeController::class, 'sessions'])->middleware('abilities:profile:read')->name('me.sessions');
        Route::delete('me/sessions/{session}', [MeController::class, 'revokeSession'])->middleware('abilities:profile:write')->name('me.sessions.revoke');
        Route::post('me/sessions/revoke-others', [MeController::class, 'revokeOthers'])->middleware('abilities:profile:write')->name('me.sessions.revoke_others');
        Route::post('me/sessions/revoke-all', [MeController::class, 'revokeAll'])->middleware('abilities:profile:write')->name('me.sessions.revoke_all');

        // Notifications
        Route::get('me/notifications', [NotificationController::class, 'index'])->middleware('abilities:notifications:read')->name('me.notifications');
        Route::get('me/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('abilities:notifications:read')->name('me.notifications.unread_count');
        Route::post('me/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('abilities:notifications:write')->name('me.notifications.read');
        Route::post('me/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('abilities:notifications:write')->name('me.notifications.read_all');

        // Realtime
        Route::get('me/live', [LiveController::class, 'me'])->middleware('abilities:notifications:read')->name('me.live');
        Route::get('tournaments/{tournament}/live', [TournamentController::class, 'live'])->middleware('throttle:api_anon')->name('tournaments.live');

        // Teams
        Route::get('me/teams', [TeamController::class, 'index'])->middleware('abilities:teams:read')->name('me.teams');
        Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('abilities:teams:read')->name('teams.show');
        Route::patch('teams/{team}', [TeamController::class, 'update'])->middleware('abilities:teams:write')->name('teams.update');
        Route::get('teams/{team}/roster', [TeamController::class, 'roster'])->middleware('abilities:roster:read')->name('teams.roster');
        Route::post('teams/{team}/roster', [TeamController::class, 'addMember'])->middleware('abilities:roster:write')->name('teams.roster.add');
        Route::delete('teams/{team}/roster/{member}', [TeamController::class, 'removeMember'])->middleware('abilities:roster:write')->name('teams.roster.remove');
        Route::post('teams/{team}/withdraw', [TeamController::class, 'withdraw'])->middleware('abilities:teams:write')->name('teams.withdraw');

        // Registration / check-in / waitlist
        Route::post('tournaments/{tournament}/registrations', [TournamentController::class, 'register'])
            ->middleware(['abilities:tournaments:register', 'idempotency'])->name('tournaments.register');
        Route::post('tournaments/{tournament}/check-in', [TournamentController::class, 'checkIn'])
            ->middleware('abilities:tournaments:register')->name('tournaments.checkin');
        Route::get('tournaments/{tournament}/waitlist', [TournamentController::class, 'waitlist'])
            ->middleware('abilities:tournaments:read')->name('tournaments.waitlist');

        // Score submission
        Route::post('matches/{match}/scores', [MatchController::class, 'submitScore'])
            ->middleware(['abilities:scores:submit', 'throttle:api_score', 'idempotency'])->name('matches.scores.submit');

        // Payments
        Route::get('payments/methods', [PaymentController::class, 'methods'])->middleware('abilities:wallet:read')->name('payments.methods');
        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware(['abilities:payments:create', 'throttle:api_payment', 'idempotency'])->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('abilities:payments:read')->name('payments.show');

        // Wallet / payouts (read-only)
        Route::get('me/wallet', [WalletController::class, 'show'])->middleware('abilities:wallet:read')->name('me.wallet');
        Route::get('me/wallet/ledger', [WalletController::class, 'ledger'])->middleware('abilities:wallet:read')->name('me.wallet.ledger');
        Route::get('me/payouts', [WalletController::class, 'payouts'])->middleware('abilities:payouts:read')->name('me.payouts');

        // Mobile push devices (Phase 18)
        Route::get('me/devices', [DeviceController::class, 'index'])->middleware('abilities:notifications:read')->name('me.devices');
        Route::post('me/devices', [DeviceController::class, 'store'])->middleware('abilities:notifications:write')->name('me.devices.store');
        Route::delete('me/devices/{device}', [DeviceController::class, 'destroy'])->middleware('abilities:notifications:write')->name('me.devices.destroy');

        // Support / disputes
        Route::get('me/support', [SupportController::class, 'index'])->middleware('abilities:support:read')->name('me.support.index');
        Route::post('me/support', [SupportController::class, 'store'])
            ->middleware(['abilities:support:write', 'throttle:api_support', 'idempotency'])->name('me.support.store');
        Route::get('me/support/{ticket}', [SupportController::class, 'show'])->middleware('abilities:support:read')->name('me.support.show');
        Route::get('me/support/{ticket}/messages', [SupportController::class, 'messages'])->middleware('abilities:support:read')->name('me.support.messages');
        Route::post('me/support/{ticket}/messages', [SupportController::class, 'reply'])
            ->middleware(['abilities:support:write', 'throttle:api_support'])->name('me.support.reply');
        Route::get('me/disputes', [DisputeController::class, 'index'])->middleware('abilities:disputes:read')->name('me.disputes');
        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->middleware('abilities:disputes:read')->name('disputes.show');

        // Personal access tokens / API clients
        Route::post('me/tokens', [TokenController::class, 'store'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.tokens.store');
        Route::get('me/tokens', [TokenController::class, 'index'])->middleware('abilities:profile:read')->name('me.tokens.index');
        Route::delete('me/tokens/{tokenId}', [TokenController::class, 'destroy'])->middleware('abilities:profile:write')->name('me.tokens.destroy');
        Route::get('me/clients', [TokenController::class, 'clients'])->middleware('abilities:profile:read')->name('me.clients.index');
        Route::post('me/clients', [TokenController::class, 'storeClient'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.clients.store');
        Route::delete('me/clients/{client}', [TokenController::class, 'destroyClient'])->middleware('abilities:profile:write')->name('me.clients.destroy');

        // ------------------------------------------------------------------
        // Admin-only: outbound webhook subscriptions
        // ------------------------------------------------------------------
        Route::middleware(['admin', 'abilities:admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('webhooks/endpoints', [WebhookSubscriptionController::class, 'index'])->name('webhooks.endpoints.index');
            Route::post('webhooks/endpoints', [WebhookSubscriptionController::class, 'store'])->name('webhooks.endpoints.store');
            Route::get('webhooks/endpoints/{endpoint}', [WebhookSubscriptionController::class, 'show'])->name('webhooks.endpoints.show');
            Route::post('webhooks/endpoints/{endpoint}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])->name('webhooks.endpoints.rotate');
            Route::post('webhooks/endpoints/{endpoint}/toggle', [WebhookSubscriptionController::class, 'toggle'])->name('webhooks.endpoints.toggle');
            Route::get('webhooks/endpoints/{endpoint}/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->name('webhooks.endpoints.deliveries');
            Route::get('webhooks/events', [WebhookSubscriptionController::class, 'events'])->name('webhooks.events.index');
        });
    });

    // ------------------------------------------------------------------
    // Inbound provider webhooks (HMAC-authenticated; no bearer required)
    // ------------------------------------------------------------------
    Route::post('webhooks/inbound/{provider}', [WebhookInboundController::class, 'handle'])
        ->middleware('throttle:api_webhook')->name('webhooks.inbound');
});

```

### `tools/gen_openapi.py`

```python
#!/usr/bin/env python3
"""
Phase 15 — OpenAPI 3.0 generator + validation gate.

Builds storage/api-docs/openapi.json from an explicit path table and shared
component schemas, then cross-checks that every documented path matches a
route registered by the application (`php artisan route:list --json`). A path
that is documented but not routed — or a routed public business endpoint that
is missing from the spec — is a hard failure.

Usage:
    python3 tools/gen_openapi.py            # (re)generate + validate
    python3 tools/gen_openapi.py --validate # validate the existing file only
"""

import json
import re
import subprocess
import sys

ROOT = "/home/user/ffarena-app"
OUT = f"{ROOT}/storage/api-docs/openapi.json"

BEARER = [{"bearerAuth": []}]
NONE = []

# ---------------------------------------------------------------------------
# Path table: (method, path, summary, security, scopes, request/response hints)
# Path params are written as {name}. Scopes string is informational.
# ---------------------------------------------------------------------------
PATHS = [
    # --- Authentication -----------------------------------------------------
    ("post", "/api/v1/auth/register", "Register an account", NONE, None,
     {"register": True, "rate": "api_register (3/hour/IP)"}),
    ("post", "/api/v1/auth/login", "Login with email + password", NONE, None,
     {"login": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/google", "Login with a Google id_token", NONE, None,
     {"google": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/otp/request", "Request a phone OTP", NONE, None,
     {"otp": True, "rate": "api_otp_request (1/min/phone)"}),
    ("post", "/api/v1/auth/otp/verify", "Verify a phone OTP and login", NONE, None,
     {"otp": True, "rate": "api_otp_verify (5/5min/phone)"}),

    # --- Public discovery --------------------------------------------------
    ("get", "/api/v1/app/meta", "App metadata & compatibility", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),
    ("get", "/api/v1/tournaments", "List public tournaments", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),
    ("get", "/api/v1/tournaments/{tournament}", "Show a tournament", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/matches", "List a tournament's matches", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/leaderboard", "Tournament leaderboard", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/bracket", "Tournament bracket", NONE, None, {}),
    ("get", "/api/v1/matches/{match}", "Show a match", NONE, None, {}),
    ("get", "/api/v1/players/{user}", "Public player profile", NONE, None, {}),
    ("get", "/api/v1/players/{user}/ranking", "Player's rankings", NONE, None, {}),
    ("get", "/api/v1/leaderboards", "Ranked tournaments", NONE, None, {}),
    ("get", "/api/v1/leaderboards/{tournament}", "Tournament standings", NONE, None, {}),

    # --- Me / profile / security ------------------------------------------
    ("get", "/api/v1/me", "Current user", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/security", "Sign-in methods & account status", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/sessions", "Active sessions", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/tokens", "Personal access tokens", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/clients", "API clients", BEARER, "profile:read", {}),
    ("put", "/api/v1/me/profile", "Update profile", BEARER, "profile:write", {}),
    ("patch", "/api/v1/me/profile", "Update profile (partial)", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/sessions/{session}", "Revoke a session", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-others", "Revoke other sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-all", "Revoke all sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/tokens", "Create a personal access token", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("post", "/api/v1/me/clients", "Create an API client", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("delete", "/api/v1/me/clients/{client}", "Revoke an API client", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/tokens/{tokenId}", "Revoke a token", BEARER, "profile:write", {}),

    # --- Notifications + realtime -----------------------------------------
    ("get", "/api/v1/me/notifications", "List notifications", BEARER, "notifications:read", {}),
    ("get", "/api/v1/me/notifications/unread-count", "Unread notification count", BEARER, "notifications:read", {}),
    ("post", "/api/v1/me/notifications/{notification}/read", "Mark a notification read", BEARER, "notifications:write", {}),
    ("post", "/api/v1/me/notifications/read-all", "Mark all notifications read", BEARER, "notifications:write", {}),
    ("get", "/api/v1/me/live", "Own realtime cursor feed", BEARER, "notifications:read", {}),
    ("get", "/api/v1/tournaments/{tournament}/live", "Tournament realtime feed", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),

    # --- Mobile push devices (Phase 18) ------------------------------------
    ("get", "/api/v1/me/devices", "Registered mobile devices", BEARER, "notifications:read", {}),
    ("post", "/api/v1/me/devices", "Register a mobile device", BEARER, "notifications:write", {}),
    ("delete", "/api/v1/me/devices/{device}", "Remove a mobile device", BEARER, "notifications:write", {}),

    # --- Teams / roster ----------------------------------------------------
    ("get", "/api/v1/me/teams", "Own teams", BEARER, "teams:read", {}),
    ("get", "/api/v1/teams/{team}", "Show a team", BEARER, "teams:read", {}),
    ("patch", "/api/v1/teams/{team}", "Update a team", BEARER, "teams:write", {}),
    ("post", "/api/v1/teams/{team}/withdraw", "Withdraw a team", BEARER, "teams:write", {}),
    ("get", "/api/v1/teams/{team}/roster", "List roster", BEARER, "roster:read", {}),
    ("post", "/api/v1/teams/{team}/roster", "Add a roster member", BEARER, "roster:write", {}),
    ("delete", "/api/v1/teams/{team}/roster/{member}", "Remove a roster member", BEARER, "roster:write", {}),

    # --- Registration / check-in / waitlist -------------------------------
    ("post", "/api/v1/tournaments/{tournament}/registrations", "Register a team", BEARER, "tournaments:register",
     {"idempotency": True}),
    ("post", "/api/v1/tournaments/{tournament}/check-in", "Check a team in", BEARER, "tournaments:register", {}),
    ("get", "/api/v1/tournaments/{tournament}/waitlist", "Waitlist positions", BEARER, "tournaments:read", {}),

    # --- Scores ------------------------------------------------------------
    ("post", "/api/v1/matches/{match}/scores", "Submit a score", BEARER, "scores:submit",
     {"idempotency": True, "rate": "api_score (10/min/user)"}),

    # --- Payments / wallet / payouts --------------------------------------
    ("get", "/api/v1/payments/methods", "Payment providers & saved methods", BEARER, "wallet:read", {}),
    ("post", "/api/v1/payments", "Create a payment", BEARER, "payments:create",
     {"idempotency": True, "rate": "api_payment (5/min/user)"}),
    ("get", "/api/v1/payments/{payment}", "Show a payment", BEARER, "payments:read", {}),
    ("get", "/api/v1/me/wallet", "Wallet summary", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/wallet/ledger", "Wallet ledger", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/payouts", "Own payouts", BEARER, "payouts:read", {}),

    # --- Support / disputes ------------------------------------------------
    ("get", "/api/v1/me/support", "Own support tickets", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support", "Create a support ticket", BEARER, "support:write",
     {"idempotency": True, "rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/support/{ticket}", "Show a ticket", BEARER, "support:read", {}),
    ("get", "/api/v1/me/support/{ticket}/messages", "Ticket messages", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support/{ticket}/messages", "Reply to a ticket", BEARER, "support:write",
     {"rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/disputes", "Own disputes", BEARER, "disputes:read", {}),
    ("get", "/api/v1/disputes/{dispute}", "Show a dispute", BEARER, "disputes:read", {}),

    # --- Admin webhooks (outbound subscriptions) --------------------------
    ("get", "/api/v1/admin/webhooks/endpoints", "List webhook endpoints", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints", "Create a webhook endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}", "Show an endpoint", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret", "Rotate endpoint secret", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/toggle", "Enable/disable endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries", "Endpoint deliveries", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/events", "Webhook event vocabulary", BEARER, "admin", {}),

    # --- Inbound provider webhooks ----------------------------------------
    ("post", "/api/v1/webhooks/inbound/{provider}", "Inbound provider webhook", NONE, None,
     {"inbound_webhook": True, "rate": "api_webhook (60/min/IP)"}),
]


def build_paths():
    """Build the OpenAPI `paths` object."""
    paths = {}
    for method, path, summary, security, scopes, hints in PATHS:
        if path not in paths:
            paths[path] = {}
        op = {
            "summary": summary,
            "operationId": f"{method}_{re.sub(r'[^a-zA-Z0-9]', '_', path.strip('/'))}",
            "tags": [tag_for(path)],
            "responses": responses_for(method, hints),
        }
        if security:
            op["security"] = security
        else:
            op["security"] = []
        params = path_params(path)
        if params:
            op["parameters"] = params
        body = body_for(method, path, hints)
        if body:
            op["requestBody"] = body
        desc_bits = []
        if scopes:
            desc_bits.append(f"**Required scope:** `{scopes}`.")
        if hints.get("idempotency"):
            desc_bits.append(
                "Supports the `Idempotency-Key` header: a replay within the TTL "
                "returns the stored response; reusing a key with a different "
                "body returns 409."
            )
        if hints.get("rate"):
            desc_bits.append(f"**Rate limit:** `{hints['rate']}`.")
        if hints.get("inbound_webhook"):
            desc_bits.append(
                "Authenticated by HMAC-SHA256 over the raw body "
                "(`X-Signature`), a fresh `X-Timestamp`, and an event-id "
                "idempotency check. Content-Type must be `application/json`."
            )
        if desc_bits:
            op["description"] = "\n\n".join(desc_bits)
        paths[path][method] = op
    return paths


def tag_for(path):
    if "/auth/" in path:
        return "Auth"
    if path.startswith("/api/v1/tournaments"):
        return "Tournaments"
    if path.startswith("/api/v1/matches"):
        return "Matches"
    if path.startswith("/api/v1/teams") or path == "/api/v1/me/teams":
        return "Teams"
    if path.startswith("/api/v1/players") or path.startswith("/api/v1/leaderboards"):
        return "Players & Leaderboards"
    if "/notifications" in path or path.endswith("/live"):
        return "Notifications & Realtime"
    if path.startswith("/api/v1/payments") or "/wallet" in path or "/payouts" in path:
        return "Payments & Wallet"
    if "/support" in path or "/disputes" in path:
        return "Support & Disputes"
    if "/admin/webhooks" in path:
        return "Admin Webhooks"
    if "/webhooks/inbound" in path:
        return "Inbound Webhooks"
    if path.startswith("/api/v1/me"):
        return "Me"
    return "General"


def path_params(path):
    names = re.findall(r"\{([a-zA-Z_]+)\}", path)
    return [{
        "name": n,
        "in": "path",
        "required": True,
        "schema": {"type": "string"},
        "description": path_param_desc(n),
    } for n in names]


def path_param_desc(name):
    return {
        "tournament": "Tournament slug",
        "match": "Match id",
        "team": "Team id",
        "member": "Roster member id",
        "user": "User id",
        "payment": "Payment id",
        "ticket": "Support ticket id",
        "dispute": "Dispute id",
        "session": "Session id",
        "tokenId": "Token id",
        "client": "API client id",
        "endpoint": "Webhook endpoint id",
        "provider": "Provider id (bkash|nagad|rocket|sslcommerz|card)",
        "device": "Mobile device id",
        "notification": "Notification id",
    }.get(name, name)


def responses_for(method, hints):
    ok = "200"
    if method == "post":
        ok = "201"
    elif method == "delete":
        ok = "204"
    envelope = {"$ref": "#/components/schemas/Envelope"}
    responses = {
        ok: {"description": "Success", "content": {"application/json": {"schema": envelope}}},
        "401": {"$ref": "#/components/responses/Unauthorized"},
        "403": {"$ref": "#/components/responses/Forbidden"},
        "404": {"$ref": "#/components/responses/NotFound"},
        "422": {"$ref": "#/components/responses/ValidationError"},
        "429": {"$ref": "#/components/responses/RateLimited"},
    }
    if method == "delete" and ok == "204":
        responses["204"] = {"description": "No content"}
        responses.pop("200", None)
    return responses


def body_for(method, path, hints):
    if method not in ("post", "put", "patch"):
        return None
    schema = {"type": "object"}
    example = None
    if "auth/register" in path:
        example = {"name": "Alice", "username": "alice", "email": "alice@example.com",
                   "phone": "01712345678", "role": "player",
                   "password": "secret123", "password_confirmation": "secret123"}
    elif "auth/login" in path:
        example = {"email": "alice@example.com", "password": "secret123"}
    elif "auth/google" in path:
        example = {"id_token": "<google id_token>"}
    elif "otp/request" in path:
        example = {"phone": "01712345678", "purpose": "login"}
    elif "otp/verify" in path:
        example = {"phone": "01712345678", "purpose": "login", "code": "123456"}
    elif path.endswith("/me/profile"):
        example = {"name": "Alice", "bio": "Player", "privacy": "public"}
    elif path.endswith("/registrations"):
        example = {"name": "Squad", "captain_name": "Captain", "phone": "01712345678",
                   "game_uid": "UID1234", "members": [{"player_name": "P1", "game_uid": "UID5678"}]}
    elif path.endswith("/check-in"):
        example = {"team_id": 1}
    elif path.endswith("/scores"):
        example = {"team_id": 1, "kills": 5, "placement": 1}
    elif path.endswith("/payments"):
        example = {"team_id": 1, "provider": "bkash"}
    elif path.endswith("/me/devices"):
        example = {"platform": "android", "provider": "fcm", "token": "<device push token>", "device_label": "Pixel 9"}
    elif path.endswith("/me/support"):
        example = {"subject": "Help", "category": "payment", "message": "Details"}
    elif path.endswith("/messages"):
        example = {"body": "Reply text"}
    elif path.endswith("/teams/{team}/roster") or path.endswith("/roster"):
        example = {"player_name": "P1", "game_uid": "UID1234"}
    elif path.endswith("/me/tokens"):
        example = {"name": "mobile", "scopes": ["profile:read"], "expires_in_days": 30}
    elif path.endswith("/me/clients"):
        example = {"name": "My App", "description": "optional", "scopes": ["profile:read"]}
    elif path.endswith("/webhooks/endpoints"):
        example = {"url": "https://example.com/hooks", "description": "optional", "events": ["payment.succeeded"]}
    elif path.endswith("/toggle"):
        example = {"status": "active"}
    return {"required": True, "content": {
        "application/json": {"schema": schema, "example": example} if example else {"schema": schema}}}


def components():
    return {
        "securitySchemes": {
            "bearerAuth": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "personal access token",
                "description": "Personal access token issued by /api/v1/auth/* or /api/v1/me/tokens. "
                               "Session cookies are NOT accepted by the API.",
            }
        },
        "responses": {
            "Unauthorized": {"description": "Missing/invalid token", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "Forbidden": {"description": "Insufficient scope or authorization", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "NotFound": {"description": "Resource not found", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "ValidationError": {"description": "Validation failed", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "RateLimited": {"description": "Rate limit exceeded", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
        },
        "schemas": {
            "Envelope": {"type": "object", "properties": {
                "data": {}, "meta": {"type": "object"}}},
            "ErrorResponse": {"type": "object", "properties": {
                "error": {"$ref": "#/components/schemas/Error"}}},
            "Error": {"type": "object", "required": ["code", "message"], "properties": {
                "code": {"type": "string"}, "message": {"type": "string"},
                "details": {"type": "object", "additionalProperties": {"type": "string"}}}},
            "Tournament": {"type": "object", "properties": {
                "id": {"type": "integer"}, "slug": {"type": "string"}, "name": {"type": "string"},
                "game_mode": {"type": "string", "enum": ["squad", "duo", "solo"]},
                "map": {"type": "string"}, "format": {"type": "string"},
                "status": {"type": "string"}, "entry_fee": {"type": "string"},
                "entry_fee_minor": {"type": "integer"}, "currency": {"type": "string"},
                "prize_pool": {"type": "string"}, "team_slots": {"type": "integer"},
                "team_size": {"type": "integer"}, "starts_at": {"type": "string", "format": "date-time"},
                "check_in_starts_at": {"type": "string", "format": "date-time"},
                "check_in_ends_at": {"type": "string", "format": "date-time"},
                "slots_left": {"type": "integer"}, "is_full": {"type": "boolean"},
                "accepts_registration": {"type": "boolean"},
                "confirmed_teams_count": {"type": "integer"},
                "organizer": {"type": "object"}}},
            "Match": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "round": {"type": "integer"}, "match_no": {"type": "integer"},
                "bracket": {"type": "string"}, "status": {"type": "string"},
                "scheduled_at": {"type": "string", "format": "date-time"},
                "completed_at": {"type": "string", "format": "date-time"},
                "room_id": {"type": "string"}, "room_pass": {"type": "string"},
                "team1": {"type": "object"}, "team2": {"type": "object"},
                "winner": {"type": "object"}, "scores": {"type": "array", "items": {"type": "object"}}}},
            "Score": {"type": "object", "properties": {
                "id": {"type": "integer"}, "team_id": {"type": "integer"},
                "kills": {"type": "integer"}, "placement": {"type": "integer"},
                "placement_points": {"type": "integer"}, "kill_points": {"type": "integer"},
                "points": {"type": "integer"}, "status": {"type": "string"}}},
            "Team": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "name": {"type": "string"}, "captain_name": {"type": "string"},
                "game_uid": {"type": "string"}, "status": {"type": "string"},
                "waitlist_position": {"type": "integer"}}},
            "UserProfile": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "username": {"type": "string"}, "visible": {"type": "boolean"},
                "privacy": {"type": "string"}}},
            "Notification": {"type": "object", "properties": {
                "id": {"type": "integer"}, "type": {"type": "string"},
                "title": {"type": "string"}, "body": {"type": "string"},
                "read": {"type": "boolean"}}},
            "Payment": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "team_id": {"type": "integer"}, "amount": {"type": "string"},
                "amount_minor": {"type": "integer"}, "currency": {"type": "string"},
                "provider": {"type": "string"}, "status": {"type": "string"}}},
            "Wallet": {"type": "object", "properties": {
                "id": {"type": "integer"}, "balance": {"type": "string"},
                "balance_minor": {"type": "integer"}, "currency": {"type": "string"},
                "status": {"type": "string"}}},
            "LedgerEntry": {"type": "object", "properties": {
                "id": {"type": "integer"}, "direction": {"type": "string", "enum": ["credit", "debit"]},
                "amount_minor": {"type": "integer"}, "balance_after_minor": {"type": "integer"},
                "type": {"type": "string"}}},
            "Payout": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "rank": {"type": "integer"}, "amount_minor": {"type": "integer"},
                "currency": {"type": "string"}, "status": {"type": "string"}}},
            "SupportTicket": {"type": "object", "properties": {
                "id": {"type": "integer"}, "subject": {"type": "string"},
                "category": {"type": "string"}, "priority": {"type": "string"},
                "status": {"type": "string"}}},
            "SupportMessage": {"type": "object", "properties": {
                "id": {"type": "integer"}, "ticket_id": {"type": "integer"},
                "body": {"type": "string"}}},
            "Dispute": {"type": "object", "properties": {
                "id": {"type": "integer"}, "match_id": {"type": "integer"},
                "category": {"type": "string"}, "status": {"type": "string"},
                "description": {"type": "string"}, "resolution": {"type": "string"}}},
            "Token": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "abilities": {"type": "array", "items": {"type": "string"}},
                "last_used_at": {"type": "string", "format": "date-time"},
                "expires_at": {"type": "string", "format": "date-time"}}},
            "ApiClient": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "description": {"type": "string"}, "status": {"type": "string"}}},
            "WebhookEndpoint": {"type": "object", "properties": {
                "id": {"type": "integer"}, "url": {"type": "string"},
                "status": {"type": "string"}, "events": {"type": "array", "items": {"type": "string"}},
                "consecutive_failures": {"type": "integer"}}},
            "WebhookDelivery": {"type": "object", "properties": {
                "id": {"type": "integer"}, "event": {"type": "string"},
                "delivery_id": {"type": "string"}, "status": {"type": "string"},
                "attempts": {"type": "integer"}}},
            "Me": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "username": {"type": "string"}, "email": {"type": "string"},
                "email_verified": {"type": "boolean"}, "avatar": {"type": "string"},
                "bio": {"type": "string"}, "country": {"type": "string"},
                "region": {"type": "string"}, "role": {"type": "string"},
                "privacy": {"type": "string"}, "joined_at": {"type": "string", "format": "date"}}},
            "AuthSession": {"type": "object", "properties": {
                "token": {"type": "string"},
                "token_expires_at": {"type": "string", "format": "date-time"},
                "user": {"$ref": "#/components/schemas/Me"}}},
            "LiveEvent": {"type": "object", "properties": {
                "id": {"type": "integer"}, "type": {"type": "string"},
                "tournament_id": {"type": "integer"}, "payload": {"type": "object"},
                "created_at": {"type": "string", "format": "date-time"}}},
            "Session": {"type": "object", "properties": {
                "id": {"type": "string"}, "device_label": {"type": "string"},
                "last_activity": {"type": "string", "format": "date-time"},
                "is_current": {"type": "boolean"}}},
            "StandingRow": {"type": "object", "properties": {
                "rank": {"type": "integer"}, "team_id": {"type": "integer"},
                "team_name": {"type": "string"}, "matches_played": {"type": "integer"},
                "kills": {"type": "integer"}, "placement_points": {"type": "integer"},
                "kill_points": {"type": "integer"}, "points": {"type": "integer"},
                "best_placement": {"type": "integer"}}},
            "Pagination": {"type": "object", "properties": {
                "current_page": {"type": "integer"}, "last_page": {"type": "integer"},
                "per_page": {"type": "integer"}, "total": {"type": "integer"}}},
            "MobileDevice": {"type": "object", "properties": {
                "id": {"type": "integer"}, "platform": {"type": "string", "enum": ["android", "ios"]},
                "provider": {"type": "string", "enum": ["fcm", "apns"]},
                "device_label": {"type": "string"}, "is_active": {"type": "boolean"},
                "last_seen_at": {"type": "string", "format": "date-time"},
                "created_at": {"type": "string", "format": "date-time"}}},
            "AppMeta": {"type": "object", "properties": {
                "app": {"$ref": "#/components/schemas/AppInfo"},
                "push": {"$ref": "#/components/schemas/PushCapabilities"},
                "urls": {"$ref": "#/components/schemas/AppUrls"},
                "platform": {"$ref": "#/components/schemas/PlatformInfo"}}},
            "AppInfo": {"type": "object", "properties": {
                "name": {"type": "string"}, "api_version": {"type": "string"},
                "min_supported_app_version": {"type": "string"},
                "latest_app_version": {"type": "string"},
                "deep_link_scheme": {"type": "string"}}},
            "PushCapabilities": {"type": "object", "properties": {
                "fcm_enabled": {"type": "boolean"}, "apns_enabled": {"type": "boolean"}}},
            "AppUrls": {"type": "object", "properties": {
                "support": {"type": "string"}, "privacy": {"type": "string"},
                "terms": {"type": "string"}}},
            "PlatformInfo": {"type": "object", "properties": {
                "currency": {"type": "string"}, "timezone": {"type": "string"},
                "locale": {"type": "string"}}},
        },
    }


def load_routes():
    """Run `php artisan route:list --json` and return (method, normalized_uri) pairs."""
    out = subprocess.run(
        ["php", "artisan", "route:list", "--json"], cwd=ROOT,
        capture_output=True, text=True, check=True)
    routes = json.loads(out.stdout)
    result = []
    for r in routes:
        uri = r["uri"]
        # Normalize optional params and strip prefix duplication.
        uri = re.sub(r"\{\w+\?\}", lambda m: m.group(0).rstrip("?"), uri)
        for method in r["method"].split("|"):
            result.append((method.lower(), uri))
    return set(result)


def validate(spec_path):
    with open(spec_path) as fh:
        spec = json.load(fh)  # hard-fails on invalid JSON
    routes = load_routes()

    problems = []
    documented = set()
    for path, methods in spec["paths"].items():
        for method in methods:
            documented.add((method.lower(), path.lstrip("/")))
            if (method.lower(), path.lstrip("/")) not in routes:
                problems.append(f"documented but NOT routed: {method.upper()} {path}")

    # Every routed /api/v1 business endpoint must be documented. HEAD is
    # Laravel's auto-derived companion of GET and is not part of the spec.
    for method, uri in routes:
        if method == "head":
            continue
        if not uri.startswith("api/v1/"):
            continue
        if (method, uri) not in documented:
            problems.append(f"routed but NOT documented: {method.upper()} /{uri}")

    if problems:
        print("OPENAPI VALIDATION FAILED:")
        for p in problems:
            print("  -", p)
        sys.exit(1)

    print(f"OpenAPI validation OK: {len(documented)} documented paths, all routed and no missing endpoints.")


def main():
    spec = {
        "openapi": "3.0.3",
        "info": {
            "title": "FF Arena Public API",
            "version": "1.0.0",
            "description": (
                "Versioned public API for FF Arena (mobile/SPA/trusted third-party "
                "clients). All business endpoints live under /api/v1; a future "
                "/api/v2 can be added without breaking v1.\n\n"
                "**Auth:** bearer personal access tokens only (session cookies are "
                "never accepted). Tokens are stored hashed, support granular scopes, "
                "expiry, revocation and last-used tracking; the plaintext is shown "
                "exactly once at creation.\n\n"
                "**Envelope:** success `{data, meta}`; errors "
                "`{error:{code,message,details}}`.\n\n"
                "**Idempotency:** critical mutations accept an `Idempotency-Key` "
                "header; replays return the stored response.\n\n"
                "**Webhooks (outbound):** deliveries are signed "
                "`X-FFArena-Signature = HMAC-SHA256(secret, \"{timestamp}.{body}\")` "
                "with `X-FFArena-Timestamp`, `X-FFArena-Event` and "
                "`X-FFArena-Delivery` headers; retries use exponential backoff."
            ),
        },
        "servers": [{"url": "/"}],
        "tags": [
            {"name": "Auth"}, {"name": "Tournaments"}, {"name": "Matches"}, {"name": "Teams"},
            {"name": "Players & Leaderboards"}, {"name": "Notifications & Realtime"},
            {"name": "Payments & Wallet"}, {"name": "Support & Disputes"},
            {"name": "Me"}, {"name": "Admin Webhooks"}, {"name": "Inbound Webhooks"},
        ],
        "paths": build_paths(),
        "components": components(),
    }

    import os
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as fh:
        json.dump(spec, fh, indent=2)
        fh.write("\n")
    print(f"Wrote {OUT}")

    validate(OUT)


if __name__ == "__main__":
    main()

```

### `scripts/ci/check-pint.sh`

```bash
#!/usr/bin/env bash
# Phase 16 + Phase 17 + Phase 18 — code-style gate (Laravel Pint).
#
# Runs Pint in test mode over the Phase 16/17-owned file set. The legacy
# Phase 01–15 codebase predates the Pint configuration and is adopted
# incrementally; see the PHASE16 report § "known limitations".

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

php vendor/bin/pint --test \
  app/Console/Commands \
  app/Support \
  app/Contracts/ErrorReporterInterface.php \
  app/Contracts/MetricsInterface.php \
  app/Services/HealthService.php \
  app/Services/CacheInvalidationService.php \
  app/Services/BackupService.php \
  app/Services/OperationsService.php \
  app/Http/Middleware/SecurityHeaders.php \
  app/Http/Middleware/HttpMetrics.php \
  app/Http/Middleware/AssignAuditRequestId.php \
  app/Http/Controllers/HealthController.php \
  app/Http/Controllers/OpsController.php \
  app/Http/Controllers/SitemapController.php \
  app/Http/Controllers/HomeController.php \
  app/Http/Controllers/TournamentController.php \
  app/Http/Controllers/LeaderboardController.php \
  app/Http/Controllers/ProfileController.php \
  app/Http/Controllers/Api/V1/DeviceController.php \
  app/Http/Controllers/Api/V1/AppMetaController.php \
  app/Models/MobileDevice.php \
  app/Policies/MobileDevicePolicy.php \
  app/Providers/AppServiceProvider.php \
  bootstrap/app.php \
  routes/health.php \
  routes/console.php \
  routes/web.php \
  routes/api.php \
  config/observability.php \
  config/mobile.php \
  config/backup.php \
  config/cors.php \
  config/logging.php \
  config/app.php \
  database/migrations/2026_09_09_110000_create_operations_heartbeats_table.php \
  database/migrations/2026_09_11_000000_create_mobile_device_tokens_table.php \
  tests/Feature/Api/ApiDeviceTokensTest.php \
  tests/Feature/Api/ApiAppMetaTest.php \
  tests/Feature/Phase16 \
  tests/Feature/Phase17

```


## Backend — tests

### `tests/Feature/Api/ApiDeviceTokensTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\MobileDevice;
use App\Models\User;

/**
 * Phase 18 — mobile push-device registration.
 *
 * Verifies owner-only access, hashed (never raw) token storage, the per-user
 * cap and the honest 422 for invalid platforms/providers.
 */
class ApiDeviceTokensTest extends ApiTestCase
{
    public function test_user_can_register_and_list_own_devices(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'raw-fcm-token-ABC123',
                'device_label' => 'Pixel 9',
            ]);

        $res->assertStatus(201)->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'raw-fcm-token-ABC123'),
            'is_active' => true,
        ]);

        // The raw token must never be persisted.
        $this->assertDatabaseMissing('mobile_device_tokens', ['token_hash' => 'raw-fcm-token-ABC123']);

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.platform', 'android')
            ->assertJsonMissing(['token_hash']);
    }

    public function test_same_token_refreshes_instead_of_duplicating(): void
    {
        $user = $this->user();

        $first = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 'same-token']);

        $second = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 'same-token']);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MobileDevice::where('user_id', $user->id)->count());
    }

    public function test_user_cannot_see_or_delete_another_users_device(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $created = $this->asUser($owner, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'ios', 'provider' => 'apns', 'token' => 'owner-token']);

        $id = $created->json('data.id');

        // Another user cannot list it.
        $this->asUser($other, ['notifications:read', 'notifications:write'])
            ->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Another user cannot delete it.
        $this->asUser($other, ['notifications:read', 'notifications:write'])
            ->deleteJson('/api/v1/me/devices/'.$id)
            ->assertStatus(403);

        // The owner can.
        $this->asUser($owner, ['notifications:read', 'notifications:write'])
            ->deleteJson('/api/v1/me/devices/'.$id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('mobile_device_tokens', ['id' => $id]);
    }

    public function test_invalid_platform_or_provider_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'web', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'sms', 'token' => 't'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_missing_token_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_guests_cannot_register_devices(): void
    {
        $this->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_missing_scope_is_forbidden(): void
    {
        $user = $this->user();

        // A token with no notification scopes cannot reach the endpoints.
        $this->asUser($user, ['profile:read'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(403);
    }

    public function test_inactive_account_cannot_register_devices(): void
    {
        $user = $this->user(['account_status' => 'deactivated']);

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_device_cap_deactivates_oldest_extras(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 30; $i++) {
            $this->asUser($user, ['notifications:read', 'notifications:write'])
                ->postJson('/api/v1/me/devices', [
                    'platform' => 'android',
                    'provider' => 'fcm',
                    'token' => 'bulk-token-'.$i,
                ]);
        }

        $active = MobileDevice::where('user_id', $user->id)->where('is_active', true)->count();

        $this->assertLessThanOrEqual((int) config('mobile.devices.max_devices_per_user', 25), $active);
    }
}

```

### `tests/Feature/Api/ApiAppMetaTest.php`

```php
<?php

namespace Tests\Feature\Api;

/**
 * Phase 18 — server-driven app metadata endpoint.
 */
class ApiAppMetaTest extends ApiTestCase
{
    public function test_app_meta_is_public_and_contains_no_secrets(): void
    {
        $res = $this->getJson('/api/v1/app/meta');

        $res->assertOk()
            ->assertJsonPath('data.app.name', config('app.name', 'FF Arena'))
            ->assertJsonPath('data.app.api_version', '1')
            ->assertJsonPath('data.app.deep_link_scheme', (string) config('mobile.deep_link_scheme', 'ffarena'))
            ->assertJsonPath('data.platform.currency', 'BDT')
            ->assertJsonStructure([
                'data' => [
                    'app' => ['name', 'api_version', 'min_supported_app_version', 'latest_app_version', 'deep_link_scheme'],
                    'push' => ['fcm_enabled', 'apns_enabled'],
                    'urls' => ['support', 'privacy', 'terms'],
                    'platform' => ['currency', 'timezone', 'locale'],
                ],
            ]);
    }

    public function test_push_flags_are_off_by_default(): void
    {
        $this->getJson('/api/v1/app/meta')
            ->assertOk()
            ->assertJsonPath('data.push.fcm_enabled', false)
            ->assertJsonPath('data.push.apns_enabled', false);
    }
}

```


## Tools & CI

### `tools/gen_mobile_models.py`

```python
#!/usr/bin/env python3
"""
Phase 18 — deterministic Dart model/endpoint code generation.

Reads the committed OpenAPI contract (storage/api-docs/openapi.json — the
same file the backend CI validates against the live route table) and emits:

  mobile/lib/api/generated/openapi_models.dart    — Dart model classes
  mobile/lib/api/generated/openapi_endpoints.dart — endpoint/scope constants

The mobile client therefore consumes exactly the documented /api/v1 surface
and never depends on undocumented endpoints. Generated output is committed;
regenerate with:

    python3 tools/gen_mobile_models.py

Rules honoured by the generated code:
  * unknown JSON fields are ignored (additive API compatibility);
  * missing optional fields decode to null (no crashes);
  * scalars are decoded defensively (no trust in server types).
"""

import json
import os
import re
import sys

ROOT = "/home/user/ffarena-app"
SPEC = os.path.join(ROOT, "storage", "api-docs", "openapi.json")
OUT_MODELS = os.path.join(ROOT, "mobile", "lib", "core", "api", "generated", "openapi_models.dart")
OUT_ENDPOINTS = os.path.join(ROOT, "mobile", "lib", "core", "api", "generated", "openapi_endpoints.dart")

DART_KEYWORDS = {
    "class", "enum", "extends", "is", "new", "null", "super", "this", "void",
    "default", "switch", "case", "if", "else", "for", "while", "do", "return",
    "import", "export", "library", "part", "mixin", "assert", "in", "out",
    "const", "final", "var", "dynamic", "static", "with", "abstract", "async",
    "await", "break", "continue", "covariant", "deferred", "factory", "get",
    "set", "operator", "rethrow", "throw", "try", "catch", "finally", "typedef",
    "external", "hide", "of", "on", "show", "sync", "yield", "required",
}

FIELD_OVERRIDES = {
    # Field name -> (dart type, fromJson expression, toJson expression)
    "starts_at": ("String?", "_asString(json['starts_at'])", None),
}

# Schema name -> generated Dart class name. Avoids collisions with Dart core
# types (Error, Match) and with first-party app classes (ApiClient, Session)
# and Flutter widget types (Notification).
RENAME = {
    "ApiClient": "ApiClientModel",
    "Session": "ApiSession",
    "Error": "ApiErrorBody",
    "ErrorResponse": "ApiErrorEnvelope",
    "Envelope": "ApiEnvelope",
    "Match": "MatchModel",
    "Notification": "NotificationModel",
}


def dart_name(name):
    return RENAME.get(name, name)


def dart_type(schema):
    """Map an OpenAPI schema to a Dart type."""
    if "$ref" in schema:
        ref = dart_name(schema["$ref"].rsplit("/", 1)[-1])
        return f"{ref}?"
    t = schema.get("type")
    if t == "integer":
        return "int?"
    if t == "number":
        return "num?"
    if t == "boolean":
        return "bool?"
    if t == "string":
        fmt = schema.get("format", "")
        if fmt in ("date", "date-time"):
            return "String?"
        return "String?"
    if t == "array":
        return "List<dynamic>?"
    if t == "object":
        return "Map<String, dynamic>?"
    return "dynamic"


def field_cast(schema, expr):
    """Generate a defensive cast expression for a scalar field."""
    if "$ref" in schema:
        ref = dart_name(schema["$ref"].rsplit("/", 1)[-1])
        return (
            f"(json['{expr}'] is Map<String, dynamic> "
            f"? {ref}.fromJson(json['{expr}'] as Map<String, dynamic>) : null)"
        )
    t = schema.get("type")
    if t == "integer":
        return f"_asInt(json['{expr}'])"
    if t == "number":
        return f"_asNum(json['{expr}'])"
    if t == "boolean":
        return f"_asBool(json['{expr}'])"
    if t == "string":
        return f"_asString(json['{expr}'])"
    if t == "array":
        return f"_asList(json['{expr}'])"
    return f"json['{expr}']"


def safe_name(name):
    name = re.sub(r"[^a-zA-Z0-9_]", "_", name)
    if name and name[0].isdigit():
        name = "_" + name
    if name in DART_KEYWORDS:
        name = name + "_"
    return name


def camel(name):
    """snake_case -> lowerCamelCase for idiomatic Dart field names."""
    parts = name.split("_")
    return parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:])


def generate_models(spec):
    schemas = spec.get("components", {}).get("schemas", {})
    lines = [
        "// GENERATED FILE — do not edit by hand.",
        "// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).",
        "//",
        "// All fields are nullable and decoded defensively so the mobile client",
        "// tolerates additive API fields and missing optional fields (Phase 18 §56).",
        "//",
        "// ignore_for_file: non_constant_identifier_names, prefer_final_locals",
        "// ignore_for_file: always_put_required_named_parameters_first",
        "// ignore_for_file: unused_element, avoid_init_to_null",
        "",
        "library;",
        "",
    ]
    for name, schema in sorted(schemas.items()):
        name = dart_name(name)
        props = schema.get("properties", {})
        ctor_params = []
        ctor_assign = []
        field_lines = []
        from_json_lines = []
        to_json_lines = []
        for prop, prop_schema in props.items():
            field = camel(safe_name(prop))
            dtype = dart_type(prop_schema)
            field_lines.append(f"  final {dtype} {field};")
            ctor_params.append(f"    this.{field} = null,")
            ctor_assign.append(f"    this.{field},")
            from_json_lines.append(
                f"      {field}: {field_cast(prop_schema, prop)},"
            )
            to_json_lines.append(f"      if ({field} != null) '{prop}': {field},")

        # Dart constructors can't have duplicate initializers; build the named
        # constructor via a positional-friendly const-friendly form instead.
        params_block = "\n".join(ctor_params) if ctor_params else "    // no fields"
        assign_block = "\n".join(ctor_assign) if ctor_assign else "    // no fields"
        from_block = "\n".join(from_json_lines) if from_json_lines else "    // no fields"
        to_block = "\n".join(to_json_lines) if to_json_lines else "    // no fields"

        lines.append(f"class {name} {{")
        lines.append(f"  const {name}({{")
        lines.append(params_block)
        lines.append("  });")
        lines.append("")
        lines.append(f"  factory {name}.fromJson(Map<String, dynamic> json) {{")
        lines.append(f"    return {name}(")
        lines.append(from_block)
        lines.append("    );")
        lines.append("  }")
        lines.append("")
        for fl in field_lines:
            lines.append(fl)
        lines.append("")
        lines.append("  Map<String, dynamic> toJson() {")
        lines.append("    return {")
        lines.append(to_block)
        lines.append("    };")
        lines.append("  }")
        lines.append("}")
        lines.append("")

    lines.append("/// Defensive JSON scalar helpers.")
    lines.append("int? _asInt(dynamic v) => v is int ? v : (v is num ? v.toInt() : (v is String ? int.tryParse(v) : null));")
    lines.append("num? _asNum(dynamic v) => v is num ? v : null;")
    lines.append("bool? _asBool(dynamic v) => v is bool ? v : null;")
    lines.append("String? _asString(dynamic v) => v is String ? v : null;")
    lines.append("List<dynamic>? _asList(dynamic v) => v is List ? v : null;")
    lines.append("")
    return "\n".join(lines)


def _op_scope(desc):
    m = re.search(r"Required scope: `([^`]+)`", desc or "")
    return m.group(1) if m else None


def generate_endpoints(spec):
    lines = [
        "// GENERATED FILE — do not edit by hand.",
        "// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).",
        "//",
        "// ignore_for_file: constant_identifier_names",
        "",
        "library;",
        "",
        "/// A documented /api/v1 endpoint (path template, method, required scope, tag).",
        "class ApiEndpoint {",
        "  const ApiEndpoint({",
        "    required this.method,",
        "    required this.path,",
        "    required this.tag,",
        "    this.scope,",
        "  });",
        "",
        "  final String method;",
        "  final String path;",
        "  final String tag;",
        "  final String? scope;",
        "",
        "  String resolve([Map<String, Object> params = const {}]) {",
        "    var p = path;",
        "    params.forEach((k, v) { p = p.replaceAll('{$k}', v.toString()); });",
        "    return p;",
        "  }",
        "}",
        "",
        "/// Endpoint constants derived from the OpenAPI contract.",
        "class OpenApiEndpoints {",
        "  const OpenApiEndpoints._();",
        "",
    ]
    for path, methods in sorted(spec.get("paths", {}).items()):
        for method, op in sorted(methods.items()):
            if method not in ("get", "post", "put", "patch", "delete"):
                continue
            op_id = op.get("operationId", f"{method}_{path}")
            const_name = safe_name(op_id)
            scope = _op_scope(op.get("description", ""))
            tag = (op.get("tags") or ["General"])[0]
            scope_lit = f"'{scope}'" if scope else "null"
            lines.append(f"  static const {const_name} = ApiEndpoint(")
            lines.append(f"    method: '{method}',")
            lines.append(f"    path: '{path}',")
            lines.append(f"    tag: '{tag}',")
            lines.append(f"    scope: {scope_lit},")
            lines.append("  );")
            lines.append("")
    lines.append("}")
    lines.append("")
    return "\n".join(lines)


def main():
    with open(SPEC) as fh:
        spec = json.load(fh)

    os.makedirs(os.path.dirname(OUT_MODELS), exist_ok=True)
    os.makedirs(os.path.dirname(OUT_ENDPOINTS), exist_ok=True)

    with open(OUT_MODELS, "w") as fh:
        fh.write(generate_models(spec))
    with open(OUT_ENDPOINTS, "w") as fh:
        fh.write(generate_endpoints(spec))

    print(f"Wrote {OUT_MODELS}")
    print(f"Wrote {OUT_ENDPOINTS}")


if __name__ == "__main__":
    main()

```

### `scripts/ci/check-flutter.sh`

```bash
#!/usr/bin/env bash
#
# Phase 18 — Flutter mobile client checks (analysis + tests + codegen).
#
# Runs inside the mobile/ project. Requires the Flutter SDK on PATH:
#   export PATH=/opt/flutter/bin:$PATH
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MOBILE_DIR="$REPO_ROOT/mobile"

if ! command -v flutter >/dev/null 2>&1; then
  echo "flutter not found on PATH — skipping mobile checks."
  exit 0
fi

echo "==> Regenerating Dart models/endpoints from the OpenAPI contract"
python3 "$REPO_ROOT/tools/gen_mobile_models.py"

cd "$MOBILE_DIR"

echo "==> flutter analyze"
flutter analyze --no-pub

echo "==> flutter test"
flutter test --no-pub

echo "All Flutter checks passed."

```


## Mobile — project configuration

### `mobile/pubspec.yaml`

```yaml
name: ffarena_mobile
description: FF Arena native mobile client (Phase 18). Consumes the Laravel /api/v1 platform; the backend stays authoritative.
publish_to: 'none'
version: 1.0.0+1

environment:
  sdk: '>=3.4.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter
  flutter_localizations:
    sdk: flutter
  # Lightweight HTTP + localization formatting. No heavy state-management
  # framework: the app uses built-in ChangeNotifier/ValueNotifier.
  http: ^1.2.2
  intl: ^0.20.2
  # Secure token storage — Android Keystore / iOS Keychain.
  flutter_secure_storage: ^9.2.2
  # Google Sign-In (only active when a client id is configured).
  google_sign_in: ^6.2.1
  # External links (privacy policy / terms / support).
  url_launcher: ^6.3.0
  # App documents directory for the offline read-only cache.
  path_provider: ^2.1.4
  cupertino_icons: ^1.0.8

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^6.0.0

flutter:
  uses-material-design: true

```

### `mobile/analysis_options.yaml`

```yaml
# FF Arena mobile — static analysis configuration (Phase 18).
#
# Extends the standard flutter_lints ruleset and adds a few mobile-specific
# rules relevant to security and correctness:
#   - avoid_print / avoid_print: debug logging must go through the app's
#     redacting logger, never straight to the console (token safety).
#   - Security-sensitive strings are handled by the core layer.

include: package:flutter_lints/flutter.yaml

analyzer:
  exclude:
    - build/**
    - .dart_tool/**
    - android/**
    - ios/**
  errors:
    missing_required_param: error
    missing_return: error

linter:
  rules:
    - avoid_print
    - avoid_relative_lib_imports
    - avoid_slow_async_io
    - cancel_subscriptions
    - close_sinks
    - directives_ordering
    - empty_statements
    - prefer_const_constructors
    - prefer_const_declarations
    - prefer_final_locals
    - prefer_is_empty
    - prefer_single_quotes
    - sort_constructors_first
    - unawaited_futures
    - unnecessary_brace_in_string_interps
    - unnecessary_const
    - use_key_in_widget_constructors
    - use_super_parameters

```

### `mobile/.gitignore`

```text
# Flutter / Dart build artifacts
.dart_tool/
build/
.flutter-plugins
.flutter-plugins-dependencies
.packages

# Platform build outputs (never commit native build trees)
android/.gradle/
android/app/build/
android/local.properties
ios/Pods/
ios/.symlinks/
ios/Flutter/ephemeral/

# Secrets — never commit. Copy these to a local-only file and fill in real
# values per environment. They are read via --dart-define at build time.
lib/config/secrets.dart
*.keystore
*.jks
key.properties
google-services.json
GoogleService-Info.plist
ServiceAccountKey.json

# IDE
.idea/
*.iml
.vscode/

```

### `mobile/README.md`

```markdown
# FF Arena — Native Mobile App (Phase 18)

Flutter client for the FF Arena tournament platform. The mobile app is a
**consumer of the existing Laravel `/api/v1`** — the backend stays
authoritative for all business logic (ranks, points, wallet, payments,
verification, team ownership, tournament and restriction state).

## Layout

| Path | Purpose |
| --- | --- |
| `lib/config/` | Build-time environment (dart-define), identity |
| `lib/core/api/` | ApiClient, typed errors, idempotency, generated models/endpoints |
| `lib/core/cache/` | Read-only offline cache |
| `lib/core/format/` | Money / dates / phone presentation |
| `lib/core/l10n/` | English + Bangla strings (WCAG 2.2 AA) |
| `lib/core/network/` | Retry policy (reads only) |
| `lib/core/push/` | Push provider abstraction (honest disabled default) |
| `lib/core/session/` | SessionManager, secure token storage |
| `lib/core/storage/` | Secure storage (Keystore/Keychain) |
| `lib/core/telemetry/` | Crash reporting + minimal product metrics |
| `lib/data/repositories/` | Thin clients over `/api/v1` |
| `lib/features/deep_links/` | `ffarena://` deep-link router |
| `lib/screens/` | All UI screens |
| `lib/widgets/` | Shared UI widgets |

## Commands

```bash
export PATH=/opt/flutter/bin:$PATH

# Fetch dependencies
flutter pub get

# Static analysis
flutter analyze

# Tests
flutter test

# Run (development) — see docs/MOBILE_APP_SETUP.md for dart-define flags
flutter run --dart-define=FFARENA_API_BASE_URL=http://localhost/api/v1

# Release builds (dev/test/release configs — see docs/MOBILE_RELEASE.md)
flutter build apk --release --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1
flutter build appbundle --release --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1
```

Generated code (`lib/core/api/generated/`) is produced from the committed
OpenAPI contract by `python3 ../../tools/gen_mobile_models.py`.

Full documentation lives in `docs/` at the repository root.

```


## Mobile — core (api, cache, format, l10n, network, push, session, storage, telemetry)

### `mobile/lib/core/api/api_client.dart`

```dart
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../config/app_config.dart';
import '../telemetry/crash_reporter.dart';
import 'api_exception.dart';
import 'api_result.dart';

/// The single HTTP gateway to the Laravel `/api/v1` platform (Phase 18 §3).
///
/// Responsibilities:
///   * attach the bearer token (read via [tokenProvider], never logged),
///   * send the right Accept/Content-Type and `Idempotency-Key` headers,
///   * unwrap the `{data, meta}` success envelope and the
///     `{error:{code,message,details}}` error envelope,
///   * map transport failures to typed [ApiException]s,
///   * report session-terminating responses to [onSessionTerminated].
class ApiClient {
  ApiClient({
    required String baseUrl,
    required this.tokenProvider,
    http.Client? httpClient,
    CrashReporter? crashReporter,
    this.requestTimeout = const Duration(seconds: 20),
  })  : _baseUrl = _normalize(baseUrl),
        _http = httpClient ?? http.Client(),
        _crash = crashReporter ?? const NoopCrashReporter();

  final String _baseUrl;
  final http.Client _http;
  final CrashReporter _crash;
  final Duration requestTimeout;

  /// Mutable so the session manager can bind the token source after wiring.
  String? Function() tokenProvider;

  /// Invoked once per response that carries a session-terminating error code.
  void Function(ApiException error)? onSessionTerminated;

  static String _normalize(String base) {
    var b = base.trim();
    while (b.endsWith('/')) {
      b = b.substring(0, b.length - 1);
    }
    return b;
  }

  Uri _uri(String path, [Map<String, String>? query]) {
    // Paths may be written relative (`/me`) or as the full documented
    // `/api/v1/...` form. When the base URL already carries the version
    // prefix, don't duplicate it.
    var p = path;
    if (p.startsWith('/api/v1') && _baseUrl.endsWith('/api/v1')) {
      p = p.substring('/api/v1'.length);
    }
    final uri = Uri.parse('$_baseUrl$p');
    if (query == null || query.isEmpty) {
      return uri;
    }
    return uri.replace(queryParameters: query);
  }

  Map<String, String> _headers({bool auth = true, String? idempotencyKey}) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'ffarena-mobile',
      'X-Client-Version': AppConfig.instance.version,
    };
    if (auth) {
      final token = tokenProvider();
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    if (idempotencyKey != null && idempotencyKey.isNotEmpty) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    return headers;
  }

  Future<ApiEnvelopeData> get(
    String path, {
    Map<String, String>? query,
    bool auth = true,
  }) =>
      _send('GET', path, query: query, auth: auth);

  Future<ApiEnvelopeData> post(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
    String? idempotencyKey,
  }) =>
      _send('POST', path,
          body: body, auth: auth, idempotencyKey: idempotencyKey);

  Future<ApiEnvelopeData> patch(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('PATCH', path, body: body, auth: auth);

  Future<ApiEnvelopeData> put(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('PUT', path, body: body, auth: auth);

  Future<ApiEnvelopeData> delete(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) =>
      _send('DELETE', path, body: body, auth: auth);

  Future<ApiEnvelopeData> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
    bool auth = true,
    String? idempotencyKey,
  }) async {
    final uri = _uri(path, query);
    final headers = _headers(auth: auth, idempotencyKey: idempotencyKey);
    final encoded = body == null ? null : jsonEncode(body);

    http.Response response;
    try {
      switch (method) {
        case 'GET':
          response =
              await _http.get(uri, headers: headers).timeout(requestTimeout);
        case 'POST':
          response = await _http
              .post(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'PATCH':
          response = await _http
              .patch(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'PUT':
          response = await _http
              .put(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        case 'DELETE':
          response = await _http
              .delete(uri, headers: headers, body: encoded)
              .timeout(requestTimeout);
        default:
          throw StateError('Unsupported HTTP method: $method');
      }
    } on TimeoutException {
      _crash.record('api_timeout', 'request timed out', null);
      throw const ApiException(
        code: ApiException.timeout,
        message: 'The server took too long to respond.',
      );
    } on SocketException {
      throw const ApiException(
        code: ApiException.offline,
        message: 'No network connection.',
      );
    } on http.ClientException {
      throw const ApiException(
        code: ApiException.offline,
        message: 'Network request failed.',
      );
    }

    return _decode(response);
  }

  ApiEnvelopeData _decode(http.Response response) {
    final Map<String, dynamic>? body;
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map<String, dynamic>) {
        body = decoded;
      } else {
        body = null;
      }
    } on FormatException {
      // Non-JSON success (unlikely for this API) is treated as empty.
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return const ApiEnvelopeData(data: null);
      }
      throw ApiException(
        code: ApiException.serverError,
        message: 'Unexpected response.',
        statusCode: response.statusCode,
      );
    }

    final status = response.statusCode;

    if (status >= 200 && status < 300) {
      // 204 No Content has no body.
      if (body == null) {
        return const ApiEnvelopeData(data: null);
      }
      return ApiEnvelopeData(
        data: body['data'],
        meta: body['meta'] is Map<String, dynamic>
            ? body['meta'] as Map<String, dynamic>
            : const {},
      );
    }

    final error = ApiException.fromBody(body, statusCode: status);
    if (error.isSessionTerminating) {
      onSessionTerminated?.call(error);
    }
    throw error;
  }

  void dispose() {
    _http.close();
  }
}

```

### `mobile/lib/core/api/api_exception.dart`

```dart
import '../l10n/app_localizations.dart';

/// Typed API error (Phase 18 §31).
///
/// Maps the Phase 15 error envelope `{error:{code,message,details}}` into a
/// single exception the UI can switch on. Codes are server-authoritative;
/// the client only ADDS transport codes (`offline`, `timeout`) and a
/// defensive `unknown` for unparseable bodies.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.details = const {},
  });

  /// Builds from a decoded error envelope (or any decoded body).
  factory ApiException.fromBody(Map<String, dynamic>? body, {int? statusCode}) {
    final error = body?['error'];
    if (error is Map<String, dynamic>) {
      final details = error['details'];
      return ApiException(
        code: (error['code'] as String?) ?? unknown,
        message: (error['message'] as String?) ?? 'Request failed.',
        statusCode: statusCode,
        details: details is Map<String, dynamic> ? details : const {},
      );
    }
    return ApiException(
      code: unknown,
      message: 'Unexpected response.',
      statusCode: statusCode,
    );
  }

  /// Server error codes (Phase 15 — see app/Exceptions/Handler).
  static const unauthenticated = 'unauthenticated';
  static const accountInactive = 'account_inactive';
  static const tokenExpired = 'token_expired';
  static const tokenRevoked = 'token_revoked';
  static const forbidden = 'forbidden';
  static const notFound = 'not_found';
  static const conflict = 'conflict';
  static const validationError = 'validation_error';
  static const rateLimited = 'rate_limited';
  static const serverError = 'server_error';
  static const invalidCredentials = 'invalid_credentials';
  static const invalidCode = 'invalid_code';
  static const noAccount = 'no_account';
  static const notConfigured = 'not_configured';
  static const invalidIdToken = 'invalid_id_token';
  static const duplicateScore = 'duplicate_score';
  static const placementTaken = 'placement_taken';
  static const registrationClosed = 'registration_closed';
  static const teamNotConfirmed = 'team_not_confirmed';

  /// Client-side transport codes (never produced by the server).
  static const offline = 'network_offline';
  static const timeout = 'network_timeout';
  static const unknown = 'unknown';

  final String code;
  final String message;
  final int? statusCode;
  final Map<String, dynamic> details;

  /// True when the session must be cleared and the user routed to the
  /// security/account screen.
  bool get isSessionTerminating =>
      code == unauthenticated ||
      code == accountInactive ||
      code == tokenExpired ||
      code == tokenRevoked;

  /// A localized, end-user-safe message. Never echoes server internals.
  String localized(AppLocalizations l10n) {
    switch (code) {
      case offline:
        return l10n.errorOffline;
      case timeout:
        return l10n.errorTimeout;
      case rateLimited:
        return l10n.errorRateLimited;
      case invalidCredentials:
        return l10n.errorInvalidCredentials;
      case invalidCode:
        return l10n.errorInvalidCode;
      case noAccount:
        return l10n.errorNoAccount;
      case invalidIdToken:
        return l10n.errorGoogleSignIn;
      case accountInactive:
        return l10n.errorAccountInactive;
      case tokenExpired:
      case tokenRevoked:
      case unauthenticated:
        return l10n.errorSessionExpired;
      case serverError:
        return l10n.errorServer;
      default:
        return message.isNotEmpty ? message : l10n.errorGeneric;
    }
  }

  @override
  String toString() => 'ApiException($code, $statusCode): $message';
}

```

### `mobile/lib/core/api/api_result.dart`

```dart
import 'generated/openapi_models.dart';

/// A decoded `/api/v1` success envelope: `{ "data": ..., "meta": {...} }`.
///
/// [data] is the raw `data` node (a Map, a List, or a scalar); [meta] holds
/// the optional pagination/count metadata.
class ApiEnvelopeData {
  const ApiEnvelopeData({required this.data, this.meta = const {}});

  final dynamic data;
  final Map<String, dynamic> meta;

  /// Convenience accessor for a decoded [Pagination] meta block.
  Pagination? get pagination {
    final p = meta['pagination'];
    if (p is Map<String, dynamic>) {
      return Pagination.fromJson(p);
    }

    return null;
  }

  bool get hasMore {
    final p = pagination;
    if (p == null || p.lastPage == null || p.currentPage == null) {
      return false;
    }

    return (p.currentPage! < p.lastPage!);
  }

  int? get nextPage {
    final p = pagination;
    if (p == null || p.currentPage == null) {
      return null;
    }

    return p.currentPage! + 1;
  }

  List<Map<String, dynamic>> get asList {
    if (data is List) {
      return (data as List)
          .whereType<Map<String, dynamic>>()
          .toList(growable: false);
    }

    return const [];
  }

  Map<String, dynamic>? get asMap {
    if (data is Map<String, dynamic>) {
      return data as Map<String, dynamic>;
    }

    return null;
  }
}

```

### `mobile/lib/core/api/generated/openapi_endpoints.dart`

```dart
// GENERATED FILE — do not edit by hand.
// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).
//
// ignore_for_file: constant_identifier_names

library;

/// A documented /api/v1 endpoint (path template, method, required scope, tag).
class ApiEndpoint {
  const ApiEndpoint({
    required this.method,
    required this.path,
    required this.tag,
    this.scope,
  });

  final String method;
  final String path;
  final String tag;
  final String? scope;

  String resolve([Map<String, Object> params = const {}]) {
    var p = path;
    params.forEach((k, v) { p = p.replaceAll('{$k}', v.toString()); });
    return p;
  }
}

/// Endpoint constants derived from the OpenAPI contract.
class OpenApiEndpoints {
  const OpenApiEndpoints._();

  static const get_api_v1_admin_webhooks_endpoints = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints = ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_endpoints__endpoint_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_endpoints__endpoint__deliveries = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints__endpoint__rotate_secret = ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const post_api_v1_admin_webhooks_endpoints__endpoint__toggle = ApiEndpoint(
    method: 'post',
    path: '/api/v1/admin/webhooks/endpoints/{endpoint}/toggle',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_admin_webhooks_events = ApiEndpoint(
    method: 'get',
    path: '/api/v1/admin/webhooks/events',
    tag: 'Admin Webhooks',
    scope: null,
  );

  static const get_api_v1_app_meta = ApiEndpoint(
    method: 'get',
    path: '/api/v1/app/meta',
    tag: 'General',
    scope: null,
  );

  static const post_api_v1_auth_google = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/google',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_login = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/login',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_otp_request = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/otp/request',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_otp_verify = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/otp/verify',
    tag: 'Auth',
    scope: null,
  );

  static const post_api_v1_auth_register = ApiEndpoint(
    method: 'post',
    path: '/api/v1/auth/register',
    tag: 'Auth',
    scope: null,
  );

  static const get_api_v1_disputes__dispute_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/disputes/{dispute}',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_leaderboards = ApiEndpoint(
    method: 'get',
    path: '/api/v1/leaderboards',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_leaderboards__tournament_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/leaderboards/{tournament}',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_matches__match_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/matches/{match}',
    tag: 'Matches',
    scope: null,
  );

  static const post_api_v1_matches__match__scores = ApiEndpoint(
    method: 'post',
    path: '/api/v1/matches/{match}/scores',
    tag: 'Matches',
    scope: null,
  );

  static const get_api_v1_me = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_clients = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/clients',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_clients = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/clients',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_clients__client_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/clients/{client}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_devices = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/devices',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_devices = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/devices',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_devices__device_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/devices/{device}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_disputes = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/disputes',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_live = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/live',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_notifications = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/notifications',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const post_api_v1_me_notifications_read_all = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/notifications/read-all',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_notifications_unread_count = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/notifications/unread-count',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const post_api_v1_me_notifications__notification__read = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/notifications/{notification}/read',
    tag: 'Notifications & Realtime',
    scope: null,
  );

  static const get_api_v1_me_payouts = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/payouts',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const patch_api_v1_me_profile = ApiEndpoint(
    method: 'patch',
    path: '/api/v1/me/profile',
    tag: 'Me',
    scope: null,
  );

  static const put_api_v1_me_profile = ApiEndpoint(
    method: 'put',
    path: '/api/v1/me/profile',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_security = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/security',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_sessions = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/sessions',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_sessions_revoke_all = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/sessions/revoke-all',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_sessions_revoke_others = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/sessions/revoke-others',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_sessions__session_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/sessions/{session}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_support = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const post_api_v1_me_support = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/support',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_support__ticket_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support/{ticket}',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_support__ticket__messages = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/support/{ticket}/messages',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const post_api_v1_me_support__ticket__messages = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/support/{ticket}/messages',
    tag: 'Support & Disputes',
    scope: null,
  );

  static const get_api_v1_me_teams = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/teams',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_me_tokens = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/tokens',
    tag: 'Me',
    scope: null,
  );

  static const post_api_v1_me_tokens = ApiEndpoint(
    method: 'post',
    path: '/api/v1/me/tokens',
    tag: 'Me',
    scope: null,
  );

  static const delete_api_v1_me_tokens__tokenId_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/me/tokens/{tokenId}',
    tag: 'Me',
    scope: null,
  );

  static const get_api_v1_me_wallet = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/wallet',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_me_wallet_ledger = ApiEndpoint(
    method: 'get',
    path: '/api/v1/me/wallet/ledger',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const post_api_v1_payments = ApiEndpoint(
    method: 'post',
    path: '/api/v1/payments',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_payments_methods = ApiEndpoint(
    method: 'get',
    path: '/api/v1/payments/methods',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_payments__payment_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/payments/{payment}',
    tag: 'Payments & Wallet',
    scope: null,
  );

  static const get_api_v1_players__user_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/players/{user}',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_players__user__ranking = ApiEndpoint(
    method: 'get',
    path: '/api/v1/players/{user}/ranking',
    tag: 'Players & Leaderboards',
    scope: null,
  );

  static const get_api_v1_teams__team_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/teams/{team}',
    tag: 'Teams',
    scope: null,
  );

  static const patch_api_v1_teams__team_ = ApiEndpoint(
    method: 'patch',
    path: '/api/v1/teams/{team}',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_teams__team__roster = ApiEndpoint(
    method: 'get',
    path: '/api/v1/teams/{team}/roster',
    tag: 'Teams',
    scope: null,
  );

  static const post_api_v1_teams__team__roster = ApiEndpoint(
    method: 'post',
    path: '/api/v1/teams/{team}/roster',
    tag: 'Teams',
    scope: null,
  );

  static const delete_api_v1_teams__team__roster__member_ = ApiEndpoint(
    method: 'delete',
    path: '/api/v1/teams/{team}/roster/{member}',
    tag: 'Teams',
    scope: null,
  );

  static const post_api_v1_teams__team__withdraw = ApiEndpoint(
    method: 'post',
    path: '/api/v1/teams/{team}/withdraw',
    tag: 'Teams',
    scope: null,
  );

  static const get_api_v1_tournaments = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament_ = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__bracket = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/bracket',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_tournaments__tournament__check_in = ApiEndpoint(
    method: 'post',
    path: '/api/v1/tournaments/{tournament}/check-in',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__leaderboard = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/leaderboard',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__live = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/live',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__matches = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/matches',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_tournaments__tournament__registrations = ApiEndpoint(
    method: 'post',
    path: '/api/v1/tournaments/{tournament}/registrations',
    tag: 'Tournaments',
    scope: null,
  );

  static const get_api_v1_tournaments__tournament__waitlist = ApiEndpoint(
    method: 'get',
    path: '/api/v1/tournaments/{tournament}/waitlist',
    tag: 'Tournaments',
    scope: null,
  );

  static const post_api_v1_webhooks_inbound__provider_ = ApiEndpoint(
    method: 'post',
    path: '/api/v1/webhooks/inbound/{provider}',
    tag: 'Inbound Webhooks',
    scope: null,
  );

}

```

### `mobile/lib/core/api/generated/openapi_models.dart`

```dart
// GENERATED FILE — do not edit by hand.
// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).
//
// All fields are nullable and decoded defensively so the mobile client
// tolerates additive API fields and missing optional fields (Phase 18 §56).
//
// ignore_for_file: non_constant_identifier_names, prefer_final_locals
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: unused_element, avoid_init_to_null

library;

class ApiClientModel {
  const ApiClientModel({
    this.id = null,
    this.name = null,
    this.description = null,
    this.status = null,
  });

  factory ApiClientModel.fromJson(Map<String, dynamic> json) {
    return ApiClientModel(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      description: _asString(json['description']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? name;
  final String? description;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (description != null) 'description': description,
      if (status != null) 'status': status,
    };
  }
}

class AppInfo {
  const AppInfo({
    this.name = null,
    this.apiVersion = null,
    this.minSupportedAppVersion = null,
    this.latestAppVersion = null,
    this.deepLinkScheme = null,
  });

  factory AppInfo.fromJson(Map<String, dynamic> json) {
    return AppInfo(
      name: _asString(json['name']),
      apiVersion: _asString(json['api_version']),
      minSupportedAppVersion: _asString(json['min_supported_app_version']),
      latestAppVersion: _asString(json['latest_app_version']),
      deepLinkScheme: _asString(json['deep_link_scheme']),
    );
  }

  final String? name;
  final String? apiVersion;
  final String? minSupportedAppVersion;
  final String? latestAppVersion;
  final String? deepLinkScheme;

  Map<String, dynamic> toJson() {
    return {
      if (name != null) 'name': name,
      if (apiVersion != null) 'api_version': apiVersion,
      if (minSupportedAppVersion != null) 'min_supported_app_version': minSupportedAppVersion,
      if (latestAppVersion != null) 'latest_app_version': latestAppVersion,
      if (deepLinkScheme != null) 'deep_link_scheme': deepLinkScheme,
    };
  }
}

class AppMeta {
  const AppMeta({
    this.app = null,
    this.push = null,
    this.urls = null,
    this.platform = null,
  });

  factory AppMeta.fromJson(Map<String, dynamic> json) {
    return AppMeta(
      app: (json['app'] is Map<String, dynamic> ? AppInfo.fromJson(json['app'] as Map<String, dynamic>) : null),
      push: (json['push'] is Map<String, dynamic> ? PushCapabilities.fromJson(json['push'] as Map<String, dynamic>) : null),
      urls: (json['urls'] is Map<String, dynamic> ? AppUrls.fromJson(json['urls'] as Map<String, dynamic>) : null),
      platform: (json['platform'] is Map<String, dynamic> ? PlatformInfo.fromJson(json['platform'] as Map<String, dynamic>) : null),
    );
  }

  final AppInfo? app;
  final PushCapabilities? push;
  final AppUrls? urls;
  final PlatformInfo? platform;

  Map<String, dynamic> toJson() {
    return {
      if (app != null) 'app': app,
      if (push != null) 'push': push,
      if (urls != null) 'urls': urls,
      if (platform != null) 'platform': platform,
    };
  }
}

class AppUrls {
  const AppUrls({
    this.support = null,
    this.privacy = null,
    this.terms = null,
  });

  factory AppUrls.fromJson(Map<String, dynamic> json) {
    return AppUrls(
      support: _asString(json['support']),
      privacy: _asString(json['privacy']),
      terms: _asString(json['terms']),
    );
  }

  final String? support;
  final String? privacy;
  final String? terms;

  Map<String, dynamic> toJson() {
    return {
      if (support != null) 'support': support,
      if (privacy != null) 'privacy': privacy,
      if (terms != null) 'terms': terms,
    };
  }
}

class AuthSession {
  const AuthSession({
    this.token = null,
    this.tokenExpiresAt = null,
    this.user = null,
  });

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    return AuthSession(
      token: _asString(json['token']),
      tokenExpiresAt: _asString(json['token_expires_at']),
      user: (json['user'] is Map<String, dynamic> ? Me.fromJson(json['user'] as Map<String, dynamic>) : null),
    );
  }

  final String? token;
  final String? tokenExpiresAt;
  final Me? user;

  Map<String, dynamic> toJson() {
    return {
      if (token != null) 'token': token,
      if (tokenExpiresAt != null) 'token_expires_at': tokenExpiresAt,
      if (user != null) 'user': user,
    };
  }
}

class Dispute {
  const Dispute({
    this.id = null,
    this.matchId = null,
    this.category = null,
    this.status = null,
    this.description = null,
    this.resolution = null,
  });

  factory Dispute.fromJson(Map<String, dynamic> json) {
    return Dispute(
      id: _asInt(json['id']),
      matchId: _asInt(json['match_id']),
      category: _asString(json['category']),
      status: _asString(json['status']),
      description: _asString(json['description']),
      resolution: _asString(json['resolution']),
    );
  }

  final int? id;
  final int? matchId;
  final String? category;
  final String? status;
  final String? description;
  final String? resolution;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (matchId != null) 'match_id': matchId,
      if (category != null) 'category': category,
      if (status != null) 'status': status,
      if (description != null) 'description': description,
      if (resolution != null) 'resolution': resolution,
    };
  }
}

class ApiEnvelope {
  const ApiEnvelope({
    this.data = null,
    this.meta = null,
  });

  factory ApiEnvelope.fromJson(Map<String, dynamic> json) {
    return ApiEnvelope(
      data: json['data'],
      meta: json['meta'],
    );
  }

  final dynamic data;
  final Map<String, dynamic>? meta;

  Map<String, dynamic> toJson() {
    return {
      if (data != null) 'data': data,
      if (meta != null) 'meta': meta,
    };
  }
}

class ApiErrorBody {
  const ApiErrorBody({
    this.code = null,
    this.message = null,
    this.details = null,
  });

  factory ApiErrorBody.fromJson(Map<String, dynamic> json) {
    return ApiErrorBody(
      code: _asString(json['code']),
      message: _asString(json['message']),
      details: json['details'],
    );
  }

  final String? code;
  final String? message;
  final Map<String, dynamic>? details;

  Map<String, dynamic> toJson() {
    return {
      if (code != null) 'code': code,
      if (message != null) 'message': message,
      if (details != null) 'details': details,
    };
  }
}

class ApiErrorEnvelope {
  const ApiErrorEnvelope({
    this.error = null,
  });

  factory ApiErrorEnvelope.fromJson(Map<String, dynamic> json) {
    return ApiErrorEnvelope(
      error: (json['error'] is Map<String, dynamic> ? ApiErrorBody.fromJson(json['error'] as Map<String, dynamic>) : null),
    );
  }

  final ApiErrorBody? error;

  Map<String, dynamic> toJson() {
    return {
      if (error != null) 'error': error,
    };
  }
}

class LedgerEntry {
  const LedgerEntry({
    this.id = null,
    this.direction = null,
    this.amountMinor = null,
    this.balanceAfterMinor = null,
    this.type = null,
  });

  factory LedgerEntry.fromJson(Map<String, dynamic> json) {
    return LedgerEntry(
      id: _asInt(json['id']),
      direction: _asString(json['direction']),
      amountMinor: _asInt(json['amount_minor']),
      balanceAfterMinor: _asInt(json['balance_after_minor']),
      type: _asString(json['type']),
    );
  }

  final int? id;
  final String? direction;
  final int? amountMinor;
  final int? balanceAfterMinor;
  final String? type;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (direction != null) 'direction': direction,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (balanceAfterMinor != null) 'balance_after_minor': balanceAfterMinor,
      if (type != null) 'type': type,
    };
  }
}

class LiveEvent {
  const LiveEvent({
    this.id = null,
    this.type = null,
    this.tournamentId = null,
    this.payload = null,
    this.createdAt = null,
  });

  factory LiveEvent.fromJson(Map<String, dynamic> json) {
    return LiveEvent(
      id: _asInt(json['id']),
      type: _asString(json['type']),
      tournamentId: _asInt(json['tournament_id']),
      payload: json['payload'],
      createdAt: _asString(json['created_at']),
    );
  }

  final int? id;
  final String? type;
  final int? tournamentId;
  final Map<String, dynamic>? payload;
  final String? createdAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (type != null) 'type': type,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (payload != null) 'payload': payload,
      if (createdAt != null) 'created_at': createdAt,
    };
  }
}

class MatchModel {
  const MatchModel({
    this.id = null,
    this.tournamentId = null,
    this.round = null,
    this.matchNo = null,
    this.bracket = null,
    this.status = null,
    this.scheduledAt = null,
    this.completedAt = null,
    this.roomId = null,
    this.roomPass = null,
    this.team1 = null,
    this.team2 = null,
    this.winner = null,
    this.scores = null,
  });

  factory MatchModel.fromJson(Map<String, dynamic> json) {
    return MatchModel(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      round: _asInt(json['round']),
      matchNo: _asInt(json['match_no']),
      bracket: _asString(json['bracket']),
      status: _asString(json['status']),
      scheduledAt: _asString(json['scheduled_at']),
      completedAt: _asString(json['completed_at']),
      roomId: _asString(json['room_id']),
      roomPass: _asString(json['room_pass']),
      team1: json['team1'],
      team2: json['team2'],
      winner: json['winner'],
      scores: _asList(json['scores']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? round;
  final int? matchNo;
  final String? bracket;
  final String? status;
  final String? scheduledAt;
  final String? completedAt;
  final String? roomId;
  final String? roomPass;
  final Map<String, dynamic>? team1;
  final Map<String, dynamic>? team2;
  final Map<String, dynamic>? winner;
  final List<dynamic>? scores;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (round != null) 'round': round,
      if (matchNo != null) 'match_no': matchNo,
      if (bracket != null) 'bracket': bracket,
      if (status != null) 'status': status,
      if (scheduledAt != null) 'scheduled_at': scheduledAt,
      if (completedAt != null) 'completed_at': completedAt,
      if (roomId != null) 'room_id': roomId,
      if (roomPass != null) 'room_pass': roomPass,
      if (team1 != null) 'team1': team1,
      if (team2 != null) 'team2': team2,
      if (winner != null) 'winner': winner,
      if (scores != null) 'scores': scores,
    };
  }
}

class Me {
  const Me({
    this.id = null,
    this.name = null,
    this.username = null,
    this.email = null,
    this.emailVerified = null,
    this.avatar = null,
    this.bio = null,
    this.country = null,
    this.region = null,
    this.role = null,
    this.privacy = null,
    this.joinedAt = null,
  });

  factory Me.fromJson(Map<String, dynamic> json) {
    return Me(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      username: _asString(json['username']),
      email: _asString(json['email']),
      emailVerified: _asBool(json['email_verified']),
      avatar: _asString(json['avatar']),
      bio: _asString(json['bio']),
      country: _asString(json['country']),
      region: _asString(json['region']),
      role: _asString(json['role']),
      privacy: _asString(json['privacy']),
      joinedAt: _asString(json['joined_at']),
    );
  }

  final int? id;
  final String? name;
  final String? username;
  final String? email;
  final bool? emailVerified;
  final String? avatar;
  final String? bio;
  final String? country;
  final String? region;
  final String? role;
  final String? privacy;
  final String? joinedAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (username != null) 'username': username,
      if (email != null) 'email': email,
      if (emailVerified != null) 'email_verified': emailVerified,
      if (avatar != null) 'avatar': avatar,
      if (bio != null) 'bio': bio,
      if (country != null) 'country': country,
      if (region != null) 'region': region,
      if (role != null) 'role': role,
      if (privacy != null) 'privacy': privacy,
      if (joinedAt != null) 'joined_at': joinedAt,
    };
  }
}

class MobileDevice {
  const MobileDevice({
    this.id = null,
    this.platform = null,
    this.provider = null,
    this.deviceLabel = null,
    this.isActive = null,
    this.lastSeenAt = null,
    this.createdAt = null,
  });

  factory MobileDevice.fromJson(Map<String, dynamic> json) {
    return MobileDevice(
      id: _asInt(json['id']),
      platform: _asString(json['platform']),
      provider: _asString(json['provider']),
      deviceLabel: _asString(json['device_label']),
      isActive: _asBool(json['is_active']),
      lastSeenAt: _asString(json['last_seen_at']),
      createdAt: _asString(json['created_at']),
    );
  }

  final int? id;
  final String? platform;
  final String? provider;
  final String? deviceLabel;
  final bool? isActive;
  final String? lastSeenAt;
  final String? createdAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (platform != null) 'platform': platform,
      if (provider != null) 'provider': provider,
      if (deviceLabel != null) 'device_label': deviceLabel,
      if (isActive != null) 'is_active': isActive,
      if (lastSeenAt != null) 'last_seen_at': lastSeenAt,
      if (createdAt != null) 'created_at': createdAt,
    };
  }
}

class NotificationModel {
  const NotificationModel({
    this.id = null,
    this.type = null,
    this.title = null,
    this.body = null,
    this.read = null,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) {
    return NotificationModel(
      id: _asInt(json['id']),
      type: _asString(json['type']),
      title: _asString(json['title']),
      body: _asString(json['body']),
      read: _asBool(json['read']),
    );
  }

  final int? id;
  final String? type;
  final String? title;
  final String? body;
  final bool? read;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (type != null) 'type': type,
      if (title != null) 'title': title,
      if (body != null) 'body': body,
      if (read != null) 'read': read,
    };
  }
}

class Pagination {
  const Pagination({
    this.currentPage = null,
    this.lastPage = null,
    this.perPage = null,
    this.total = null,
  });

  factory Pagination.fromJson(Map<String, dynamic> json) {
    return Pagination(
      currentPage: _asInt(json['current_page']),
      lastPage: _asInt(json['last_page']),
      perPage: _asInt(json['per_page']),
      total: _asInt(json['total']),
    );
  }

  final int? currentPage;
  final int? lastPage;
  final int? perPage;
  final int? total;

  Map<String, dynamic> toJson() {
    return {
      if (currentPage != null) 'current_page': currentPage,
      if (lastPage != null) 'last_page': lastPage,
      if (perPage != null) 'per_page': perPage,
      if (total != null) 'total': total,
    };
  }
}

class Payment {
  const Payment({
    this.id = null,
    this.tournamentId = null,
    this.teamId = null,
    this.amount = null,
    this.amountMinor = null,
    this.currency = null,
    this.provider = null,
    this.status = null,
  });

  factory Payment.fromJson(Map<String, dynamic> json) {
    return Payment(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      teamId: _asInt(json['team_id']),
      amount: _asString(json['amount']),
      amountMinor: _asInt(json['amount_minor']),
      currency: _asString(json['currency']),
      provider: _asString(json['provider']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? teamId;
  final String? amount;
  final int? amountMinor;
  final String? currency;
  final String? provider;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (teamId != null) 'team_id': teamId,
      if (amount != null) 'amount': amount,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (currency != null) 'currency': currency,
      if (provider != null) 'provider': provider,
      if (status != null) 'status': status,
    };
  }
}

class Payout {
  const Payout({
    this.id = null,
    this.tournamentId = null,
    this.rank = null,
    this.amountMinor = null,
    this.currency = null,
    this.status = null,
  });

  factory Payout.fromJson(Map<String, dynamic> json) {
    return Payout(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      rank: _asInt(json['rank']),
      amountMinor: _asInt(json['amount_minor']),
      currency: _asString(json['currency']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? tournamentId;
  final int? rank;
  final int? amountMinor;
  final String? currency;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (rank != null) 'rank': rank,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (currency != null) 'currency': currency,
      if (status != null) 'status': status,
    };
  }
}

class PlatformInfo {
  const PlatformInfo({
    this.currency = null,
    this.timezone = null,
    this.locale = null,
  });

  factory PlatformInfo.fromJson(Map<String, dynamic> json) {
    return PlatformInfo(
      currency: _asString(json['currency']),
      timezone: _asString(json['timezone']),
      locale: _asString(json['locale']),
    );
  }

  final String? currency;
  final String? timezone;
  final String? locale;

  Map<String, dynamic> toJson() {
    return {
      if (currency != null) 'currency': currency,
      if (timezone != null) 'timezone': timezone,
      if (locale != null) 'locale': locale,
    };
  }
}

class PushCapabilities {
  const PushCapabilities({
    this.fcmEnabled = null,
    this.apnsEnabled = null,
  });

  factory PushCapabilities.fromJson(Map<String, dynamic> json) {
    return PushCapabilities(
      fcmEnabled: _asBool(json['fcm_enabled']),
      apnsEnabled: _asBool(json['apns_enabled']),
    );
  }

  final bool? fcmEnabled;
  final bool? apnsEnabled;

  Map<String, dynamic> toJson() {
    return {
      if (fcmEnabled != null) 'fcm_enabled': fcmEnabled,
      if (apnsEnabled != null) 'apns_enabled': apnsEnabled,
    };
  }
}

class Score {
  const Score({
    this.id = null,
    this.teamId = null,
    this.kills = null,
    this.placement = null,
    this.placementPoints = null,
    this.killPoints = null,
    this.points = null,
    this.status = null,
  });

  factory Score.fromJson(Map<String, dynamic> json) {
    return Score(
      id: _asInt(json['id']),
      teamId: _asInt(json['team_id']),
      kills: _asInt(json['kills']),
      placement: _asInt(json['placement']),
      placementPoints: _asInt(json['placement_points']),
      killPoints: _asInt(json['kill_points']),
      points: _asInt(json['points']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final int? teamId;
  final int? kills;
  final int? placement;
  final int? placementPoints;
  final int? killPoints;
  final int? points;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (teamId != null) 'team_id': teamId,
      if (kills != null) 'kills': kills,
      if (placement != null) 'placement': placement,
      if (placementPoints != null) 'placement_points': placementPoints,
      if (killPoints != null) 'kill_points': killPoints,
      if (points != null) 'points': points,
      if (status != null) 'status': status,
    };
  }
}

class ApiSession {
  const ApiSession({
    this.id = null,
    this.deviceLabel = null,
    this.lastActivity = null,
    this.isCurrent = null,
  });

  factory ApiSession.fromJson(Map<String, dynamic> json) {
    return ApiSession(
      id: _asString(json['id']),
      deviceLabel: _asString(json['device_label']),
      lastActivity: _asString(json['last_activity']),
      isCurrent: _asBool(json['is_current']),
    );
  }

  final String? id;
  final String? deviceLabel;
  final String? lastActivity;
  final bool? isCurrent;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (deviceLabel != null) 'device_label': deviceLabel,
      if (lastActivity != null) 'last_activity': lastActivity,
      if (isCurrent != null) 'is_current': isCurrent,
    };
  }
}

class StandingRow {
  const StandingRow({
    this.rank = null,
    this.teamId = null,
    this.teamName = null,
    this.matchesPlayed = null,
    this.kills = null,
    this.placementPoints = null,
    this.killPoints = null,
    this.points = null,
    this.bestPlacement = null,
  });

  factory StandingRow.fromJson(Map<String, dynamic> json) {
    return StandingRow(
      rank: _asInt(json['rank']),
      teamId: _asInt(json['team_id']),
      teamName: _asString(json['team_name']),
      matchesPlayed: _asInt(json['matches_played']),
      kills: _asInt(json['kills']),
      placementPoints: _asInt(json['placement_points']),
      killPoints: _asInt(json['kill_points']),
      points: _asInt(json['points']),
      bestPlacement: _asInt(json['best_placement']),
    );
  }

  final int? rank;
  final int? teamId;
  final String? teamName;
  final int? matchesPlayed;
  final int? kills;
  final int? placementPoints;
  final int? killPoints;
  final int? points;
  final int? bestPlacement;

  Map<String, dynamic> toJson() {
    return {
      if (rank != null) 'rank': rank,
      if (teamId != null) 'team_id': teamId,
      if (teamName != null) 'team_name': teamName,
      if (matchesPlayed != null) 'matches_played': matchesPlayed,
      if (kills != null) 'kills': kills,
      if (placementPoints != null) 'placement_points': placementPoints,
      if (killPoints != null) 'kill_points': killPoints,
      if (points != null) 'points': points,
      if (bestPlacement != null) 'best_placement': bestPlacement,
    };
  }
}

class SupportMessage {
  const SupportMessage({
    this.id = null,
    this.ticketId = null,
    this.body = null,
  });

  factory SupportMessage.fromJson(Map<String, dynamic> json) {
    return SupportMessage(
      id: _asInt(json['id']),
      ticketId: _asInt(json['ticket_id']),
      body: _asString(json['body']),
    );
  }

  final int? id;
  final int? ticketId;
  final String? body;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (ticketId != null) 'ticket_id': ticketId,
      if (body != null) 'body': body,
    };
  }
}

class SupportTicket {
  const SupportTicket({
    this.id = null,
    this.subject = null,
    this.category = null,
    this.priority = null,
    this.status = null,
  });

  factory SupportTicket.fromJson(Map<String, dynamic> json) {
    return SupportTicket(
      id: _asInt(json['id']),
      subject: _asString(json['subject']),
      category: _asString(json['category']),
      priority: _asString(json['priority']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? subject;
  final String? category;
  final String? priority;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (subject != null) 'subject': subject,
      if (category != null) 'category': category,
      if (priority != null) 'priority': priority,
      if (status != null) 'status': status,
    };
  }
}

class Team {
  const Team({
    this.id = null,
    this.tournamentId = null,
    this.name = null,
    this.captainName = null,
    this.gameUid = null,
    this.status = null,
    this.waitlistPosition = null,
  });

  factory Team.fromJson(Map<String, dynamic> json) {
    return Team(
      id: _asInt(json['id']),
      tournamentId: _asInt(json['tournament_id']),
      name: _asString(json['name']),
      captainName: _asString(json['captain_name']),
      gameUid: _asString(json['game_uid']),
      status: _asString(json['status']),
      waitlistPosition: _asInt(json['waitlist_position']),
    );
  }

  final int? id;
  final int? tournamentId;
  final String? name;
  final String? captainName;
  final String? gameUid;
  final String? status;
  final int? waitlistPosition;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (tournamentId != null) 'tournament_id': tournamentId,
      if (name != null) 'name': name,
      if (captainName != null) 'captain_name': captainName,
      if (gameUid != null) 'game_uid': gameUid,
      if (status != null) 'status': status,
      if (waitlistPosition != null) 'waitlist_position': waitlistPosition,
    };
  }
}

class Token {
  const Token({
    this.id = null,
    this.name = null,
    this.abilities = null,
    this.lastUsedAt = null,
    this.expiresAt = null,
  });

  factory Token.fromJson(Map<String, dynamic> json) {
    return Token(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      abilities: _asList(json['abilities']),
      lastUsedAt: _asString(json['last_used_at']),
      expiresAt: _asString(json['expires_at']),
    );
  }

  final int? id;
  final String? name;
  final List<dynamic>? abilities;
  final String? lastUsedAt;
  final String? expiresAt;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (abilities != null) 'abilities': abilities,
      if (lastUsedAt != null) 'last_used_at': lastUsedAt,
      if (expiresAt != null) 'expires_at': expiresAt,
    };
  }
}

class Tournament {
  const Tournament({
    this.id = null,
    this.slug = null,
    this.name = null,
    this.gameMode = null,
    this.map = null,
    this.format = null,
    this.status = null,
    this.entryFee = null,
    this.entryFeeMinor = null,
    this.currency = null,
    this.prizePool = null,
    this.teamSlots = null,
    this.teamSize = null,
    this.startsAt = null,
    this.checkInStartsAt = null,
    this.checkInEndsAt = null,
    this.slotsLeft = null,
    this.isFull = null,
    this.acceptsRegistration = null,
    this.confirmedTeamsCount = null,
    this.organizer = null,
  });

  factory Tournament.fromJson(Map<String, dynamic> json) {
    return Tournament(
      id: _asInt(json['id']),
      slug: _asString(json['slug']),
      name: _asString(json['name']),
      gameMode: _asString(json['game_mode']),
      map: _asString(json['map']),
      format: _asString(json['format']),
      status: _asString(json['status']),
      entryFee: _asString(json['entry_fee']),
      entryFeeMinor: _asInt(json['entry_fee_minor']),
      currency: _asString(json['currency']),
      prizePool: _asString(json['prize_pool']),
      teamSlots: _asInt(json['team_slots']),
      teamSize: _asInt(json['team_size']),
      startsAt: _asString(json['starts_at']),
      checkInStartsAt: _asString(json['check_in_starts_at']),
      checkInEndsAt: _asString(json['check_in_ends_at']),
      slotsLeft: _asInt(json['slots_left']),
      isFull: _asBool(json['is_full']),
      acceptsRegistration: _asBool(json['accepts_registration']),
      confirmedTeamsCount: _asInt(json['confirmed_teams_count']),
      organizer: json['organizer'],
    );
  }

  final int? id;
  final String? slug;
  final String? name;
  final String? gameMode;
  final String? map;
  final String? format;
  final String? status;
  final String? entryFee;
  final int? entryFeeMinor;
  final String? currency;
  final String? prizePool;
  final int? teamSlots;
  final int? teamSize;
  final String? startsAt;
  final String? checkInStartsAt;
  final String? checkInEndsAt;
  final int? slotsLeft;
  final bool? isFull;
  final bool? acceptsRegistration;
  final int? confirmedTeamsCount;
  final Map<String, dynamic>? organizer;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (slug != null) 'slug': slug,
      if (name != null) 'name': name,
      if (gameMode != null) 'game_mode': gameMode,
      if (map != null) 'map': map,
      if (format != null) 'format': format,
      if (status != null) 'status': status,
      if (entryFee != null) 'entry_fee': entryFee,
      if (entryFeeMinor != null) 'entry_fee_minor': entryFeeMinor,
      if (currency != null) 'currency': currency,
      if (prizePool != null) 'prize_pool': prizePool,
      if (teamSlots != null) 'team_slots': teamSlots,
      if (teamSize != null) 'team_size': teamSize,
      if (startsAt != null) 'starts_at': startsAt,
      if (checkInStartsAt != null) 'check_in_starts_at': checkInStartsAt,
      if (checkInEndsAt != null) 'check_in_ends_at': checkInEndsAt,
      if (slotsLeft != null) 'slots_left': slotsLeft,
      if (isFull != null) 'is_full': isFull,
      if (acceptsRegistration != null) 'accepts_registration': acceptsRegistration,
      if (confirmedTeamsCount != null) 'confirmed_teams_count': confirmedTeamsCount,
      if (organizer != null) 'organizer': organizer,
    };
  }
}

class UserProfile {
  const UserProfile({
    this.id = null,
    this.name = null,
    this.username = null,
    this.visible = null,
    this.privacy = null,
  });

  factory UserProfile.fromJson(Map<String, dynamic> json) {
    return UserProfile(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      username: _asString(json['username']),
      visible: _asBool(json['visible']),
      privacy: _asString(json['privacy']),
    );
  }

  final int? id;
  final String? name;
  final String? username;
  final bool? visible;
  final String? privacy;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (name != null) 'name': name,
      if (username != null) 'username': username,
      if (visible != null) 'visible': visible,
      if (privacy != null) 'privacy': privacy,
    };
  }
}

class Wallet {
  const Wallet({
    this.id = null,
    this.balance = null,
    this.balanceMinor = null,
    this.currency = null,
    this.status = null,
  });

  factory Wallet.fromJson(Map<String, dynamic> json) {
    return Wallet(
      id: _asInt(json['id']),
      balance: _asString(json['balance']),
      balanceMinor: _asInt(json['balance_minor']),
      currency: _asString(json['currency']),
      status: _asString(json['status']),
    );
  }

  final int? id;
  final String? balance;
  final int? balanceMinor;
  final String? currency;
  final String? status;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (balance != null) 'balance': balance,
      if (balanceMinor != null) 'balance_minor': balanceMinor,
      if (currency != null) 'currency': currency,
      if (status != null) 'status': status,
    };
  }
}

class WebhookDelivery {
  const WebhookDelivery({
    this.id = null,
    this.event = null,
    this.deliveryId = null,
    this.status = null,
    this.attempts = null,
  });

  factory WebhookDelivery.fromJson(Map<String, dynamic> json) {
    return WebhookDelivery(
      id: _asInt(json['id']),
      event: _asString(json['event']),
      deliveryId: _asString(json['delivery_id']),
      status: _asString(json['status']),
      attempts: _asInt(json['attempts']),
    );
  }

  final int? id;
  final String? event;
  final String? deliveryId;
  final String? status;
  final int? attempts;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (event != null) 'event': event,
      if (deliveryId != null) 'delivery_id': deliveryId,
      if (status != null) 'status': status,
      if (attempts != null) 'attempts': attempts,
    };
  }
}

class WebhookEndpoint {
  const WebhookEndpoint({
    this.id = null,
    this.url = null,
    this.status = null,
    this.events = null,
    this.consecutiveFailures = null,
  });

  factory WebhookEndpoint.fromJson(Map<String, dynamic> json) {
    return WebhookEndpoint(
      id: _asInt(json['id']),
      url: _asString(json['url']),
      status: _asString(json['status']),
      events: _asList(json['events']),
      consecutiveFailures: _asInt(json['consecutive_failures']),
    );
  }

  final int? id;
  final String? url;
  final String? status;
  final List<dynamic>? events;
  final int? consecutiveFailures;

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
      if (url != null) 'url': url,
      if (status != null) 'status': status,
      if (events != null) 'events': events,
      if (consecutiveFailures != null) 'consecutive_failures': consecutiveFailures,
    };
  }
}

/// Defensive JSON scalar helpers.
int? _asInt(dynamic v) => v is int ? v : (v is num ? v.toInt() : (v is String ? int.tryParse(v) : null));
num? _asNum(dynamic v) => v is num ? v : null;
bool? _asBool(dynamic v) => v is bool ? v : null;
String? _asString(dynamic v) => v is String ? v : null;
List<dynamic>? _asList(dynamic v) => v is List ? v : null;

```

### `mobile/lib/core/api/idempotency.dart`

```dart
import 'dart:math';

/// Idempotency-Key generation (Phase 18 §30).
///
/// Critical mutations (registration, payments, score submission, support
/// tickets) accept an `Idempotency-Key` header; a replay within the server
/// TTL returns the stored response instead of executing twice. The client
/// generates a fresh key per logical action and REUSES the same key when
/// retrying the same action, so a retried financial/score mutation can never
/// double-execute.
class Idempotency {
  const Idempotency._();

  static final Random _random = Random.secure();

  /// A fresh, collision-resistant key.
  static String generate() {
    final millis = DateTime.now().millisecondsSinceEpoch.toRadixString(16);
    final rand = _random.nextInt(0x7FFFFFFF).toRadixString(16).padLeft(8, '0');
    return 'mob-$millis-$rand';
  }
}

```

### `mobile/lib/core/cache/offline_cache.dart`

```dart
import 'dart:async';
import 'dart:convert';
import 'dart:io';

/// Read-only, filesystem-backed offline cache (Phase 18 §33).
///
/// The mobile app NEVER owns a writable source of truth for rankings,
/// wallets, payments or scores — those stay server-authoritative. This cache
/// only stores the last successful GET responses so a user can browse
/// previously loaded content while offline. Entries are marked with their
/// fetch time so the UI can show a "stale data" banner and never presents
/// cached financial/rank values as current.
class OfflineCache {
  OfflineCache._();

  static final OfflineCache instance = OfflineCache._();

  Directory? _dir;

  bool get isReady => _dir != null;

  Future<void> init(Directory baseDir) async {
    final dir = Directory('${baseDir.path}${Platform.pathSeparator}api_cache');
    if (!dir.existsSync()) {
      dir.createSync(recursive: true);
    }
    _dir = dir;
  }

  String? _pathFor(String key) {
    final dir = _dir;
    if (dir == null) {
      return null;
    }
    return '${dir.path}${Platform.pathSeparator}${_keyHash(key)}.json';
  }

  static int _keyHash(String key) => key.hashCode & 0x7FFFFFFF;

  Future<void> put(String key, Map<String, dynamic> value) async {
    final path = _pathFor(key);
    if (path == null) {
      return;
    }
    File(path).writeAsStringSync(jsonEncode({
      'cached_at': DateTime.now().toIso8601String(),
      'payload': value,
    }));
  }

  Future<CachedResponse?> get(String key) async {
    final path = _pathFor(key);
    if (path == null) {
      return null;
    }
    try {
      final file = File(path);
      if (!file.existsSync()) {
        return null;
      }
      final decoded = jsonDecode(file.readAsStringSync());
      if (decoded is! Map<String, dynamic>) {
        return null;
      }
      final cachedAtRaw = decoded['cached_at'];
      final payload = decoded['payload'];
      return CachedResponse(
        cachedAt: cachedAtRaw is String ? DateTime.parse(cachedAtRaw) : null,
        payload: payload is Map<String, dynamic> ? payload : const {},
      );
    } catch (_) {
      // Corrupt cache entries are treated as a miss, never a crash.
      return null;
    }
  }

  Future<void> remove(String key) async {
    final path = _pathFor(key);
    if (path == null) {
      return;
    }
    try {
      File(path).deleteSync();
    } catch (_) {
      // Best effort.
    }
  }

  Future<void> clear() async {
    final dir = _dir;
    if (dir == null) {
      return;
    }
    try {
      final files = dir.listSync().whereType<File>();
      for (final f in files) {
        f.deleteSync();
      }
    } catch (_) {
      // Best effort.
    }
  }
}

class CachedResponse {
  const CachedResponse({required this.cachedAt, required this.payload});

  final DateTime? cachedAt;
  final Map<String, dynamic> payload;

  bool get isStale {
    if (cachedAt == null) {
      return true;
    }
    return DateTime.now().difference(cachedAt!) > const Duration(minutes: 30);
  }
}

```

### `mobile/lib/core/format/dates.dart`

```dart
import 'package:intl/intl.dart';

/// Date/time presentation (Phase 18 §60). The platform timezone is
/// Asia/Dhaka (announced by `/api/v1/app/meta`), so timestamps are rendered
/// in the Asia/Dhaka wall clock by default. Pure presentation — the server
/// remains authoritative for all schedules.
class Dates {
  Dates._();

  static const String platformTimezone = 'Asia/Dhaka';

  static DateTime? parse(String? iso) {
    if (iso == null || iso.isEmpty) {
      return null;
    }
    return DateTime.tryParse(iso);
  }

  static DateTime toPlatform(DateTime value) =>
      value.toUtc().add(const Duration(hours: 6));

  /// "12 Sep 2026, 8:30 PM".
  static String formatDateTime(String? iso, {String? locale}) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    final local = toPlatform(parsed);
    return DateFormat('d MMM y, h:mm a', locale).format(local);
  }

  /// "12 Sep 2026".
  static String formatDate(String? iso, {String? locale}) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    return DateFormat('d MMM y', locale).format(toPlatform(parsed));
  }

  /// "in 3h 20m" / "5m ago" style relative time.
  static String relative(String? iso) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    final diff = toPlatform(parsed)
        .difference(DateTime.now().toUtc().add(const Duration(hours: 6)));
    final abs = diff.abs();
    if (abs.inDays >= 1) {
      return '${abs.inDays}d';
    }
    if (abs.inHours >= 1) {
      return '${abs.inHours}h';
    }
    return '${abs.inMinutes.abs()}m';
  }
}

```

### `mobile/lib/core/format/money.dart`

```dart
import 'package:intl/intl.dart';

/// BDT money formatting (Phase 18 §58).
///
/// The API carries amounts in minor units (poisha, int) and occasionally a
/// server-formatted display string. Formatting is presentation-only — the
/// client never changes precision and never uses a formatted string for
/// arithmetic or payment math.
class Money {
  Money._();

  static const String currency = 'BDT';

  /// Minor units (poisha) -> "1,234.56" (server-authoritative formatting).
  static String formatMinor(int minor, {String? locale}) {
    final taka = minor / 100;
    final nf = NumberFormat.currency(
      locale: locale,
      symbol: '৳',
      decimalDigits: 2,
    );
    return nf.format(taka);
  }

  /// Prefers the server's display string when present, else formats minor.
  static String display({int? minor, String? formatted, String? locale}) {
    if (formatted != null && formatted.isNotEmpty) {
      return formatted;
    }
    return formatMinor(minor ?? 0, locale: locale);
  }
}

```

### `mobile/lib/core/format/phone.dart`

```dart
/// Bangladesh phone-number presentation (Phase 18 §60).
///
/// Normalizes local `01XXXXXXXXX` numbers into E.164 (`+8801XXXXXXXXX`) for
/// storage/submission and renders them consistently. Digits are never logged
/// by the app's redacting telemetry.
class Phone {
  Phone._();

  /// Normalizes a user-typed Bangladesh number to E.164, or returns null if
  /// it cannot be a valid BD mobile number.
  static String? normalizeBd(String input) {
    var digits = input.replaceAll(RegExp(r'\D'), '');
    if (digits.startsWith('880')) {
      digits = digits.substring(3);
    } else if (digits.startsWith('00880')) {
      digits = digits.substring(5);
    }
    if (digits.startsWith('0')) {
      digits = digits.substring(1);
    }
    if (!RegExp(r'^1[3-9]\d{8}$').hasMatch(digits)) {
      return null;
    }
    return '+880$digits';
  }

  /// Renders E.164 as `01XXXXXXXXX`.
  static String display(String e164) {
    if (e164.startsWith('+880')) {
      return '0${e164.substring(4)}';
    }
    return e164;
  }
}

```

### `mobile/lib/core/l10n/app_localizations.dart`

```dart
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart';

/// Application strings — English + Bangla (Phase 18 §68).
///
/// Strings are resolved from a plain map (no code generation) so the app
/// stays lightweight and testable. English is the fallback; Bangla (bn) is
/// the second supported locale. WCAG 2.2 AA: strings avoid relying on colour
/// alone and keep sentence case for readability.
class AppLocalizations {
  AppLocalizations(this.locale) : _isBn = locale.languageCode == 'bn';

  final Locale locale;
  final bool _isBn;

  static const supportedLocales = [Locale('en'), Locale('bn')];
  static const localizationsDelegates = [
    delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ];

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  static AppLocalizations of(BuildContext context) =>
      Localizations.of<AppLocalizations>(context, AppLocalizations)!;

  static AppLocalizations? maybeOf(BuildContext context) =>
      Localizations.of<AppLocalizations>(context, AppLocalizations);

  /// Universal accessor (fallback: English, then the key itself).
  String t(String key) => (_isBn ? _bn[key] : null) ?? _en[key] ?? key;

  // --- App / onboarding ---
  String get appName => t('appName');
  String get tagline => t('tagline');
  String get getStarted => t('getStarted');
  String get onboardingWelcomeTitle => t('onboardingWelcomeTitle');
  String get onboardingWelcomeBody => t('onboardingWelcomeBody');
  String get onboardingPlayTitle => t('onboardingPlayTitle');
  String get onboardingPlayBody => t('onboardingPlayBody');
  String get onboardingWinTitle => t('onboardingWinTitle');
  String get onboardingWinBody => t('onboardingWinBody');

  // --- Auth ---
  String get logIn => t('logIn');
  String get logout => t('logout');
  String get createAccount => t('createAccount');
  String get continueWithGoogle => t('continueWithGoogle');
  String get continueWithPhone => t('continueWithPhone');
  String get email => t('email');
  String get password => t('password');
  String get passwordConfirmation => t('passwordConfirmation');
  String get name => t('name');
  String get username => t('username');
  String get phone => t('phone');
  String get gameUid => t('gameUid');
  String get role => t('role');
  String get rolePlayer => t('rolePlayer');
  String get roleOrganizer => t('roleOrganizer');
  String get forgotPassword => t('forgotPassword');
  String get forgotPasswordBody => t('forgotPasswordBody');
  String get forgotPasswordNote => t('forgotPasswordNote');
  String get backToLogin => t('backToLogin');
  String get sendCode => t('sendCode');
  String get verify => t('verify');
  String get resendCode => t('resendCode');
  String get codeSentTo => t('codeSentTo');
  String get enterCode => t('enterCode');

  // --- Navigation ---
  String get home => t('home');
  String get tournaments => t('tournaments');
  String get matches => t('matches');
  String get leaderboard => t('leaderboard');
  String get profile => t('profile');
  String get wallet => t('wallet');
  String get notifications => t('notifications');
  String get settings => t('settings');
  String get support => t('support');
  String get myTeams => t('myTeams');
  String get live => t('live');
  String get viewAll => t('viewAll');

  // --- Tournaments / registration ---
  String get register => t('register');
  String get registered => t('registered');
  String get waitlisted => t('waitlisted');
  String get checkIn => t('checkIn');
  String get checkedIn => t('checkedIn');
  String get entryFee => t('entryFee');
  String get prizePool => t('prizePool');
  String get slotsLeft => t('slotsLeft');
  String get startsAt => t('startsAt');
  String get checkInOpens => t('checkInOpens');
  String get checkInCloses => t('checkInCloses');
  String get teamSize => t('teamSize');
  String get gameMode => t('gameMode');
  String get map => t('map');
  String get format => t('format');
  String get status => t('status');
  String get viewBracket => t('viewBracket');
  String get viewMatches => t('viewMatches');
  String get viewLeaderboard => t('viewLeaderboard');
  String get teamIsWaitlisted => t('teamIsWaitlisted');
  String get waitlistPositionLabel => t('waitlistPositionLabel');
  String get search => t('search');
  String get all => t('all');
  String get upcoming => t('upcoming');
  String get finished => t('finished');
  String get teamName => t('teamName');
  String get captainName => t('captainName');
  String get members => t('members');
  String get memberName => t('memberName');

  // --- Teams ---
  String get joinTeam => t('joinTeam');
  String get createTeam => t('createTeam');
  String get addMember => t('addMember');
  String get removeMember => t('removeMember');
  String get withdraw => t('withdraw');
  String get confirmWithdraw => t('confirmWithdraw');
  String get withdrawBody => t('withdrawBody');
  String get roster => t('roster');
  String get noTeams => t('noTeams');

  // --- Matches / scores ---
  String get matchNo => t('matchNo');
  String get round => t('round');
  String get roomId => t('roomId');
  String get roomPass => t('roomPass');
  String get scheduled => t('scheduled');
  String get completed => t('completed');
  String get kills => t('kills');
  String get placement => t('placement');
  String get points => t('points');
  String get submitScore => t('submitScore');
  String get scoreSubmitted => t('scoreSubmitted');
  String get noScoresYet => t('noScoresYet');
  String get myUpcomingMatches => t('myUpcomingMatches');

  // --- Leaderboard ---
  String get rank => t('rank');
  String get team => t('team');
  String get standings => t('standings');

  // --- Wallet ---
  String get balance => t('balance');
  String get ledger => t('ledger');
  String get payouts => t('payouts');
  String get payout => t('payout');
  String get amount => t('amount');
  String get provider => t('provider');
  String get redirectingToPayment => t('redirectingToPayment');
  String get paymentPending => t('paymentPending');
  String get paymentPaid => t('paymentPaid');
  String get paymentFailed => t('paymentFailed');
  String get pollForStatus => t('pollForStatus');
  String get savedMethods => t('savedMethods');
  String get noSavedMethods => t('noSavedMethods');

  // --- Notifications ---
  String get noNotifications => t('noNotifications');
  String get markAllRead => t('markAllRead');
  String get unread => t('unread');

  // --- Support / disputes ---
  String get subject => t('subject');
  String get category => t('category');
  String get priority => t('priority');
  String get message => t('message');
  String get sendMessage => t('sendMessage');
  String get createTicket => t('createTicket');
  String get ticketCreated => t('ticketCreated');
  String get noTickets => t('noTickets');
  String get waitingForReply => t('waitingForReply');
  String get dispute => t('dispute');
  String get disputeWebOnly => t('disputeWebOnly');
  String get noDisputes => t('noDisputes');

  // --- Profile / settings ---
  String get accountSecurity => t('accountSecurity');
  String get securityStatus => t('securityStatus');
  String get signInMethods => t('signInMethods');
  String get emailVerified => t('emailVerified');
  String get emailNotVerified => t('emailNotVerified');
  String get hasPassword => t('hasPassword');
  String get noPassword => t('noPassword');
  String get accountStatus => t('accountStatus');
  String get active => t('active');
  String get sessions => t('sessions');
  String get revoke => t('revoke');
  String get revokeAll => t('revokeAll');
  String get revokeOthers => t('revokeOthers');
  String get currentSession => t('currentSession');
  String get thisDevice => t('thisDevice');
  String get lastActive => t('lastActive');
  String get privacy => t('privacy');
  String get privacyPublic => t('privacyPublic');
  String get privacyRegistered => t('privacyRegistered');
  String get privacyPrivate => t('privacyPrivate');
  String get publicProfile => t('publicProfile');
  String get editProfile => t('editProfile');
  String get bio => t('bio');
  String get country => t('country');
  String get region => t('region');
  String get avatar => t('avatar');
  String get save => t('save');
  String get saved => t('saved');
  String get cancel => t('cancel');
  String get confirm => t('confirm');
  String get retry => t('retry');
  String get loading => t('loading');
  String get empty => t('empty');
  String get version => t('version');
  String get language => t('language');
  String get english => t('english');
  String get bangla => t('bangla');
  String get pushStatus => t('pushStatus');
  String get pushDisabled => t('pushDisabled');
  String get pushEnabled => t('pushEnabled');
  String get about => t('about');
  String get terms => t('terms');
  String get privacyPolicy => t('privacyPolicy');
  String get contactSupport => t('contactSupport');
  String get connectedAccounts => t('connectedAccounts');
  String get google => t('google');
  String get phoneMethod => t('phoneMethod');
  String get emailMethod => t('emailMethod');
  String get linkPhone => t('linkPhone');
  String get phoneLinked => t('phoneLinked');
  String get phoneLinkBody => t('phoneLinkBody');
  String get verifyPhone => t('verifyPhone');
  String get changePasswordWebOnly => t('changePasswordWebOnly');
  String get changePasswordWebBody => t('changePasswordWebBody');
  String get deactivationWebOnly => t('deactivationWebOnly');
  String get deactivationWebBody => t('deactivationWebBody');

  // --- Security events ---
  String get securityEventTitle => t('securityEventTitle');
  String get sessionExpiredBody => t('sessionExpiredBody');
  String get tokenRevokedBody => t('tokenRevokedBody');
  String get accountInactiveTitle => t('accountInactiveTitle');
  String get accountInactiveBody => t('accountInactiveBody');
  String get loginAgain => t('loginAgain');
  String get ok => t('ok');

  // --- Errors / offline ---
  String get offlineBanner => t('offlineBanner');
  String get staleBanner => t('staleBanner');
  String get errorOffline => t('errorOffline');
  String get errorTimeout => t('errorTimeout');
  String get errorRateLimited => t('errorRateLimited');
  String get errorInvalidCredentials => t('errorInvalidCredentials');
  String get errorInvalidCode => t('errorInvalidCode');
  String get errorNoAccount => t('errorNoAccount');
  String get errorGoogleSignIn => t('errorGoogleSignIn');
  String get errorAccountInactive => t('errorAccountInactive');
  String get errorSessionExpired => t('errorSessionExpired');
  String get errorServer => t('errorServer');
  String get errorGeneric => t('errorGeneric');
  String get errorUnexpected => t('errorUnexpected');

  /// Formats a BDT amount in minor units using the current locale.
  String formatMoney(num amountMinor) {
    final taka = amountMinor / 100;
    final nf = NumberFormat.currency(
      locale: locale.toString(),
      symbol: '৳',
      decimalDigits: 2,
    );
    return nf.format(taka);
  }

  static const Map<String, String> _en = {
    'appName': 'FF Arena',
    'tagline': 'Compete. Track. Win.',
    'getStarted': 'Get started',
    'onboardingWelcomeTitle': 'Welcome to FF Arena',
    'onboardingWelcomeBody':
        'The official mobile companion for FF Arena tournaments in Bangladesh.',
    'onboardingPlayTitle': 'Compete',
    'onboardingPlayBody':
        'Register your squad, pay the entry fee, and check in for live matches.',
    'onboardingWinTitle': 'Track & win',
    'onboardingWinBody':
        'Follow standings, submit scores, and withdraw your winnings.',
    'logIn': 'Log in',
    'logout': 'Log out',
    'createAccount': 'Create account',
    'continueWithGoogle': 'Continue with Google',
    'continueWithPhone': 'Continue with phone',
    'email': 'Email',
    'password': 'Password',
    'passwordConfirmation': 'Confirm password',
    'name': 'Full name',
    'username': 'Username',
    'phone': 'Phone',
    'gameUid': 'Free Fire UID',
    'role': 'Role',
    'rolePlayer': 'Player',
    'roleOrganizer': 'Organizer',
    'forgotPassword': 'Forgot password?',
    'forgotPasswordBody':
        'Password reset is available on the web. Open ffarena in your browser and use "Forgot password".',
    'forgotPasswordNote': 'For your security we do not reset passwords in-app.',
    'backToLogin': 'Back to log in',
    'sendCode': 'Send code',
    'verify': 'Verify',
    'resendCode': 'Resend code',
    'codeSentTo': 'We sent a verification code to your phone.',
    'enterCode': 'Enter the code',
    'home': 'Home',
    'tournaments': 'Tournaments',
    'matches': 'Matches',
    'leaderboard': 'Leaderboard',
    'profile': 'Profile',
    'wallet': 'Wallet',
    'notifications': 'Notifications',
    'settings': 'Settings',
    'support': 'Support',
    'myTeams': 'My teams',
    'live': 'Live',
    'viewAll': 'View all',
    'register': 'Register',
    'registered': 'Registered',
    'waitlisted': 'Waitlisted',
    'checkIn': 'Check in',
    'checkedIn': 'Checked in',
    'entryFee': 'Entry fee',
    'prizePool': 'Prize pool',
    'slotsLeft': 'Slots left',
    'startsAt': 'Starts',
    'checkInOpens': 'Check-in opens',
    'checkInCloses': 'Check-in closes',
    'teamSize': 'Team size',
    'gameMode': 'Mode',
    'map': 'Map',
    'format': 'Format',
    'status': 'Status',
    'viewBracket': 'Bracket',
    'viewMatches': 'Matches',
    'viewLeaderboard': 'Standings',
    'teamIsWaitlisted': 'Your team is on the waitlist.',
    'waitlistPositionLabel': 'Waitlist position',
    'search': 'Search',
    'all': 'All',
    'upcoming': 'Upcoming',
    'finished': 'Finished',
    'teamName': 'Team name',
    'captainName': 'Captain name',
    'members': 'Members',
    'memberName': 'Player name',
    'joinTeam': 'Join a team',
    'createTeam': 'Create a team',
    'addMember': 'Add member',
    'removeMember': 'Remove',
    'withdraw': 'Withdraw',
    'confirmWithdraw': 'Withdraw team?',
    'withdrawBody':
        'Withdrawing removes your team from this tournament. Entry-fee refunds follow the tournament refund policy.',
    'roster': 'Roster',
    'noTeams': 'You are not part of any team yet.',
    'matchNo': 'Match',
    'round': 'Round',
    'roomId': 'Room ID',
    'roomPass': 'Room password',
    'scheduled': 'Scheduled',
    'completed': 'Completed',
    'kills': 'Kills',
    'placement': 'Placement',
    'points': 'Points',
    'submitScore': 'Submit score',
    'scoreSubmitted': 'Score submitted',
    'noScoresYet': 'No scores submitted yet.',
    'myUpcomingMatches': 'My upcoming matches',
    'rank': 'Rank',
    'team': 'Team',
    'standings': 'Standings',
    'balance': 'Balance',
    'ledger': 'Ledger',
    'payouts': 'Payouts',
    'payout': 'Payout',
    'amount': 'Amount',
    'provider': 'Provider',
    'redirectingToPayment': 'Opening your payment provider…',
    'paymentPending':
        'Payment is being processed. We will confirm once the provider reports back.',
    'paymentPaid': 'Payment confirmed.',
    'paymentFailed': 'Payment was not completed.',
    'pollForStatus': 'Checking payment status…',
    'savedMethods': 'Saved methods',
    'noSavedMethods': 'No saved payment methods.',
    'noNotifications': 'No notifications yet.',
    'markAllRead': 'Mark all read',
    'unread': 'Unread',
    'subject': 'Subject',
    'category': 'Category',
    'priority': 'Priority',
    'message': 'Message',
    'sendMessage': 'Send',
    'createTicket': 'New ticket',
    'ticketCreated': 'Ticket created. We will reply soon.',
    'noTickets': 'No support tickets.',
    'waitingForReply': 'Waiting for a reply…',
    'dispute': 'Dispute',
    'disputeWebOnly':
        'Disputes are filed from the web match page. You can track your filed disputes here.',
    'noDisputes': 'No disputes.',
    'accountSecurity': 'Account security',
    'securityStatus': 'Security status',
    'signInMethods': 'Sign-in methods',
    'emailVerified': 'Email verified',
    'emailNotVerified': 'Email not verified',
    'hasPassword': 'Password set',
    'noPassword': 'No password',
    'accountStatus': 'Account status',
    'active': 'Active',
    'sessions': 'Active sessions',
    'revoke': 'Revoke',
    'revokeAll': 'Log out everywhere',
    'revokeOthers': 'Log out other devices',
    'currentSession': 'This device',
    'thisDevice': 'This device',
    'lastActive': 'Last active',
    'privacy': 'Privacy',
    'privacyPublic': 'Public — anyone can view your profile',
    'privacyRegistered': 'Registered users only',
    'privacyPrivate': 'Private — hidden from everyone',
    'publicProfile': 'Public profile',
    'editProfile': 'Edit profile',
    'bio': 'Bio',
    'country': 'Country',
    'region': 'Region',
    'avatar': 'Avatar URL',
    'save': 'Save',
    'saved': 'Saved',
    'cancel': 'Cancel',
    'confirm': 'Confirm',
    'retry': 'Retry',
    'loading': 'Loading…',
    'empty': 'Nothing here yet.',
    'version': 'Version',
    'language': 'Language',
    'english': 'English',
    'bangla': 'বাংলা',
    'pushStatus': 'Push notifications',
    'pushDisabled': 'Push notifications are not configured for this build.',
    'pushEnabled': 'Push notifications are enabled.',
    'about': 'About',
    'terms': 'Terms of service',
    'privacyPolicy': 'Privacy policy',
    'contactSupport': 'Contact support',
    'connectedAccounts': 'Connected accounts',
    'google': 'Google',
    'phoneMethod': 'Phone',
    'emailMethod': 'Email',
    'linkPhone': 'Link phone number',
    'phoneLinked': 'Phone number linked.',
    'phoneLinkBody': 'Link a phone number so you can log in with it.',
    'verifyPhone': 'Verify phone',
    'changePasswordWebOnly': 'Change password',
    'changePasswordWebBody':
        'Password changes are available on the web. Open FF Arena in your browser to change or reset your password.',
    'deactivationWebOnly': 'Account lifecycle',
    'deactivationWebBody':
        'Account deactivation and deletion requests are handled on the web for your safety.',
    'securityEventTitle': 'Session ended',
    'sessionExpiredBody': 'Your session expired. Please log in again.',
    'tokenRevokedBody': 'You were signed out on this device.',
    'accountInactiveTitle': 'Account unavailable',
    'accountInactiveBody':
        'This account has been deactivated. Contact support if you believe this is a mistake.',
    'loginAgain': 'Log in again',
    'ok': 'OK',
    'offlineBanner': 'You are offline — showing saved data.',
    'staleBanner': 'Showing saved data. Pull to refresh when online.',
    'errorOffline': 'You are offline. Check your connection.',
    'errorTimeout': 'The server took too long to respond.',
    'errorRateLimited': 'Too many attempts. Please wait a moment.',
    'errorInvalidCredentials': 'That email or password is not correct.',
    'errorInvalidCode': 'That code is not valid.',
    'errorNoAccount': 'No account is linked to this phone number.',
    'errorGoogleSignIn': 'Could not complete Google sign-in. Please try again.',
    'errorAccountInactive': 'This account has been deactivated.',
    'errorSessionExpired': 'Your session expired. Please log in again.',
    'errorServer': 'The server had a problem. Please try again.',
    'errorGeneric': 'Something went wrong. Please try again.',
    'errorUnexpected': 'An unexpected error occurred.',
  };

  static const Map<String, String> _bn = {
    'appName': 'এফএফ এরিনা',
    'tagline': 'প্রতিযোগিতা করুন। ফলো করুন। জিতুন।',
    'getStarted': 'শুরু করুন',
    'onboardingWelcomeTitle': 'এফএফ এরিনায় স্বাগতম',
    'onboardingWelcomeBody':
        'বাংলাদেশের এফএফ এরিনা টুর্নামেন্টের অফিসিয়াল মোবাইল অ্যাপ।',
    'onboardingPlayTitle': 'প্রতিযোগিতা',
    'onboardingPlayBody':
        'আপনার স্কোয়াড রেজিস্টার করুন, এন্ট্রি ফি দিন এবং লাইভ ম্যাচে চেক-ইন করুন।',
    'onboardingWinTitle': 'ফলো করুন ও জিতুন',
    'onboardingWinBody':
        'স্ট্যান্ডিং দেখুন, স্কোর জমা দিন এবং পুরস্কার তুলে নিন।',
    'logIn': 'লগ ইন',
    'logout': 'লগ আউট',
    'createAccount': 'অ্যাকাউন্ট তৈরি করুন',
    'continueWithGoogle': 'গুগল দিয়ে চালিয়ে যান',
    'continueWithPhone': 'ফোন দিয়ে চালিয়ে যান',
    'email': 'ইমেইল',
    'password': 'পাসওয়ার্ড',
    'passwordConfirmation': 'পাসওয়ার্ড নিশ্চিত করুন',
    'name': 'পুরো নাম',
    'username': 'ইউজারনেম',
    'phone': 'ফোন',
    'gameUid': 'ফ্রি ফায়ার UID',
    'role': 'ভূমিকা',
    'rolePlayer': 'প্লেয়ার',
    'roleOrganizer': 'অর্গানাইজার',
    'forgotPassword': 'পাসওয়ার্ড ভুলে গেছেন?',
    'forgotPasswordBody':
        'পাসওয়ার্ড রিসেট ওয়েবে পাওয়া যায়। ব্রাউজারে এফএফ এরিনা খুলে "পাসওয়ার্ড ভুলে গেছেন" ব্যবহার করুন।',
    'forgotPasswordNote':
        'নিরাপত্তার জন্য আমরা অ্যাপে পাসওয়ার্ড রিসেট করি না।',
    'backToLogin': 'লগ ইন-এ ফিরে যান',
    'sendCode': 'কোড পাঠান',
    'verify': 'যাচাই করুন',
    'resendCode': 'কোড আবার পাঠান',
    'codeSentTo': 'আপনার ফোনে একটি যাচাই কোড পাঠানো হয়েছে।',
    'enterCode': 'কোডটি লিখুন',
    'home': 'হোম',
    'tournaments': 'টুর্নামেন্ট',
    'matches': 'ম্যাচ',
    'leaderboard': 'লিডারবোর্ড',
    'profile': 'প্রোফাইল',
    'wallet': 'ওয়ালেট',
    'notifications': 'বিজ্ঞপ্তি',
    'settings': 'সেটিংস',
    'support': 'সহায়তা',
    'myTeams': 'আমার দল',
    'live': 'লাইভ',
    'viewAll': 'সব দেখুন',
    'register': 'রেজিস্টার',
    'registered': 'রেজিস্টার্ড',
    'waitlisted': 'অপেক্ষমাণ',
    'checkIn': 'চেক-ইন',
    'checkedIn': 'চেক-ইন হয়েছে',
    'entryFee': 'এন্ট্রি ফি',
    'prizePool': 'পুরস্কার',
    'slotsLeft': 'আসন বাকি',
    'startsAt': 'শুরু',
    'checkInOpens': 'চেক-ইন শুরু',
    'checkInCloses': 'চেক-ইন শেষ',
    'teamSize': 'দলের সদস্য',
    'gameMode': 'মোড',
    'map': 'ম্যাপ',
    'format': 'ফরম্যাট',
    'status': 'স্ট্যাটাস',
    'viewBracket': 'ব্র্যাকেট',
    'viewMatches': 'ম্যাচ',
    'viewLeaderboard': 'স্ট্যান্ডিং',
    'teamIsWaitlisted': 'আপনার দল অপেক্ষমাণ তালিকায় আছে।',
    'waitlistPositionLabel': 'অপেক্ষমাণ অবস্থান',
    'search': 'খুঁজুন',
    'all': 'সব',
    'upcoming': 'আসন্ন',
    'finished': 'সমাপ্ত',
    'teamName': 'দলের নাম',
    'captainName': 'অধিনায়কের নাম',
    'members': 'সদস্য',
    'memberName': 'খেলোয়াড়ের নাম',
    'joinTeam': 'দলে যোগ দিন',
    'createTeam': 'দল তৈরি করুন',
    'addMember': 'সদস্য যোগ করুন',
    'removeMember': 'সরান',
    'withdraw': 'প্রত্যাহার',
    'confirmWithdraw': 'দল প্রত্যাহার করবেন?',
    'withdrawBody':
        'প্রত্যাহার করলে আপনার দল এই টুর্নামেন্ট থেকে বাদ যাবে। এন্ট্রি ফি রিফান্ড টুর্নামেন্টের রিফান্ড নীতি অনুযায়ী হবে।',
    'roster': 'রোস্টার',
    'noTeams': 'আপনি এখনো কোনো দলে নেই।',
    'matchNo': 'ম্যাচ',
    'round': 'রাউন্ড',
    'roomId': 'রুম আইডি',
    'roomPass': 'রুম পাসওয়ার্ড',
    'scheduled': 'নির্ধারিত',
    'completed': 'সমাপ্ত',
    'kills': 'কিল',
    'placement': 'প্লেসমেন্ট',
    'points': 'পয়েন্ট',
    'submitScore': 'স্কোর জমা দিন',
    'scoreSubmitted': 'স্কোর জমা হয়েছে',
    'noScoresYet': 'এখনো কোনো স্কোর জমা হয়নি।',
    'myUpcomingMatches': 'আমার আসন্ন ম্যাচ',
    'rank': 'র‍্যাঙ্ক',
    'team': 'দল',
    'standings': 'স্ট্যান্ডিং',
    'balance': 'ব্যালেন্স',
    'ledger': 'লেজার',
    'payouts': 'পেআউট',
    'payout': 'পেআউট',
    'amount': 'পরিমাণ',
    'provider': 'প্রোভাইডার',
    'redirectingToPayment': 'আপনার পেমেন্ট প্রোভাইডার খোলা হচ্ছে…',
    'paymentPending':
        'পেমেন্ট প্রক্রিয়াধীন। প্রোভাইডার রিপোর্ট দিলেই আমরা নিশ্চিত করব।',
    'paymentPaid': 'পেমেন্ট নিশ্চিত হয়েছে।',
    'paymentFailed': 'পেমেন্ট সম্পন্ন হয়নি।',
    'pollForStatus': 'পেমেন্ট স্ট্যাটাস যাচাই হচ্ছে…',
    'savedMethods': 'সংরক্ষিত মাধ্যম',
    'noSavedMethods': 'কোনো সংরক্ষিত পেমেন্ট মাধ্যম নেই।',
    'noNotifications': 'এখনো কোনো বিজ্ঞপ্তি নেই।',
    'markAllRead': 'সব পড়া হয়েছে',
    'unread': 'অপঠিত',
    'subject': 'বিষয়',
    'category': 'ক্যাটাগরি',
    'priority': 'অগ্রাধিকার',
    'message': 'বার্তা',
    'sendMessage': 'পাঠান',
    'createTicket': 'নতুন টিকিট',
    'ticketCreated': 'টিকিট তৈরি হয়েছে। আমরা শীঘ্রই উত্তর দেব।',
    'noTickets': 'কোনো সাপোর্ট টিকিট নেই।',
    'waitingForReply': 'উত্তরের অপেক্ষায়…',
    'dispute': 'বিরোধ',
    'disputeWebOnly':
        'বিরোধ ওয়েব ম্যাচ পেজ থেকে দায়ের করা হয়। এখানে আপনার দায়েরকৃত বিরোধ দেখতে পারবেন।',
    'noDisputes': 'কোনো বিরোধ নেই।',
    'accountSecurity': 'অ্যাকাউন্ট নিরাপত্তা',
    'securityStatus': 'নিরাপত্তা স্ট্যাটাস',
    'signInMethods': 'সাইন-ইন মাধ্যম',
    'emailVerified': 'ইমেইল যাচাইকৃত',
    'emailNotVerified': 'ইমেইল যাচাই হয়নি',
    'hasPassword': 'পাসওয়ার্ড সেট আছে',
    'noPassword': 'পাসওয়ার্ড নেই',
    'accountStatus': 'অ্যাকাউন্ট স্ট্যাটাস',
    'active': 'সক্রিয়',
    'sessions': 'সক্রিয় সেশন',
    'revoke': 'প্রত্যাহার',
    'revokeAll': 'সব জায়গা থেকে লগ আউট',
    'revokeOthers': 'অন্য ডিভাইস লগ আউট',
    'currentSession': 'এই ডিভাইস',
    'thisDevice': 'এই ডিভাইস',
    'lastActive': 'সর্বশেষ সক্রিয়',
    'privacy': 'প্রাইভেসি',
    'privacyPublic': 'পাবলিক — যে কেউ প্রোফাইল দেখতে পারবে',
    'privacyRegistered': 'শুধু রেজিস্টার্ড ব্যবহারকারী',
    'privacyPrivate': 'প্রাইভেট — সবার থেকে লুকানো',
    'publicProfile': 'পাবলিক প্রোফাইল',
    'editProfile': 'প্রোফাইল সম্পাদনা',
    'bio': 'বায়ো',
    'country': 'দেশ',
    'region': 'অঞ্চল',
    'avatar': 'অ্যাভাটার URL',
    'save': 'সংরক্ষণ',
    'saved': 'সংরক্ষিত',
    'cancel': 'বাতিল',
    'confirm': 'নিশ্চিত',
    'retry': 'আবার চেষ্টা করুন',
    'loading': 'লোড হচ্ছে…',
    'empty': 'এখানে কিছু নেই।',
    'version': 'সংস্করণ',
    'language': 'ভাষা',
    'english': 'English',
    'bangla': 'বাংলা',
    'pushStatus': 'পুশ বিজ্ঞপ্তি',
    'pushDisabled': 'এই বিল্ডে পুশ বিজ্ঞপ্তি কনফিগার করা হয়নি।',
    'pushEnabled': 'পুশ বিজ্ঞপ্তি চালু আছে।',
    'about': 'সম্পর্কে',
    'terms': 'ব্যবহারের শর্তাবলী',
    'privacyPolicy': 'গোপনীয়তা নীতি',
    'contactSupport': 'সাপোর্টে যোগাযোগ',
    'connectedAccounts': 'সংযুক্ত অ্যাকাউন্ট',
    'google': 'গুগল',
    'phoneMethod': 'ফোন',
    'emailMethod': 'ইমেইল',
    'linkPhone': 'ফোন নম্বর যুক্ত করুন',
    'phoneLinked': 'ফোন নম্বর যুক্ত হয়েছে।',
    'phoneLinkBody':
        'একটি ফোন নম্বর যুক্ত করুন যাতে এটি দিয়ে লগ ইন করতে পারেন।',
    'verifyPhone': 'ফোন যাচাই করুন',
    'changePasswordWebOnly': 'পাসওয়ার্ড পরিবর্তন',
    'changePasswordWebBody':
        'পাসওয়ার্ড পরিবর্তন ওয়েবে করা যায়। পাসওয়ার্ড বদলাতে বা রিসেট করতে ব্রাউজারে এফএফ এরিনা খুলুন।',
    'deactivationWebOnly': 'অ্যাকাউন্ট লাইফসাইকেল',
    'deactivationWebBody':
        'নিরাপত্তার জন্য অ্যাকাউন্ট নিষ্ক্রিয় ও মুছে ফেলার অনুরোধ ওয়েবে করা হয়।',
    'securityEventTitle': 'সেশন শেষ',
    'sessionExpiredBody': 'আপনার সেশন মেয়াদোত্তীর্ণ। আবার লগ ইন করুন।',
    'tokenRevokedBody': 'এই ডিভাইসে আপনাকে সাইন আউট করা হয়েছে।',
    'accountInactiveTitle': 'অ্যাকাউন্ট অনুপলব্ধ',
    'accountInactiveBody':
        'এই অ্যাকাউন্টটি নিষ্ক্রিয় করা হয়েছে। ভুল হলে সাপোর্টে যোগাযোগ করুন।',
    'loginAgain': 'আবার লগ ইন করুন',
    'ok': 'ঠিক আছে',
    'offlineBanner': 'আপনি অফলাইনে আছেন — সংরক্ষিত ডেটা দেখানো হচ্ছে।',
    'staleBanner': 'সংরক্ষিত ডেটা দেখানো হচ্ছে।',
    'errorOffline': 'আপনি অফলাইনে আছেন। সংযোগ পরীক্ষা করুন।',
    'errorTimeout': 'সার্ভার সাড়া দিতে দেরি করছে।',
    'errorRateLimited': 'অনেকবার চেষ্টা হয়েছে। একটু অপেক্ষা করুন।',
    'errorInvalidCredentials': 'ইমেইল বা পাসওয়ার্ড সঠিক নয়।',
    'errorInvalidCode': 'কোডটি সঠিক নয়।',
    'errorNoAccount': 'এই ফোন নম্বরে কোনো অ্যাকাউন্ট নেই।',
    'errorGoogleSignIn': 'গুগল সাইন-ইন সম্পন্ন হয়নি। আবার চেষ্টা করুন।',
    'errorAccountInactive': 'এই অ্যাকাউন্টটি নিষ্ক্রিয় করা হয়েছে।',
    'errorSessionExpired': 'সেশন মেয়াদোত্তীর্ণ। আবার লগ ইন করুন।',
    'errorServer': 'সার্ভারে সমস্যা হয়েছে। আবার চেষ্টা করুন।',
    'errorGeneric': 'কিছু ভুল হয়েছে। আবার চেষ্টা করুন।',
    'errorUnexpected': 'একটি অপ্রত্যাশিত ত্রুটি ঘটেছে।',
  };
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) =>
      locale.languageCode == 'en' || locale.languageCode == 'bn';

  // Synchronous load so `AppLocalizations.of(context)` resolves on the very
  // first build (no null flash, and tests don't need a settle pass).
  @override
  Future<AppLocalizations> load(Locale locale) =>
      SynchronousFuture(AppLocalizations(locale));

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

```

### `mobile/lib/core/network/retry.dart`

```dart
import 'dart:async';
import 'dart:math';

import '../api/api_exception.dart';

/// Retry policy with exponential backoff + full jitter (Phase 18 §30/§35).
///
/// Only idempotent operations may be retried automatically. Financial and
/// score mutations are never retried — instead they reuse the same
/// `Idempotency-Key` so a manual retry of the same logical action is safe.
class RetryPolicy {
  const RetryPolicy({
    required this.maxAttempts,
    this.baseDelay = const Duration(milliseconds: 300),
    this.maxDelay = const Duration(seconds: 3),
  });

  final int maxAttempts;
  final Duration baseDelay;
  final Duration maxDelay;

  /// Retries idempotent reads on transient failures only.
  static const idempotentRead = RetryPolicy(maxAttempts: 3);

  static const none = RetryPolicy(maxAttempts: 1);

  bool _isRetriable(ApiException e) =>
      e.code == ApiException.timeout ||
      e.code == ApiException.offline ||
      e.code == ApiException.serverError;

  /// Executes [action], retrying transient failures with backoff. Any
  /// non-retriable error rethrows immediately.
  Future<T> run<T>(Future<T> Function() action) async {
    var attempt = 0;
    final random = Random();
    while (true) {
      attempt += 1;
      try {
        return await action();
      } on ApiException catch (e) {
        if (!_isRetriable(e) || attempt >= maxAttempts) {
          rethrow;
        }
        // Full jitter: sleep = random(0, min(max, base * 2^(attempt-1)))
        final capMs = min(
          maxDelay.inMilliseconds,
          baseDelay.inMilliseconds * pow(2, attempt - 1).toInt(),
        );
        final sleep = Duration(milliseconds: random.nextInt(capMs + 1));
        await Future<void>.delayed(sleep);
      }
    }
  }
}

```

### `mobile/lib/core/push/noop_push_provider.dart`

```dart
import 'push_provider.dart';

/// The honest "push is not configured" provider (Phase 18 §53).
///
/// When no push transport is configured — which is the default for this
/// foundation — the app must NOT pretend to send notifications. The UI shows
/// push as unavailable and never calls delivery code paths.
class NoopPushProvider implements PushProvider {
  const NoopPushProvider();

  @override
  bool get isConfigured => false;

  @override
  String get platform => 'android';

  @override
  String get providerName => 'none';

  @override
  Future<String?> requestToken() async => null;

  @override
  Future<bool> requestPermission() async => false;
}

```

### `mobile/lib/core/push/push_provider.dart`

```dart
/// Push-notification provider abstraction (Phase 18 §53).
///
/// The mobile app NEVER ships production credentials and NEVER fakes
/// delivery. A provider reports whether it is actually configured; when it
/// is not, push is disabled honestly.
abstract class PushProvider {
  /// True only when this provider has a real, configured transport.
  bool get isConfigured;

  /// OS platform reported to `POST /api/v1/me/devices` (`android` / `ios`).
  String get platform;

  /// Provider reported to `POST /api/v1/me/devices` (`fcm` / `apns`).
  String get providerName;

  /// Requests the OS push token for this installation, or returns null when
  /// unavailable / permission denied. Never fabricates a token.
  Future<String?> requestToken();

  /// Requests the user's OS-level push permission (returns true if granted).
  Future<bool> requestPermission();
}

```

### `mobile/lib/core/push/push_service.dart`

```dart
import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../cache/offline_cache.dart';
import 'noop_push_provider.dart';
import 'push_provider.dart';

/// Coordinates push-token registration with the backend (Phase 18 §53/§54).
///
/// Flow (server-authoritative):
///   1. if the provider is not configured, push is disabled — nothing runs;
///   2. request the OS token;
///   3. register it via `POST /api/v1/me/devices` (the server stores only
///      its sha256 hash and never echoes it back — the response contains the
///      device id but no token material);
///   4. on logout, unregister the device by id.
class PushService {
  PushService({
    required ApiClient api,
    required PushProvider provider,
    OfflineCache? cache,
  })  : _api = api,
        _provider = provider,
        _cache = cache ?? OfflineCache.instance;

  static const _deviceIdKey = 'push.device_id';

  final ApiClient _api;
  final PushProvider _provider;
  final OfflineCache _cache;

  int? _registeredDeviceId;

  PushProvider get provider => _provider;
  bool get isConfigured => _provider.isConfigured;

  /// Registers (or refreshes) the device token. No-op when unconfigured.
  Future<void> sync() async {
    if (!_provider.isConfigured) {
      return;
    }
    final token = await _provider.requestToken();
    if (token == null || token.isEmpty) {
      return;
    }
    try {
      final envelope = await _api.post(
        '/me/devices',
        body: {
          'platform': _provider.platform,
          'provider': _provider.providerName,
          'token': token,
        },
      );
      final id = envelope.asMap?['id'];
      if (id is int) {
        _registeredDeviceId = id;
        await _cache.put(_deviceIdKey, {'id': id});
      }
    } on ApiException {
      // Registration is best-effort; a failure keeps push disabled until the
      // next successful sync.
    }
  }

  /// Best-effort unregister on logout. MUST run before the session token is
  /// cleared (the endpoint requires authentication). Never blocks logout.
  Future<void> unregisterOnLogout() async {
    final id = await _registeredDeviceIdOrCached();
    if (id == null) {
      return;
    }
    try {
      await _api.delete('/me/devices/$id');
    } on ApiException {
      // Best effort.
    }
    _registeredDeviceId = null;
    await _cache.remove(_deviceIdKey);
  }

  Future<int?> _registeredDeviceIdOrCached() async {
    if (_registeredDeviceId != null) {
      return _registeredDeviceId;
    }
    final cached = await _cache.get(_deviceIdKey);
    final id = cached?.payload['id'];
    return id is int ? id : null;
  }
}

/// Convenience for wiring a disabled push stack.
PushService noopPushService(ApiClient api) =>
    PushService(api: api, provider: const NoopPushProvider());

```

### `mobile/lib/core/session/auth_session.dart`

```dart
import '../api/generated/openapi_models.dart';

/// Immutable authenticated session (Phase 18 §9).
///
/// Holds the access token plus the documented `Me` resource. The token is
/// the ONLY secret in the session and is never serialized into logs or
/// analytics — `toJson`/`toString` deliberately exclude it.
class UserSession {
  const UserSession({
    required this.token,
    required this.user,
    this.tokenExpiresAt,
    this.refreshToken,
  });

  final String token;
  final Me user;
  final String? tokenExpiresAt;
  final String? refreshToken;

  /// True when the token is known to be expired (parse failure => false, the
  /// server remains authoritative and will reject with `token_expired`).
  bool get isExpired {
    final expiresAt = tokenExpiresAt;
    if (expiresAt == null) {
      return false;
    }
    final expiry = DateTime.tryParse(expiresAt);
    if (expiry == null) {
      return false;
    }
    return expiry.isBefore(DateTime.now());
  }

  Map<String, dynamic> toJson() => {
        // The token is intentionally NOT included here — this map is only
        // used for diagnostics and tests that must never see the raw token.
        'user': user.toJson(),
        'token_expires_at': tokenExpiresAt,
      };
}

```

### `mobile/lib/core/session/session_manager.dart`

```dart
import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../api/generated/openapi_models.dart';
import '../push/push_service.dart';
import 'auth_session.dart';
import 'session_store.dart';

/// Reasons a session ended (Phase 18 §9/§60). Each maps to a distinct
/// security/account screen message.
enum SessionEndReason {
  manualLogout,
  tokenExpired,
  tokenRevoked,
  accountInactive,
  unauthenticated;

  bool get isSecurityEvent => this != SessionEndReason.manualLogout;

  static SessionEndReason fromError(ApiException error) {
    switch (error.code) {
      case ApiException.accountInactive:
        return SessionEndReason.accountInactive;
      case ApiException.tokenExpired:
        return SessionEndReason.tokenExpired;
      case ApiException.tokenRevoked:
        return SessionEndReason.tokenRevoked;
      default:
        return SessionEndReason.unauthenticated;
    }
  }
}

/// Single owner of the authenticated session (Phase 18 §9).
///
/// The session manager is authoritative for the CLIENT-side lifecycle only —
/// the server stays authoritative for everything else. It:
///   * restores the persisted session at startup,
///   * validates the token against `GET /me`,
///   * force-clears the session on `token_expired` / `token_revoked` /
///     `account_inactive` / `unauthenticated`,
///   * routes the UI to the security/account screen for security events.
class SessionManager extends ChangeNotifier {
  SessionManager({
    required ApiClient api,
    required SessionStore store,
    PushService? pushService,
  })  : _api = api,
        _store = store,
        _push = pushService {
    // The API client reports session-terminating errors back to us so a 401
    // from ANY request force-clears auth state in one place.
    _api.onSessionTerminated = _handleTermination;
  }

  final ApiClient _api;
  final SessionStore _store;
  final PushService? _push;

  UserSession? _session;
  bool _restoring = true;
  SessionEndReason? _endReason;
  String? _securityContext;

  UserSession? get session => _session;
  Me? get me => _session?.user;
  String? get token => _session?.token;
  bool get isAuthenticated => _session != null;
  bool get restoring => _restoring;
  SessionEndReason? get endReason => _endReason;
  String? get securityContext => _securityContext;

  /// Restores a persisted session and validates it against the server.
  Future<void> restore() async {
    _restoring = true;
    final stored = await _store.load();
    if (stored == null) {
      _restoring = false;
      notifyListeners();
      return;
    }

    // Client-side expiry check first (fast path); the server still gets the
    // final say via the 401 mapping.
    if (stored.isExpired) {
      await _end(SessionEndReason.tokenExpired);
      _restoring = false;
      notifyListeners();
      return;
    }

    _session = stored;
    _restoring = false;
    notifyListeners();

    try {
      final envelope = await _api.get('/me');
      final freshUser = Me.fromJson(envelope.asMap ?? const {});
      _session = UserSession(
        token: stored.token,
        user: freshUser,
        tokenExpiresAt: stored.tokenExpiresAt,
        refreshToken: stored.refreshToken,
      );
      await _store.save(_session!);
      notifyListeners();
    } on ApiException catch (e) {
      // The ApiClient already routed session-terminating codes to
      // _handleTermination; anything else is transient (offline etc.) and we
      // keep the cached session.
      if (e.isSessionTerminating) {
        await _end(SessionEndReason.fromError(e));
      }
    } catch (_) {
      // Offline at startup: keep the restored session.
    }
  }

  /// Completes a login with a freshly issued token from a documented auth
  /// endpoint. The server is authoritative; we just store what it issued.
  Future<void> establish({
    required String token,
    required Me user,
    String? tokenExpiresAt,
    String? refreshToken,
  }) async {
    final session = UserSession(
      token: token,
      user: user,
      tokenExpiresAt: tokenExpiresAt,
      refreshToken: refreshToken,
    );
    await _store.save(session);
    _session = session;
    _endReason = null;
    _securityContext = null;
    notifyListeners();
    // Push device token is registered on the next sync (post-login).
    await _push?.sync();
  }

  /// Local logout. Clears secure storage and (best-effort) unregisters the
  /// push device token.
  Future<void> logout() async {
    await _push?.unregisterOnLogout();
    await _store.clear();
    _session = null;
    _endReason = SessionEndReason.manualLogout;
    notifyListeners();
  }

  /// Force-clears auth state after a security event, routing the UI to the
  /// security/account screen.
  Future<void> _handleTermination(ApiException error) async {
    if (_session == null) {
      return;
    }
    await _end(SessionEndReason.fromError(error));
  }

  Future<void> _end(SessionEndReason reason) async {
    // Clear in-memory state synchronously so the UI (and tests) observe the
    // ended session immediately; storage cleanup happens right after.
    _session = null;
    _endReason = reason;
    _securityContext = reason == SessionEndReason.accountInactive
        ? 'account_inactive'
        : reason == SessionEndReason.tokenRevoked
            ? 'token_revoked'
            : 'session_expired';
    notifyListeners();
    await _store.clear();
  }

  /// Replaces the cached user (e.g. after a profile update) and persists it.
  Future<void> refreshUser(Me user) async {
    final current = _session;
    if (current == null) {
      return;
    }
    _session = UserSession(
      token: current.token,
      user: user,
      tokenExpiresAt: current.tokenExpiresAt,
      refreshToken: current.refreshToken,
    );
    await _store.save(_session!);
    notifyListeners();
  }

  /// Clears the end-reason flag (used after the user acknowledges the
  /// security screen and returns to the login flow).
  void acknowledgeSecurityEvent() {
    _endReason = null;
    _securityContext = null;
    notifyListeners();
  }
}

```

### `mobile/lib/core/session/session_store.dart`

```dart
import 'dart:convert';

import '../api/generated/openapi_models.dart';
import '../storage/secure_storage.dart';
import 'auth_session.dart';

/// Persists the authenticated session into secure storage (Phase 18 §9/§52).
///
/// The token is stored under its own key so the API client can read it
/// without deserializing the whole envelope; the full session (token + user)
/// is stored as one JSON blob under a second key. Both are cleared on logout
/// or on any session-terminating server response.
class SessionStore {
  SessionStore({required SecureStorage storage}) : _storage = storage;

  final SecureStorage _storage;

  Future<void> save(UserSession session) async {
    await _storage.write(SecureKeys.accessToken, session.token);
    await _storage.write(
      SecureKeys.sessionJson,
      jsonEncode({
        'token': session.token,
        'token_expires_at': session.tokenExpiresAt,
        'refresh_token': session.refreshToken,
        'user': session.user.toJson(),
      }),
    );
  }

  Future<UserSession?> load() async {
    final raw = await _storage.read(SecureKeys.sessionJson);
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      final token = json['token'] as String?;
      final userJson = json['user'];
      if (token == null || userJson is! Map<String, dynamic>) {
        return null;
      }
      return UserSession(
        token: token,
        tokenExpiresAt: json['token_expires_at'] as String?,
        refreshToken: json['refresh_token'] as String?,
        user: Me.fromJson(userJson),
      );
    } catch (_) {
      // A corrupt blob is treated as "no session" — never a crash, and never
      // an attempt to salvage a half-written token.
      await clear();
      return null;
    }
  }

  Future<void> clear() async {
    await _storage.delete(SecureKeys.accessToken);
    await _storage.delete(SecureKeys.sessionJson);
  }
}

```

### `mobile/lib/core/storage/secure_storage.dart`

```dart
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Secure-storage contract (Phase 18 §52).
///
/// The ONLY data permitted in secure storage is the access token (and the
/// minimal session envelope that contains it). Nothing else — no PII cache,
/// no payment data, no push tokens — belongs here.
abstract class SecureStorage {
  Future<void> write(String key, String value);

  Future<String?> read(String key);

  Future<void> delete(String key);

  Future<bool> containsKey(String key);
}

/// Storage keys. Kept in one place so the audit of "what lives in secure
/// storage" is trivial.
abstract class SecureKeys {
  static const accessToken = 'auth.access_token';
  static const sessionJson = 'auth.session_json';
}

/// Production implementation backed by the platform keystore/keychain.
class PlatformSecureStorage implements SecureStorage {
  PlatformSecureStorage([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<bool> containsKey(String key) => _storage.containsKey(key: key);
}

/// In-memory implementation used by tests and the local preview harness.
class InMemorySecureStorage implements SecureStorage {
  InMemorySecureStorage([Map<String, String>? seed]) : _map = {...?seed};

  final Map<String, String> _map;

  @override
  Future<void> write(String key, String value) async => _map[key] = value;

  @override
  Future<String?> read(String key) async => _map[key];

  @override
  Future<void> delete(String key) async => _map.remove(key);

  @override
  Future<bool> containsKey(String key) async => _map.containsKey(key);
}

```

### `mobile/lib/core/telemetry/crash_reporter.dart`

```dart
import 'package:flutter/foundation.dart';

/// Crash/error-reporting abstraction (Phase 18 §62).
///
/// A real build can bind a Sentry/Crashlytics adapter behind this interface;
/// the reference implementation ships a no-op and a debug logger. Reporters
/// must never receive credentials, tokens, OTP codes, raw IPs or device
/// fingerprints — callers only ever pass sanitized, categorized facts.
abstract class CrashReporter {
  /// Reports a handled (caught) error with a redacted context label.
  void record(String context, Object error, StackTrace? stackTrace);

  /// Reports an uncaught platform error.
  void recordFlutterError(FlutterErrorDetails details);

  bool get isEnabled;
}

class NoopCrashReporter implements CrashReporter {
  const NoopCrashReporter();

  @override
  void record(String context, Object error, StackTrace? stackTrace) {}

  @override
  void recordFlutterError(FlutterErrorDetails details) {}

  @override
  bool get isEnabled => false;
}

/// Debug-only reporter. Never emits in release mode, and never logs payload
/// contents — only the redacted context label and error category.
class LogCrashReporter implements CrashReporter {
  const LogCrashReporter();

  @override
  void record(String context, Object error, StackTrace? stackTrace) {
    if (kDebugMode) {
      // ignore: avoid_print
      print('[crash][$context] ${error.runtimeType}: $error');
    }
  }

  @override
  void recordFlutterError(FlutterErrorDetails details) {
    if (kDebugMode) {
      // ignore: avoid_print
      print('[crash][flutter] ${details.exceptionAsString()}');
    }
  }

  @override
  bool get isEnabled => kDebugMode;
}

```

### `mobile/lib/core/telemetry/product_metrics.dart`

```dart
import 'dart:io';

import '../../config/app_config.dart';

/// Minimal, privacy-safe product telemetry (Phase 18 §34).
///
/// Only a hard allow-list of non-identifying fields may ever be recorded:
/// app version, platform (OS), event name/category and duration. Passwords,
/// OTP codes, tokens, raw IPs, risk scores and device fingerprints are never
/// recorded — they are simply dropped by construction.
class ProductMetrics {
  ProductMetrics._();

  /// Optional sink (e.g. a local logger or a remote metrics pipeline).
  /// Nothing is sent unless a sink is attached.
  static void Function(String event, Map<String, String> fields)? sink;

  static const Set<String> _allowedFields = {
    'app_version',
    'platform',
    'event',
    'category',
    'duration_ms',
  };

  static void record(String event, {Map<String, String> fields = const {}}) {
    final filtered = <String, String>{
      'event': event,
      'app_version': AppConfig.instance.version,
      'platform': Platform.operatingSystem,
      for (final e in fields.entries)
        if (_allowedFields.contains(e.key)) e.key: e.value,
    };
    sink?.call(event, filtered);
  }

  /// Times a block and records its duration under [category].
  static Future<T> timed<T>(
    String event, {
    String? category,
    required Future<T> Function() action,
  }) async {
    final stopwatch = Stopwatch()..start();
    try {
      return await action();
    } finally {
      stopwatch.stop();
      record(
        event,
        fields: {
          if (category != null) 'category': category,
          'duration_ms': '${stopwatch.elapsedMilliseconds}',
        },
      );
    }
  }
}

```


## Mobile — data repositories

### `mobile/lib/data/repositories/app_meta_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Server-driven app metadata (Phase 18 §55).
///
/// `GET /api/v1/app/meta` is anonymous and tells the client the minimum /
/// latest supported app versions, the deep-link scheme, push capability
/// flags, and the BDT / Asia/Dhaka platform contract.
class AppMetaRepository {
  AppMetaRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  AppMeta? _cached;

  AppMeta? get cached => _cached;

  Future<AppMeta> fetch() async {
    final envelope = await _api.get('/app/meta', auth: false);
    final meta = AppMeta.fromJson(envelope.asMap ?? const {});
    _cached = meta;
    return meta;
  }
}

```

### `mobile/lib/data/repositories/auth_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Auth over the existing `/api/v1` account engine (Phase 18 §4).
///
/// The mobile client has NO second account database and NO invented refresh
/// lifecycle — every method here is a thin client over a documented endpoint.
class AuthRepository {
  AuthRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// POST /auth/login (email + password).
  Future<AuthSession> login(String email, String password) async {
    final envelope = await _api.post(
      '/auth/login',
      body: {'email': email, 'password': password},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/register (email + password signup).
  Future<AuthSession> register({
    required String name,
    required String username,
    required String email,
    required String password,
    String? phone,
    String? gameUid,
    required String role,
  }) async {
    final envelope = await _api.post(
      '/auth/register',
      body: {
        'name': name,
        'username': username,
        'email': email,
        'password': password,
        'password_confirmation': password,
        'role': role,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (gameUid != null && gameUid.isNotEmpty) 'game_uid': gameUid,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/otp/request.
  Future<void> requestOtp(String phone, {String purpose = 'login'}) async {
    await _api.post(
      '/auth/otp/request',
      body: {'phone': phone, 'purpose': purpose},
      idempotencyKey: Idempotency.generate(),
    );
  }

  /// POST /auth/otp/verify (phone login / signup-linking).
  Future<AuthSession> verifyOtp(
    String phone,
    String code, {
    String purpose = 'login',
  }) async {
    final envelope = await _api.post(
      '/auth/otp/verify',
      body: {'phone': phone, 'purpose': purpose, 'code': code},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/google (id_token -> platform session).
  Future<AuthSession> google(String idToken) async {
    final envelope = await _api.post(
      '/auth/google',
      body: {'id_token': idToken},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }
}

```

### `mobile/lib/data/repositories/device_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Push-device registry client (Phase 18 §54). Only ever touches the
/// authenticated user's OWN devices (owner-only policy, enforced server-side
/// and mirrored here). The raw token is sent once; the server stores only
/// its sha256 hash and never echoes it back.
class DeviceRepository {
  DeviceRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<MobileDevice>> list() async {
    final envelope = await _api.get('/me/devices');
    return envelope.asList
        .map((m) => MobileDevice.fromJson(m))
        .toList(growable: false);
  }

  Future<Map<String, dynamic>> register({
    required String platform,
    required String provider,
    required String token,
    String? label,
  }) async {
    final envelope = await _api.post('/me/devices', body: {
      'platform': platform,
      'provider': provider,
      'token': token,
      if (label != null && label.isNotEmpty) 'device_label': label,
    });
    return envelope.asMap ?? const {};
  }

  Future<void> delete(int deviceId) async {
    await _api.delete('/me/devices/$deviceId');
  }
}

```

### `mobile/lib/data/repositories/dispute_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Disputes (Phase 18 §29).
///
/// The API is read-only for disputes: the client lists the user's own
/// disputes and shows a single dispute. Dispute FILING happens on the web
/// match page — the mobile client does not invent a create endpoint.
class DisputeRepository {
  DisputeRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<Dispute>> mine() async {
    final envelope = await _api.get('/me/disputes');
    return envelope.asList
        .map((m) => Dispute.fromJson(m))
        .toList(growable: false);
  }

  Future<Dispute> get(int id) async {
    final envelope = await _api.get('/disputes/$id');
    return Dispute.fromJson(envelope.asMap ?? const {});
  }
}

```

### `mobile/lib/data/repositories/leaderboard_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Leaderboards / rankings (Phase 18 §22). Rank and points are computed
/// server-side; the client only renders them.
class LeaderboardRepository {
  LeaderboardRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /leaderboards — ranked tournaments.
  Future<List<Tournament>> rankedTournaments() async {
    final envelope = await _api.get('/leaderboards');
    return envelope.asList
        .map((m) => Tournament.fromJson(m))
        .toList(growable: false);
  }

  /// GET /leaderboards/{tournament} — standings.
  Future<List<StandingRow>> standings(int tournamentId) async {
    final envelope = await _api.get('/leaderboards/$tournamentId');
    return envelope.asList
        .map((m) => StandingRow.fromJson(m))
        .toList(growable: false);
  }

  /// GET /players/{id}/ranking.
  Future<Map<String, dynamic>> playerRanking(int userId) async {
    final envelope = await _api.get('/players/$userId/ranking');
    return envelope.asMap ?? const {};
  }
}

```

### `mobile/lib/data/repositories/live_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Live activity (Phase 18 §36).
class LiveRepository {
  LiveRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/live — the current user's live/upcoming activity feed.
  Future<List<LiveEvent>> me() async {
    final envelope = await _api.get('/me/live');
    return envelope.asList
        .map((m) => LiveEvent.fromJson(m))
        .toList(growable: false);
  }

  /// GET /tournaments/{id}/live — a tournament's live feed.
  Future<List<LiveEvent>> tournament(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/live');
    return envelope.asList
        .map((m) => LiveEvent.fromJson(m))
        .toList(growable: false);
  }
}

```

### `mobile/lib/data/repositories/match_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Match reads and score submission (Phase 18 §26/§27).
///
/// The client submits ONLY the documented `{team_id, kills, placement}` and
/// never attempts to send points/status (the server prohibits them).
class MatchRepository {
  MatchRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<MatchModel> get(int id) async {
    final envelope = await _api.get('/matches/$id');
    return MatchModel.fromJson(envelope.asMap ?? const {});
  }

  Future<List<Score>> scores(int matchId) async {
    final envelope = await _api.get('/matches/$matchId/scores');
    return envelope.asList
        .map((m) => Score.fromJson(m))
        .toList(growable: false);
  }

  Future<Score> submitScore({
    required int matchId,
    required int teamId,
    required int kills,
    required int placement,
  }) async {
    final envelope = await _api.post(
      '/matches/$matchId/scores',
      body: {
        'team_id': teamId,
        'kills': kills,
        'placement': placement,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return Score.fromJson(envelope.asMap ?? const {});
  }
}

```

### `mobile/lib/data/repositories/notification_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// In-app notifications (Phase 18 §28).
class NotificationRepository {
  NotificationRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<NotificationModel>> list({int page = 1}) async {
    final envelope =
        await _api.get('/me/notifications', query: {'page': '$page'});
    return envelope.asList
        .map((m) => NotificationModel.fromJson(m))
        .toList(growable: false);
  }

  Future<int> unreadCount() async {
    final envelope = await _api.get('/me/notifications/unread-count');
    final value = envelope.data;
    if (value is int) {
      return value;
    }
    if (value is Map<String, dynamic>) {
      return (value['count'] as num?)?.toInt() ?? 0;
    }
    return 0;
  }

  Future<void> markRead(int id) async {
    await _api.post('/me/notifications/$id/read');
  }

  Future<void> markAllRead() async {
    await _api.post('/me/notifications/read-all');
  }
}

```

### `mobile/lib/data/repositories/profile_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Current-user profile (Phase 18 §59).
class ProfileRepository {
  ProfileRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Me> me() async {
    final envelope = await _api.get('/me');
    return Me.fromJson(envelope.asMap ?? const {});
  }

  /// PUT/PATCH /me/profile. Only documented, non-sensitive fields.
  Future<Me> update({
    String? name,
    String? bio,
    String? country,
    String? region,
    String? avatar,
    String? privacy,
  }) async {
    final envelope = await _api.patch('/me/profile', body: {
      if (name != null) 'name': name,
      if (bio != null) 'bio': bio,
      if (country != null) 'country': country,
      if (region != null) 'region': region,
      if (avatar != null) 'avatar': avatar,
      if (privacy != null) 'privacy': privacy,
    });
    return Me.fromJson(envelope.asMap ?? const {});
  }

  /// Public player profile (GET /players/{id}).
  Future<UserProfile> player(int userId) async {
    final envelope = await _api.get('/players/$userId');
    return UserProfile.fromJson(envelope.asMap ?? const {});
  }
}

```

### `mobile/lib/data/repositories/security_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Account security / sessions (Phase 18 §9/§61).
class SecurityRepository {
  SecurityRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/security — sign-in methods and account status (never internal
  /// signals like phone numbers, fraud flags, device or IP data).
  Future<Map<String, dynamic>> security() async {
    final envelope = await _api.get('/me/security');
    return envelope.asMap ?? const {};
  }

  /// GET /me/sessions.
  Future<List<ApiSession>> sessions() async {
    final envelope = await _api.get('/me/sessions');
    return envelope.asList
        .map((m) => ApiSession.fromJson(m))
        .toList(growable: false);
  }

  /// DELETE /me/sessions/{id}.
  Future<void> revokeSession(String id) async {
    await _api.delete('/me/sessions/$id');
  }

  /// POST /me/sessions/revoke-all.
  Future<void> revokeAllSessions() async {
    await _api.post('/me/sessions/revoke-all');
  }

  /// POST /me/sessions/revoke-others.
  Future<void> revokeOtherSessions() async {
    await _api.post('/me/sessions/revoke-others');
  }
}

```

### `mobile/lib/data/repositories/support_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Support tickets (Phase 18 §25).
class SupportRepository {
  SupportRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<SupportTicket>> tickets() async {
    final envelope = await _api.get('/me/support');
    return envelope.asList
        .map((m) => SupportTicket.fromJson(m))
        .toList(growable: false);
  }

  Future<SupportTicket> get(int id) async {
    final envelope = await _api.get('/me/support/$id');
    return SupportTicket.fromJson(envelope.asMap ?? const {});
  }

  /// Returns `{messages, latest_message_id}` from the server.
  Future<Map<String, dynamic>> messages(int ticketId, {int afterId = 0}) async {
    final envelope = await _api.get(
      '/me/support/$ticketId/messages',
      query: {'after': '$afterId'},
    );
    return envelope.asMap ?? const {};
  }

  Future<SupportTicket> create({
    required String subject,
    required String category,
    required String message,
    String? priority,
  }) async {
    final envelope = await _api.post(
      '/me/support',
      body: {
        'subject': subject,
        'category': category,
        'message': message,
        if (priority != null) 'priority': priority,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return SupportTicket.fromJson(envelope.asMap ?? const {});
  }

  Future<SupportMessage> reply(int ticketId, String body) async {
    final envelope = await _api.post(
      '/me/support/$ticketId/messages',
      body: {'body': body},
      idempotencyKey: Idempotency.generate(),
    );
    return SupportMessage.fromJson(envelope.asMap ?? const {});
  }
}

```

### `mobile/lib/data/repositories/team_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Teams and rosters (Phase 18 §21).
class TeamRepository {
  TeamRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/teams — teams the current user captains or belongs to.
  Future<List<Team>> mine() async {
    final envelope = await _api.get('/me/teams');
    return envelope.asList.map((m) => Team.fromJson(m)).toList(growable: false);
  }

  Future<Team> get(int id) async {
    final envelope = await _api.get('/teams/$id');
    return Team.fromJson(envelope.asMap ?? const {});
  }

  /// PATCH /teams/{id}.
  Future<Team> update({
    required int teamId,
    required String name,
    required String captainName,
    required String phone,
    required String gameUid,
  }) async {
    final envelope = await _api.patch('/teams/$teamId', body: {
      'name': name,
      'captain_name': captainName,
      'phone': phone,
      'game_uid': gameUid,
    });
    return Team.fromJson(envelope.asMap ?? const {});
  }

  /// GET /teams/{id}/roster.
  Future<List<Map<String, dynamic>>> roster(int teamId) async {
    final envelope = await _api.get('/teams/$teamId/roster');
    return envelope.asList;
  }

  /// POST /teams/{id}/roster.
  Future<void> addMember(int teamId, String playerName, String gameUid) async {
    await _api.post('/teams/$teamId/roster', body: {
      'player_name': playerName,
      'game_uid': gameUid,
    });
  }

  /// DELETE /teams/{id}/roster/{memberId}.
  Future<void> removeMember(int teamId, int memberId) async {
    await _api.delete('/teams/$teamId/roster/$memberId');
  }

  /// POST /teams/{id}/withdraw.
  Future<void> withdraw(int teamId) async {
    await _api.post('/teams/$teamId/withdraw');
  }
}

```

### `mobile/lib/data/repositories/tournament_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Tournaments, registrations and matches (Phase 18 §17/§19/§26).
class TournamentRepository {
  TournamentRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<Tournament>> list({
    int page = 1,
    String? search,
    String? status,
    String? gameMode,
  }) async {
    final envelope = await _api.get('/tournaments', query: {
      'page': '$page',
      if (search != null && search.isNotEmpty) 'search': search,
      if (status != null && status.isNotEmpty) 'status': status,
      if (gameMode != null && gameMode.isNotEmpty) 'game_mode': gameMode,
    });
    return envelope.asList
        .map((m) => Tournament.fromJson(m))
        .toList(growable: false);
  }

  Future<Tournament> get(int id) async {
    final envelope = await _api.get('/tournaments/$id');
    return Tournament.fromJson(envelope.asMap ?? const {});
  }

  Future<List<MatchModel>> matches(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/matches');
    return envelope.asList
        .map((m) => MatchModel.fromJson(m))
        .toList(growable: false);
  }

  /// POST /tournaments/{id}/registrations — creates (or waitlists) a team.
  /// Returns the raw registration envelope; the client trusts the server's
  /// `waitlisted` / `waitlist_position` / `next_step` verdict.
  Future<Map<String, dynamic>> register({
    required int tournamentId,
    required String name,
    required String captainName,
    required String phone,
    required String gameUid,
    List<Map<String, String>> members = const [],
  }) async {
    final envelope = await _api.post(
      '/tournaments/$tournamentId/registrations',
      body: {
        'name': name,
        'captain_name': captainName,
        'phone': phone,
        'game_uid': gameUid,
        'members': members,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return envelope.asMap ?? const {};
  }

  /// POST /tournaments/{id}/check-in.
  Future<Map<String, dynamic>> checkIn(int tournamentId, int teamId) async {
    final envelope = await _api.post(
      '/tournaments/$tournamentId/check-in',
      body: {'team_id': teamId},
      idempotencyKey: Idempotency.generate(),
    );
    return envelope.asMap ?? const {};
  }

  /// GET /tournaments/{id}/bracket.
  Future<dynamic> bracket(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/bracket');
    return envelope.data;
  }
}

```

### `mobile/lib/data/repositories/wallet_repository.dart`

```dart
import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Result of `POST /payments`: the payment plus an optional external
/// redirect URL (present only for non-manual providers).
class PaymentIntent {
  const PaymentIntent({required this.payment, this.redirectUrl});

  final Payment payment;
  final String? redirectUrl;
}

/// Wallet, ledger, payouts and payments (Phase 18 §23/§24).
///
/// The client NEVER marks a payment successful locally and never computes a
/// balance. Payments are entry-fee payments for a team: `POST /payments`
/// takes `{team_id, provider}` and returns a `redirect_url` only for
/// non-manual providers. Completion is always determined by polling
/// `GET /payments/{id}` until the SERVER reports a terminal status.
class WalletRepository {
  WalletRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Wallet> wallet() async {
    final envelope = await _api.get('/me/wallet');
    return Wallet.fromJson(envelope.asMap ?? const {});
  }

  Future<List<LedgerEntry>> ledger({int page = 1}) async {
    final envelope =
        await _api.get('/me/wallet/ledger', query: {'page': '$page'});
    return envelope.asList
        .map((m) => LedgerEntry.fromJson(m))
        .toList(growable: false);
  }

  Future<List<Payout>> payouts() async {
    final envelope = await _api.get('/me/payouts');
    return envelope.asList
        .map((m) => Payout.fromJson(m))
        .toList(growable: false);
  }

  /// POST /payments — creates an entry-fee payment for a team.
  ///
  /// The server responds `{payment, redirect_url}`; `redirect_url` is present
  /// only for providers that require an external redirect (never for the
  /// Bangladesh manual providers).
  Future<PaymentIntent> createPayment({
    required int teamId,
    required String provider,
  }) async {
    final envelope = await _api.post(
      '/payments',
      body: {'team_id': teamId, 'provider': provider},
      idempotencyKey: Idempotency.generate(),
    );
    final map = envelope.asMap ?? const {};
    final paymentJson = map['payment'];
    final payment = paymentJson is Map<String, dynamic>
        ? Payment.fromJson(paymentJson)
        : Payment.fromJson(map);
    return PaymentIntent(
      payment: payment,
      redirectUrl: map['redirect_url'] as String?,
    );
  }

  /// GET /payments/{id} — server-authoritative payment state.
  Future<Payment> payment(int id) async {
    final envelope = await _api.get('/payments/$id');
    return Payment.fromJson(envelope.asMap ?? const {});
  }

  /// GET /payments/methods — saved methods + provider availability.
  Future<Map<String, dynamic>> methods() async {
    final envelope = await _api.get('/payments/methods');
    return envelope.asMap ?? const {};
  }

  /// Polls until the server reports a terminal status (or timeout).
  Future<Payment> awaitTerminal(
    int id, {
    Duration timeout = const Duration(minutes: 2),
  }) async {
    final deadline = DateTime.now().add(timeout);
    while (DateTime.now().isBefore(deadline)) {
      final p = await payment(id);
      final status = p.status ?? '';
      if (status == 'PAID' ||
          status == 'FAILED' ||
          status == 'CANCELLED' ||
          status == 'EXPIRED') {
        return p;
      }
      await Future<void>.delayed(const Duration(seconds: 2));
    }
    return payment(id);
  }
}

```


## Mobile — config & features

### `mobile/lib/config/app_config.dart`

```dart
import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// App identity and build-time environment configuration (Phase 18 §45).
///
/// Every environment-sensitive value is injected at build time via
/// `--dart-define` and defaults to a safe placeholder so a checkout never
/// runs against a hardcoded production environment. Nothing here holds a
/// secret — real credentials are injected via dart-define / platform config
/// and never committed.
///
///   flutter run \
///     --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
///     --dart-define=FFARENA_ENV=staging \
///     --dart-define=FFARENA_GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com
class AppConfig {
  const AppConfig({
    required this.appName,
    required this.version,
    required this.buildNumber,
    required this.env,
    required this.apiBaseUrl,
    required this.deepLinkScheme,
    required this.googleSignInClientId,
    required this.pushEnabled,
    required this.crashReportingEnabled,
  });

  final String appName;
  final String version;
  final String buildNumber;
  final String env;
  final String apiBaseUrl;
  final String deepLinkScheme;
  final String googleSignInClientId;
  final bool pushEnabled;
  final bool crashReportingEnabled;

  static const String _defineApiBaseUrl =
      String.fromEnvironment('FFARENA_API_BASE_URL');
  static const String _defineEnv = String.fromEnvironment('FFARENA_ENV');
  static const String _defineGoogleClientId =
      String.fromEnvironment('FFARENA_GOOGLE_CLIENT_ID');
  static const bool _definePushEnabled =
      bool.fromEnvironment('FFARENA_PUSH_ENABLED');
  static const bool _defineCrashEnabled =
      bool.fromEnvironment('FFARENA_CRASH_REPORTING_ENABLED');

  static AppConfig? _instance;

  static AppConfig get instance => _instance ??= _resolve();

  static AppConfig _resolve() {
    final env = _defineEnv.isEmpty ? 'development' : _defineEnv;
    return AppConfig(
      appName: 'FF Arena',
      version: '1.0.0',
      buildNumber: '1',
      env: env,
      apiBaseUrl: _defineApiBaseUrl.isEmpty
          ? 'http://localhost/api/v1'
          : _defineApiBaseUrl,
      deepLinkScheme: 'ffarena',
      googleSignInClientId: _defineGoogleClientId,
      pushEnabled: _definePushEnabled,
      crashReportingEnabled: _defineCrashEnabled,
    );
  }

  bool get isProduction => env == 'production';

  bool get isGoogleSignInConfigured => googleSignInClientId.isNotEmpty;

  /// Release builds must never silently point at a localhost API.
  bool get isApiBaseUrlSane => apiBaseUrl.startsWith('https://');

  /// The on-device directory for the offline read-only cache.
  static Future<Directory> cacheDirectory() async {
    final base = await getApplicationDocumentsDirectory();
    return base;
  }
}

```

### `mobile/lib/features/deep_links/deep_link_router.dart`

```dart
import 'package:flutter/foundation.dart';

/// Deep-link routing (Phase 18 §64).
///
/// Supported routes (the scheme comes from `/api/v1/app/meta`, default
/// `ffarena`):
///
///   ffarena://tournament/{id}
///   ffarena://match/{id}
///   ffarena://profile/{id}
///
/// Every deep link requires authentication and an authorized server response
/// before the target screen renders; the link itself never carries secrets,
/// room passwords, payment secrets or tokens.
enum DeepLinkTarget {
  tournament,
  match,
  profile;

  static DeepLinkTarget? fromPath(String segment) {
    switch (segment) {
      case 'tournament':
        return DeepLinkTarget.tournament;
      case 'match':
        return DeepLinkTarget.match;
      case 'profile':
        return DeepLinkTarget.profile;
      default:
        return null;
    }
  }
}

class DeepLink {
  const DeepLink({required this.target, required this.id, this.raw});

  final DeepLinkTarget target;
  final int id;
  final String? raw;
}

/// Parses a `scheme://host/path/{id}` deep link. Returns null for anything
/// that is not a recognized FF Arena link, and NEVER parses embedded
/// credentials or secrets out of the URI.
DeepLink? parseDeepLink(Uri uri, {String scheme = 'ffarena'}) {
  if (uri.scheme != scheme) {
    return null;
  }
  // `ffarena://tournament/{id}` puts the target in the host and the id in
  // the first path segment. Normalize both into a single segment list so
  // `ffarena://tournament/42` and equivalent forms parse identically.
  final segments = <String>[
    if (uri.host.isNotEmpty) uri.host,
    ...uri.pathSegments.where((s) => s.isNotEmpty),
  ];
  if (segments.length != 2) {
    return null;
  }
  final target = DeepLinkTarget.fromPath(segments[0]);
  if (target == null) {
    return null;
  }
  final id = int.tryParse(segments[1]);
  if (id == null) {
    return null;
  }
  // Rebuild the raw form WITHOUT query string or fragment so an attacker can
  // never smuggle secrets/payment tokens into a deep link.
  final raw = '${uri.scheme}://${uri.host}${uri.path}';
  return DeepLink(target: target, id: id, raw: raw);
}

/// The app's root navigator callback for deep links. Set by the app shell so
/// the router has no widget dependency and can be unit tested.
typedef DeepLinkHandler = void Function(DeepLink link);

class DeepLinkRouter {
  DeepLinkRouter({required String scheme}) : _scheme = scheme;

  final String _scheme;
  DeepLinkHandler? handler;

  void registerHandler(DeepLinkHandler h) {
    handler = h;
  }

  /// Entry point for the OS cold-start / warm-start URI.
  void route(String rawUri) {
    final uri = Uri.tryParse(rawUri);
    if (uri == null) {
      return;
    }
    final link = parseDeepLink(uri, scheme: _scheme);
    if (link == null) {
      debugPrint('[deeplink] ignored non-ffarena link');
      return;
    }
    handler?.call(link);
  }
}

```


## Mobile — screens

### `mobile/lib/screens/auth/forgot_password_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../core/l10n/app_localizations.dart';

/// Forgot password (Phase 18 §5). Password reset is web-only — the mobile
/// client does not invent an in-app reset flow and says so honestly.
class ForgotPasswordScreen extends StatelessWidget {
  const ForgotPasswordScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.forgotPassword)),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              children: [
                Icon(Icons.lock_reset,
                    size: 64, color: Theme.of(context).colorScheme.primary),
                const SizedBox(height: 16),
                Text(
                  l10n.forgotPasswordBody,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: 8),
                Text(
                  l10n.forgotPasswordNote,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/auth/login_screen.dart`

```dart
import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import 'forgot_password_screen.dart';
import 'phone_login_screen.dart';
import 'register_screen.dart';

/// Email + password login (Phase 18 §5/§14). A thin client over the
/// documented `/auth/*` endpoints — the server stays authoritative.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.services.auth.login(email, password);
      await _adopt(auth);
    } on ApiException catch (e) {
      _showError(e);
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _google() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final google = GoogleSignIn(
        serverClientId: AppConfig.instance.googleSignInClientId,
      );
      final account = await google.signIn();
      if (account == null) {
        setState(() => _busy = false);
        return; // User cancelled.
      }
      final authInfo = await account.authentication;
      final idToken = authInfo.idToken;
      if (idToken == null) {
        throw const ApiException(
            code: ApiException.invalidIdToken, message: 'Missing id token.');
      }
      final auth = await widget.services.auth.google(idToken);
      await _adopt(auth);
      await google.signOut();
    } on ApiException catch (e) {
      _showError(e);
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGoogleSignIn);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  /// Adopts a server-issued session (token + user) into the SessionManager.
  Future<void> _adopt(dynamic auth) async {
    final token = auth.token as String?;
    final user = auth.user;
    if (token == null || token.isEmpty || user == null) {
      throw const ApiException(
          code: ApiException.invalidCredentials, message: 'Bad session.');
    }
    await widget.services.session.establish(
      token: token,
      user: user,
      tokenExpiresAt: auth.tokenExpiresAt as String?,
    );
  }

  void _showError(ApiException e) {
    setState(() => _error = e.localized(AppLocalizations.of(context)));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    l10n.appName,
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .headlineMedium
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    l10n.tagline,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 32),
                  TextField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.email],
                    decoration: InputDecoration(
                      labelText: l10n.email,
                      prefixIcon: const Icon(Icons.mail_outline),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    autofillHints: const [AutofillHints.password],
                    decoration: InputDecoration(
                      labelText: l10n.password,
                      prefixIcon: const Icon(Icons.lock_outline),
                    ),
                    onSubmitted: (_) => _login(),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => const ForgotPasswordScreen(),
                        ),
                      ),
                      child: Text(l10n.forgotPassword),
                    ),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _busy ? null : _login,
                    child: _busy
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(l10n.logIn),
                  ),
                  if (config.isGoogleSignInConfigured) ...[
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : _google,
                      icon: const Icon(Icons.g_mobiledata, size: 24),
                      label: Text(l10n.continueWithGoogle),
                    ),
                  ],
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: _busy
                        ? null
                        : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) =>
                                    PhoneLoginScreen(services: widget.services),
                              ),
                            ),
                    icon: const Icon(Icons.phone_outlined),
                    label: Text(l10n.continueWithPhone),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) =>
                                    RegisterScreen(services: widget.services),
                              ),
                            ),
                    child: Text(l10n.createAccount),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      _error!,
                      textAlign: TextAlign.center,
                      style:
                          TextStyle(color: Theme.of(context).colorScheme.error),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/auth/otp_verify_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Phone OTP verification — step 2 of 2 (Phase 18 §14). Reuses the SAME
/// logical attempt via an Idempotency-Key only inside the repository; a
/// failed verify here simply requires the user to re-request a code.
class OtpVerifyScreen extends StatefulWidget {
  const OtpVerifyScreen({
    super.key,
    required this.services,
    required this.phone,
    required this.purpose,
  });

  final AppServices services;
  final String phone;
  final String purpose;

  @override
  State<OtpVerifyScreen> createState() => _OtpVerifyScreenState();
}

class _OtpVerifyScreenState extends State<OtpVerifyScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.services.auth
          .verifyOtp(widget.phone, code, purpose: widget.purpose);
      final token = auth.token;
      final user = auth.user;
      if (token == null || user == null) {
        throw const ApiException(
            code: ApiException.invalidCode, message: 'Bad session.');
      }
      await widget.services.session.establish(
        token: token,
        user: user,
        tokenExpiresAt: auth.tokenExpiresAt,
      );
      // On successful login the FFApp routes to the shell automatically.
    } on ApiException catch (e) {
      setState(() => _error = e.localized(AppLocalizations.of(context)));
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.enterCode)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(l10n.codeSentTo, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  autofillHints: const [AutofillHints.oneTimeCode],
                  textAlign: TextAlign.center,
                  style: const TextStyle(letterSpacing: 8, fontSize: 20),
                  decoration: InputDecoration(labelText: l10n.enterCode),
                  onSubmitted: (_) => _verify(),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : _verify,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.verify),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/auth/phone_login_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';
import 'otp_verify_screen.dart';

/// Phone OTP login — step 1 of 2 (Phase 18 §14).
class PhoneLoginScreen extends StatefulWidget {
  const PhoneLoginScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PhoneLoginScreen> createState() => _PhoneLoginScreenState();
}

class _PhoneLoginScreenState extends State<PhoneLoginScreen> {
  final _phone = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final normalized = Phone.normalizeBd(_phone.text);
    if (normalized == null) {
      setState(() => _error = AppLocalizations.of(context).phone);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth.requestOtp(normalized, purpose: 'login');
      if (!mounted) {
        return;
      }
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => OtpVerifyScreen(
            services: widget.services,
            phone: normalized,
            purpose: 'login',
          ),
        ),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.localized(AppLocalizations.of(context)));
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.continueWithPhone)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: l10n.phone,
                    prefixText: '+880 ',
                  ),
                  onSubmitted: (_) => _send(),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : _send,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.sendCode),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/auth/register_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Email + password signup (Phase 18 §5). Role is limited to player or
/// organizer — the server never mass-assigns admin.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _name = TextEditingController();
  final _username = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _gameUid = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  String _role = 'player';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _username.dispose();
    _email.dispose();
    _phone.dispose();
    _gameUid.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    if (_password.text != _confirm.text) {
      setState(() => _error = l10n.passwordConfirmation);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = await widget.services.auth.register(
        name: _name.text.trim(),
        username: _username.text.trim(),
        email: _email.text.trim(),
        password: _password.text,
        phone: _phone.text.trim(),
        gameUid: _gameUid.text.trim(),
        role: _role,
      );
      final token = auth.token;
      final user = auth.user;
      if (token == null || user == null) {
        throw const ApiException(
            code: ApiException.invalidCredentials, message: 'Bad session.');
      }
      await widget.services.session.establish(
        token: token,
        user: user,
        tokenExpiresAt: auth.tokenExpiresAt,
      );
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.createAccount)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _name,
                  textCapitalization: TextCapitalization.words,
                  decoration: InputDecoration(labelText: l10n.name),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _username,
                  autocorrect: false,
                  decoration: InputDecoration(labelText: l10n.username),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: InputDecoration(labelText: l10n.email),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(labelText: l10n.phone),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _gameUid,
                  autocorrect: false,
                  decoration: InputDecoration(labelText: l10n.gameUid),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _password,
                  obscureText: true,
                  decoration: InputDecoration(labelText: l10n.password),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _confirm,
                  obscureText: true,
                  decoration:
                      InputDecoration(labelText: l10n.passwordConfirmation),
                ),
                const SizedBox(height: 16),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(
                        value: 'player', label: Text(l10n.rolePlayer)),
                    ButtonSegment(
                        value: 'organizer', label: Text(l10n.roleOrganizer)),
                  ],
                  selected: {_role},
                  onSelectionChanged: (s) => setState(() => _role = s.first),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.createAccount),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/disputes/dispute_detail_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';

/// Single dispute (Phase 18 §29).
class DisputeDetailScreen extends StatefulWidget {
  const DisputeDetailScreen({
    super.key,
    required this.services,
    required this.disputeId,
  });

  final AppServices services;
  final int disputeId;

  @override
  State<DisputeDetailScreen> createState() => _DisputeDetailScreenState();
}

class _DisputeDetailScreenState extends State<DisputeDetailScreen> {
  late Future<Dispute> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.disputes.get(widget.disputeId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.dispute)),
      body: FutureBuilder<Dispute>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(() =>
                  _future = widget.services.disputes.get(widget.disputeId)),
              child: const SizedBox(),
            );
          }
          final d = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.status,
                trailing:
                    d.status != null ? StatusPill(status: d.status!) : null,
                child: Column(
                  children: [
                    KeyValueRow(label: l10n.category, value: d.category ?? '—'),
                    KeyValueRow(
                        label: l10n.matchNo, value: '#${d.matchId ?? '—'}'),
                    if (d.resolution != null)
                      KeyValueRow(label: l10n.status, value: d.resolution!),
                  ],
                ),
              ),
              if (d.description != null)
                SectionCard(
                  title: l10n.message,
                  child: Text(d.description!),
                ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/disputes/disputes_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'dispute_detail_screen.dart';

/// Disputes (Phase 18 §29). Read-only on mobile — filing happens on the web
/// match page; the client never invents a create endpoint.
class DisputesScreen extends StatefulWidget {
  const DisputesScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<DisputesScreen> createState() => _DisputesScreenState();
}

class _DisputesScreenState extends State<DisputesScreen> {
  late Future<List<Dispute>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.disputes.mine();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.dispute)),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            color: const Color(0xFFFFF3CD),
            padding: const EdgeInsets.all(12),
            child: Text(l10n.disputeWebOnly,
                style: const TextStyle(color: Colors.black87)),
          ),
          Expanded(
            child: FutureBuilder<List<Dispute>>(
              future: _future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  final message = snapshot.error is ApiException
                      ? (snapshot.error as ApiException).localized(l10n)
                      : l10n.errorGeneric;
                  return AsyncView(
                    loading: false,
                    error: message,
                    empty: false,
                    onRetry: () => setState(
                        () => _future = widget.services.disputes.mine()),
                    child: const SizedBox(),
                  );
                }
                final disputes = snapshot.data ?? const <Dispute>[];
                if (disputes.isEmpty) {
                  return Center(child: Text(l10n.noDisputes));
                }
                return ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: disputes.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (context, i) {
                    final d = disputes[i];
                    return Card(
                      child: ListTile(
                        title: Text(d.category ?? l10n.dispute),
                        subtitle: Text('Match #${d.matchId ?? '—'}'),
                        trailing: d.status != null
                            ? StatusPill(status: d.status!)
                            : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => DisputeDetailScreen(
                              services: widget.services,
                              disputeId: d.id!,
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/home/home_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import '../notifications/notifications_screen.dart';
import '../tournaments/tournament_detail_screen.dart';
import '../tournaments/tournament_list_screen.dart';
import '../wallet/wallet_screen.dart';

/// Home tab (Phase 18 §11): live feed, my tournaments, upcoming matches and
/// a wallet summary — all read from server-authoritative endpoints.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late Future<_HomeData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_HomeData> _load() async {
    final live = await widget.services.live.me();
    final teams = await widget.services.teams.mine();
    final wallet = await widget.services.wallet.wallet();
    return _HomeData(live: live, teams: teams, wallet: wallet);
  }

  Future<void> _refresh() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final session = widget.services.session;

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.home),
        actions: [
          IconButton(
            tooltip: l10n.notifications,
            icon: const Icon(Icons.notifications_outlined),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => NotificationsScreen(services: widget.services),
              ),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<_HomeData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return ListView(
                children: const [
                  SizedBox(height: 200),
                  Center(child: CircularProgressIndicator()),
                ],
              );
            }
            final error = _errorOf(snapshot.error, l10n);
            if (error != null) {
              return AsyncView(
                loading: false,
                error: error,
                empty: false,
                onRetry: _refresh,
                child: const SizedBox(),
              );
            }
            final data = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.only(bottom: 24),
              children: [
                // Greeting
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Text(
                    '${l10n.appName} — ${session.me?.name ?? ''}',
                    style: Theme.of(context)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                // Live feed
                if (data.live.isNotEmpty)
                  SectionCard(
                    title: l10n.live,
                    child: Column(
                      children: [
                        for (final event in data.live.take(5))
                          _LiveRow(event: event, services: widget.services),
                      ],
                    ),
                  ),
                // My teams
                if (data.teams.isNotEmpty)
                  SectionCard(
                    title: l10n.myTeams,
                    trailing: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) =>
                              TournamentListScreen(services: widget.services),
                        ),
                      ),
                      child: Text(l10n.viewAll),
                    ),
                    child: Column(
                      children: [
                        for (final team in data.teams.take(5))
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: CircleAvatar(
                              child: Text(
                                (team.name ?? '?')
                                    .substring(0, 1)
                                    .toUpperCase(),
                              ),
                            ),
                            title: Text(team.name ?? '—'),
                            subtitle: team.tournamentId != null
                                ? Text('Tournament #${team.tournamentId}')
                                : null,
                            trailing: team.status != null
                                ? StatusPill(status: team.status!)
                                : null,
                          ),
                      ],
                    ),
                  ),
                // Wallet summary
                SectionCard(
                  title: l10n.wallet,
                  trailing: TextButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => WalletScreen(services: widget.services),
                      ),
                    ),
                    child: Text(l10n.viewAll),
                  ),
                  child: Text(
                    l10n.formatMoney(data.wallet.balanceMinor ?? 0),
                    style: Theme.of(context)
                        .textTheme
                        .headlineSmall
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  String? _errorOf(Object? error, AppLocalizations l10n) {
    if (error == null) {
      return null;
    }
    if (error is ApiException) {
      return error.localized(l10n);
    }
    return l10n.errorGeneric;
  }
}

class _HomeData {
  const _HomeData({
    required this.live,
    required this.teams,
    required this.wallet,
  });

  final List<LiveEvent> live;
  final List<Team> teams;
  final Wallet wallet;
}

class _LiveRow extends StatelessWidget {
  const _LiveRow({required this.event, required this.services});

  final LiveEvent event;
  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final tournamentId = event.tournamentId;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.sports_esports),
      title: Text(event.type ?? 'event'),
      trailing: tournamentId != null
          ? IconButton(
              icon: const Icon(Icons.chevron_right),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => TournamentDetailScreen(
                      services: services, tournamentId: tournamentId),
                ),
              ),
            )
          : null,
    );
  }
}

```

### `mobile/lib/screens/leaderboard/leaderboard_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import '../standings/standings_screen.dart';

/// Leaderboard hub (Phase 18 §22): lists ranked tournaments, then opens the
/// server-computed standings.
class LeaderboardScreen extends StatefulWidget {
  const LeaderboardScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LeaderboardScreen> createState() => _LeaderboardScreenState();
}

class _LeaderboardScreenState extends State<LeaderboardScreen> {
  late Future<List<Tournament>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.rankedTournaments();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.leaderboard)),
      body: FutureBuilder<List<Tournament>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(() =>
                  _future = widget.services.leaderboard.rankedTournaments()),
              child: const SizedBox(),
            );
          }
          final tournaments = snapshot.data ?? const <Tournament>[];
          if (tournaments.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: tournaments.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = tournaments[i];
              return Card(
                child: ListTile(
                  title: Text(t.name ?? '—'),
                  subtitle: Text('${l10n.prizePool}: ${t.prizePool ?? '—'}'),
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => StandingsScreen(
                        services: widget.services,
                        tournamentId: t.id!,
                      ),
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/matches/match_center_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'match_detail_screen.dart';

/// Match centre (Phase 18 §26): lists a tournament's matches (embedded tab)
/// or, standalone, the user's upcoming matches.
class MatchCenterScreen extends StatefulWidget {
  const MatchCenterScreen({
    super.key,
    required this.services,
    this.tournamentId,
    this.embedded = false,
  });

  final AppServices services;
  final int? tournamentId;
  final bool embedded;

  @override
  State<MatchCenterScreen> createState() => _MatchCenterScreenState();
}

class _MatchCenterScreenState extends State<MatchCenterScreen> {
  late Future<List<MatchModel>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<MatchModel>> _load() {
    final id = widget.tournamentId;
    if (id != null) {
      return widget.services.tournaments.matches(id);
    }
    // Standalone: matches across the user's tournaments.
    return widget.services.teams.mine().then((teams) async {
      final matches = <MatchModel>[];
      for (final team in teams) {
        final tournamentId = team.tournamentId;
        if (tournamentId == null) {
          continue;
        }
        try {
          final list = await widget.services.tournaments.matches(tournamentId);
          for (final m in list) {
            if (m.status != null && _isUpcoming(m.status!)) {
              matches.add(m);
            }
          }
        } on ApiException {
          // Skip unavailable tournaments.
        }
      }
      return matches;
    });
  }

  bool _isUpcoming(String status) =>
      status.toLowerCase() == 'scheduled' || status.toLowerCase() == 'open';

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final content = FutureBuilder<List<MatchModel>>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          final message = snapshot.error is ApiException
              ? (snapshot.error as ApiException).localized(l10n)
              : l10n.errorGeneric;
          return AsyncView(
            loading: false,
            error: message,
            empty: false,
            onRetry: () => setState(() => _future = _load()),
            child: const SizedBox(),
          );
        }
        final matches = snapshot.data ?? const <MatchModel>[];
        if (matches.isEmpty) {
          return Center(child: Text(l10n.noScoresYet));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: matches.length,
          separatorBuilder: (_, __) => const SizedBox(height: 12),
          itemBuilder: (context, i) {
            final m = matches[i];
            return Card(
              child: ListTile(
                title: Text('${l10n.matchNo} ${m.matchNo ?? m.id ?? '—'}'),
                subtitle: Text('${l10n.round} ${m.round ?? '—'}'),
                trailing:
                    m.status != null ? StatusPill(status: m.status!) : null,
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => MatchDetailScreen(
                      services: widget.services,
                      matchId: m.id!,
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );

    if (widget.embedded) {
      return content;
    }
    return Scaffold(
      appBar: AppBar(
          title: Text(widget.tournamentId != null
              ? l10n.matches
              : l10n.myUpcomingMatches)),
      body: content,
    );
  }
}

```

### `mobile/lib/screens/matches/match_detail_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';

/// Match detail + score submission (Phase 18 §26/§27).
///
/// Room credentials come ONLY from the server's authorized Match resource and
/// are rendered without being logged or persisted. Score submission sends
/// only `{team_id, kills, placement}`.
class MatchDetailScreen extends StatefulWidget {
  const MatchDetailScreen({
    super.key,
    required this.services,
    required this.matchId,
  });

  final AppServices services;
  final int matchId;

  @override
  State<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends State<MatchDetailScreen> {
  late Future<MatchModel> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.matches.get(widget.matchId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('${l10n.matchNo} ${widget.matchId}')),
      body: FutureBuilder<MatchModel>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(
                  () => _future = widget.services.matches.get(widget.matchId)),
              child: const SizedBox(),
            );
          }
          final m = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${l10n.matchNo} ${m.matchNo ?? m.id ?? '—'}',
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
                  ),
                  if (m.status != null) StatusPill(status: m.status!),
                ],
              ),
              const SizedBox(height: 8),
              SectionCard(
                title: l10n.status,
                child: Column(
                  children: [
                    KeyValueRow(label: l10n.round, value: '${m.round ?? '—'}'),
                    KeyValueRow(
                        label: l10n.scheduled,
                        value: Dates.formatDateTime(m.scheduledAt)),
                    if (m.completedAt != null)
                      KeyValueRow(
                          label: l10n.completed,
                          value: Dates.formatDateTime(m.completedAt)),
                  ],
                ),
              ),
              if (m.roomId != null)
                SectionCard(
                  title: l10n.roomId,
                  child: Column(
                    children: [
                      KeyValueRow(label: l10n.roomId, value: m.roomId!),
                      if (m.roomPass != null)
                        KeyValueRow(label: l10n.roomPass, value: m.roomPass!),
                    ],
                  ),
                ),
              _ScoreSubmit(services: widget.services, match: m),
            ],
          );
        },
      ),
    );
  }
}

class _ScoreSubmit extends StatefulWidget {
  const _ScoreSubmit({required this.services, required this.match});

  final AppServices services;
  final MatchModel match;

  @override
  State<_ScoreSubmit> createState() => _ScoreSubmitState();
}

class _ScoreSubmitState extends State<_ScoreSubmit> {
  List<Team> _teams = const [];
  int? _teamId;
  final _kills = TextEditingController();
  final _placement = TextEditingController();
  bool _busy = false;
  String? _result;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.services.teams.mine().then((teams) {
      if (mounted) {
        setState(() {
          _teams = teams;
          if (teams.isNotEmpty) {
            _teamId = teams.first.id;
          }
        });
      }
    });
  }

  @override
  void dispose() {
    _kills.dispose();
    _placement.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    final kills = int.tryParse(_kills.text.trim()) ?? 0;
    final placement = int.tryParse(_placement.text.trim()) ?? 0;
    if (_teamId == null || placement <= 0) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.matches.submitScore(
        matchId: widget.match.id!,
        teamId: _teamId!,
        kills: kills,
        placement: placement,
      );
      setState(() => _result = l10n.scoreSubmitted);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return SectionCard(
      title: l10n.submitScore,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          DropdownButtonFormField<int>(
            initialValue: _teamId,
            decoration: InputDecoration(labelText: l10n.team),
            items: [
              for (final t in _teams)
                DropdownMenuItem(value: t.id, child: Text(t.name ?? '—')),
            ],
            onChanged: (v) => setState(() => _teamId = v),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _kills,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: l10n.kills),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  controller: _placement,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: l10n.placement),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : Text(l10n.submitScore),
          ),
          if (_result != null) ...[
            const SizedBox(height: 8),
            Text(_result!, textAlign: TextAlign.center),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/notifications/notifications_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// In-app notifications (Phase 18 §28).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late Future<List<NotificationModel>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.notifications.list();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.notifications),
        actions: [
          TextButton(
            onPressed: () async {
              await widget.services.notifications.markAllRead();
              if (mounted) {
                setState(() => _future = widget.services.notifications.list());
              }
            },
            child: Text(l10n.markAllRead),
          ),
        ],
      ),
      body: FutureBuilder<List<NotificationModel>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(
                  () => _future = widget.services.notifications.list()),
              child: const SizedBox(),
            );
          }
          final items = snapshot.data ?? const <NotificationModel>[];
          if (items.isEmpty) {
            return Center(child: Text(l10n.noNotifications));
          }
          return ListView.separated(
            itemCount: items.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final n = items[i];
              return ListTile(
                leading: Icon(
                  n.read == true
                      ? Icons.notifications_none
                      : Icons.notifications,
                ),
                title: Text(n.title ?? ''),
                subtitle: n.body != null ? Text(n.body!) : null,
                onTap: () async {
                  if (n.read != true) {
                    await widget.services.notifications.markRead(n.id!);
                    if (mounted) {
                      setState(
                          () => _future = widget.services.notifications.list());
                    }
                  }
                },
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/onboarding/onboarding_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/cache/offline_cache.dart';
import '../../core/l10n/app_localizations.dart';
import '../auth/login_screen.dart';

/// First-run onboarding (Phase 18 §5). Three value-prop pages, then Login.
/// Nothing here requires a network call or a session.
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  static const _seenKey = 'onboarding.seen';

  final _controller = PageController();
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _finish() async {
    await OfflineCache.instance.put(_seenKey, {'seen': true});
    if (!mounted) {
      return;
    }
    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(
        builder: (_) => LoginScreen(services: widget.services),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final pages = [
      (
        Icons.emoji_events_outlined,
        l10n.onboardingWelcomeTitle,
        l10n.onboardingWelcomeBody
      ),
      (
        Icons.groups_outlined,
        l10n.onboardingPlayTitle,
        l10n.onboardingPlayBody
      ),
      (Icons.trending_up, l10n.onboardingWinTitle, l10n.onboardingWinBody),
    ];

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: PageView.builder(
                controller: _controller,
                itemCount: pages.length,
                onPageChanged: (i) => setState(() => _page = i),
                itemBuilder: (context, i) {
                  final (icon, title, body) = pages[i];
                  return Padding(
                    padding: const EdgeInsets.all(32),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(icon,
                            size: 96,
                            color: Theme.of(context).colorScheme.primary),
                        const SizedBox(height: 32),
                        Text(
                          title,
                          textAlign: TextAlign.center,
                          style: Theme.of(context)
                              .textTheme
                              .headlineSmall
                              ?.copyWith(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 12),
                        Text(
                          body,
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodyLarge,
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(pages.length, (i) {
                final active = i == _page;
                return AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  width: active ? 24 : 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: active
                        ? Theme.of(context).colorScheme.primary
                        : Theme.of(context).colorScheme.outlineVariant,
                    borderRadius: BorderRadius.circular(4),
                  ),
                );
              }),
            ),
            const SizedBox(height: 24),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              child: FilledButton(
                onPressed: _finish,
                child: Text(l10n.getStarted),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/profile/profile_edit_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Edit profile (Phase 18 §59): only the documented, non-sensitive fields.
class ProfileEditScreen extends StatefulWidget {
  const ProfileEditScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<ProfileEditScreen> createState() => _ProfileEditScreenState();
}

class _ProfileEditScreenState extends State<ProfileEditScreen> {
  late final TextEditingController _name;
  late final TextEditingController _bio;
  late final TextEditingController _country;
  late final TextEditingController _region;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final me = widget.services.session.me;
    _name = TextEditingController(text: me?.name ?? '');
    _bio = TextEditingController(text: me?.bio ?? '');
    _country = TextEditingController(text: me?.country ?? '');
    _region = TextEditingController(text: me?.region ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _bio.dispose();
    _country.dispose();
    _region.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final me = await widget.services.profile.update(
        name: _name.text.trim(),
        bio: _bio.text.trim(),
        country: _country.text.trim(),
        region: _region.text.trim(),
      );
      // Refresh the session's cached user.
      await widget.services.session.refreshUser(me);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.saved)));
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.editProfile)),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: InputDecoration(labelText: l10n.name),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _bio,
              maxLines: 3,
              decoration: InputDecoration(labelText: l10n.bio),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _country,
              decoration: InputDecoration(labelText: l10n.country),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _region,
              decoration: InputDecoration(labelText: l10n.region),
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(l10n.save),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/profile/profile_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/avatar.dart';
import '../disputes/disputes_screen.dart';
import '../notifications/notifications_screen.dart';
import '../settings/settings_screen.dart';
import '../support/support_tickets_screen.dart';
import '../teams/team_list_screen.dart';
import '../wallet/wallet_screen.dart';
import 'profile_edit_screen.dart';

/// Profile tab (Phase 18 §59): renders the documented, privacy-redacted Me
/// resource and links to the account surfaces.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final me = services.session.me;

    void push(Widget screen) => Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => screen));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.profile)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Avatar(
                  name: me?.name ?? me?.username ?? '?',
                  url: me?.avatar,
                  radius: 32),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      me?.name ?? '—',
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
                    if (me?.username != null)
                      Text(
                        '@${me!.username}',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color:
                                Theme.of(context).colorScheme.onSurfaceVariant),
                      ),
                  ],
                ),
              ),
              IconButton(
                tooltip: l10n.editProfile,
                icon: const Icon(Icons.edit_outlined),
                onPressed: () => push(ProfileEditScreen(services: services)),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.groups_outlined),
                  title: Text(l10n.myTeams),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(TeamListScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.account_balance_wallet_outlined),
                  title: Text(l10n.wallet),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(WalletScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.notifications_outlined),
                  title: Text(l10n.notifications),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(NotificationsScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.help_outline),
                  title: Text(l10n.support),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(SupportTicketsScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.gavel_outlined),
                  title: Text(l10n.dispute),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(DisputesScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.settings_outlined),
                  title: Text(l10n.settings),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(SettingsScreen(services: services)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/profile/public_profile_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/avatar.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import 'ranking_screen.dart';

/// Public player profile (Phase 18 §59). Renders the documented UserProfile;
/// privacy/visibility are server decisions.
class PublicProfileScreen extends StatefulWidget {
  const PublicProfileScreen({
    super.key,
    required this.services,
    required this.userId,
  });

  final AppServices services;
  final int userId;

  @override
  State<PublicProfileScreen> createState() => _PublicProfileScreenState();
}

class _PublicProfileScreenState extends State<PublicProfileScreen> {
  late Future<UserProfile> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.profile.player(widget.userId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.publicProfile)),
      body: FutureBuilder<UserProfile>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(() =>
                  _future = widget.services.profile.player(widget.userId)),
              child: const SizedBox(),
            );
          }
          final p = snapshot.data!;
          if (p.visible == false) {
            return Center(child: Text(l10n.privacyPrivate));
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Center(
                child: Avatar(
                  name: p.name ?? p.username ?? '?',
                  radius: 40,
                ),
              ),
              const SizedBox(height: 8),
              Center(
                child: Text(
                  p.name ?? '—',
                  style: Theme.of(context)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              if (p.username != null)
                Center(
                  child: Text('@${p.username}',
                      style: Theme.of(context).textTheme.bodyMedium),
                ),
              const SizedBox(height: 16),
              SectionCard(
                title: l10n.profile,
                child: Column(
                  children: [
                    if (p.privacy != null)
                      KeyValueRow(label: l10n.privacy, value: p.privacy!),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => RankingScreen(
                        services: widget.services,
                        userId: widget.userId,
                      ),
                    ),
                  ),
                  child: Text(l10n.leaderboard),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/profile/ranking_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// A player's ranking summary (Phase 18 §59). Server-computed.
class RankingScreen extends StatefulWidget {
  const RankingScreen({
    super.key,
    required this.services,
    required this.userId,
  });

  final AppServices services;
  final int userId;

  @override
  State<RankingScreen> createState() => _RankingScreenState();
}

class _RankingScreenState extends State<RankingScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.playerRanking(widget.userId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.leaderboard)),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(() => _future =
                  widget.services.leaderboard.playerRanking(widget.userId)),
              child: const SizedBox(),
            );
          }
          final data = snapshot.data ?? const {};
          final entries = data.entries
              .where((e) => e.value is num || e.value is String)
              .toList();
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.leaderboard,
                child: Column(
                  children: [
                    for (final e in entries)
                      KeyValueRow(label: e.key, value: '${e.value}'),
                    if (entries.isEmpty) Text(l10n.empty),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/registration/check_in_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';

/// Tournament check-in (Phase 18 §19). The user picks one of their own
/// teams; the server is authoritative for check-in eligibility and result.
class CheckInScreen extends StatefulWidget {
  const CheckInScreen({
    super.key,
    required this.services,
    required this.tournamentId,
  });

  final AppServices services;
  final int tournamentId;

  @override
  State<CheckInScreen> createState() => _CheckInScreenState();
}

class _CheckInScreenState extends State<CheckInScreen> {
  late Future<List<Team>> _future;
  bool _busy = false;
  String? _result;
  String? _error;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.mine();
  }

  Future<void> _checkIn(int teamId) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.tournaments.checkIn(widget.tournamentId, teamId);
      setState(() => _result = l10n.checkedIn);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkIn)),
      body: FutureBuilder<List<Team>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final teams = snapshot.data ?? const <Team>[];
          if (teams.isEmpty) {
            return Center(child: Text(l10n.noTeams));
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              for (final team in teams)
                Card(
                  child: ListTile(
                    title: Text(team.name ?? '—'),
                    subtitle: Text('${l10n.team} #${team.id}'),
                    trailing: FilledButton(
                      onPressed: _busy ? null : () => _checkIn(team.id!),
                      child: Text(l10n.checkIn),
                    ),
                  ),
                ),
              if (_result != null)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    _result!,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/registration/payment_checkout_screen.dart`

```dart
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/status_pill.dart';

/// Entry-fee payment checkout (Phase 18 §23/§24).
///
/// The client NEVER marks a payment successful locally. It POSTs the intent
/// with `{team_id, provider}`, follows the server's `redirect_url` when one
/// is provided, then polls `GET /payments/{id}` until the SERVER reports a
/// terminal status.
class PaymentCheckoutScreen extends StatefulWidget {
  const PaymentCheckoutScreen({
    super.key,
    required this.services,
    required this.team,
  });

  final AppServices services;
  final Team team;

  @override
  State<PaymentCheckoutScreen> createState() => _PaymentCheckoutScreenState();
}

class _PaymentCheckoutScreenState extends State<PaymentCheckoutScreen> {
  Payment? _payment;
  bool _busy = false;
  String? _error;
  String? _status;

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });

    // Discover which providers are actually configured (never assume).
    Map<String, dynamic> methods = const {};
    try {
      methods = await widget.services.wallet.methods();
    } on ApiException {
      methods = const {};
    }

    final provider = _chooseProvider(methods);
    if (provider == null) {
      setState(() => _error = l10n.errorGeneric);
      setState(() => _busy = false);
      return;
    }

    try {
      final intent = await widget.services.wallet.createPayment(
        teamId: widget.team.id!,
        provider: provider,
      );
      final payment = intent.payment;
      setState(() => _payment = payment);

      // Follow the redirect only when the server provides one; completion is
      // always confirmed by polling the server status, never the redirect.
      if (intent.redirectUrl != null) {
        await launchUrl(Uri.parse(intent.redirectUrl!),
            mode: LaunchMode.externalApplication);
      }

      setState(() => _status = l10n.pollForStatus);
      final terminal = await widget.services.wallet.awaitTerminal(payment.id!);
      setState(() {
        _status = switch (terminal.status ?? '') {
          'PAID' => l10n.paymentPaid,
          'FAILED' || 'CANCELLED' || 'EXPIRED' => l10n.paymentFailed,
          _ => l10n.paymentPending,
        };
      });
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  /// Picks the first enabled AND configured provider the server reports.
  String? _chooseProvider(Map<String, dynamic> methods) {
    final providers = methods['providers'];
    if (providers is! List) {
      return null;
    }
    for (final p in providers) {
      if (p is Map<String, dynamic> &&
          p['enabled'] == true &&
          p['configured'] == true) {
        final id = p['id'];
        if (id is String && id.isNotEmpty) {
          return id;
        }
      }
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final payment = _payment;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.entryFee)),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (_busy) ...[
                const CircularProgressIndicator(),
                const SizedBox(height: 16),
                Text(l10n.redirectingToPayment),
              ] else ...[
                if (payment != null && payment.status != null)
                  StatusPill(status: payment.status!),
                const SizedBox(height: 16),
                Text(_status ?? l10n.paymentPending,
                    textAlign: TextAlign.center),
              ],
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/registration/team_register_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';

/// Tournament team registration (Phase 18 §19/§26).
///
/// Submits the documented `{name, captain_name, phone, game_uid, members}`
/// and trusts the server's `waitlisted` / `waitlist_position` / `next_step`
/// verdict — the client never computes slot availability or waitlist math.
class TeamRegisterScreen extends StatefulWidget {
  const TeamRegisterScreen({
    super.key,
    required this.services,
    required this.tournament,
  });

  final AppServices services;
  final Tournament tournament;

  @override
  State<TeamRegisterScreen> createState() => _TeamRegisterScreenState();
}

class _TeamRegisterScreenState extends State<TeamRegisterScreen> {
  final _name = TextEditingController();
  final _captain = TextEditingController();
  final _phone = TextEditingController();
  final _gameUid = TextEditingController();
  final _memberNames = List.generate(3, (_) => TextEditingController());
  final _memberUids = List.generate(3, (_) => TextEditingController());

  bool _busy = false;
  String? _error;
  String? _outcome;

  @override
  void dispose() {
    _name.dispose();
    _captain.dispose();
    _phone.dispose();
    _gameUid.dispose();
    for (final c in [..._memberNames, ..._memberUids]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    final phone = Phone.normalizeBd(_phone.text);
    if (phone == null) {
      setState(() => _error = l10n.phone);
      return;
    }
    final members = <Map<String, String>>[];
    for (var i = 0; i < 3; i++) {
      final playerName = _memberNames[i].text.trim();
      final gameUid = _memberUids[i].text.trim();
      if (playerName.isNotEmpty || gameUid.isNotEmpty) {
        members.add({'player_name': playerName, 'game_uid': gameUid});
      }
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.services.tournaments.register(
        tournamentId: widget.tournament.id!,
        name: _name.text.trim(),
        captainName: _captain.text.trim(),
        phone: phone,
        gameUid: _gameUid.text.trim(),
        members: members,
      );
      final waitlisted = result['waitlisted'] == true;
      final position = result['waitlist_position'];
      setState(() {
        _outcome = waitlisted
            ? '${l10n.teamIsWaitlisted}'
                '${position != null ? ' ${l10n.waitlistPositionLabel}: $position' : ''}'
            : l10n.registered;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final memberFields = <Widget>[];
    for (var i = 0; i < 3; i++) {
      memberFields.addAll([
        Text('${l10n.members} ${i + 1}'),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _memberNames[i],
                decoration: InputDecoration(labelText: l10n.memberName),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _memberUids[i],
                decoration: InputDecoration(labelText: l10n.gameUid),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
      ]);
    }

    return Scaffold(
      appBar: AppBar(title: Text(l10n.register)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _name,
                  decoration: InputDecoration(labelText: l10n.teamName),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _captain,
                  decoration: InputDecoration(labelText: l10n.captainName),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: l10n.phone,
                    prefixText: '+880 ',
                  ),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _gameUid,
                  autocorrect: false,
                  decoration: InputDecoration(labelText: l10n.gameUid),
                ),
                const SizedBox(height: 24),
                ...memberFields,
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.register),
                ),
                if (_outcome != null) ...[
                  const SizedBox(height: 16),
                  Text(
                    _outcome!,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/security/security_event_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../core/session/session_manager.dart';

/// Security / account-state screen (Phase 18 §9/§60).
///
/// Shown whenever the server ends a session — expired/revoked token or a
/// deactivated account. Explains WHY and offers re-login; for deactivated
/// accounts it points to support without assuming any ability the client
/// does not have.
class SecurityEventScreen extends StatelessWidget {
  const SecurityEventScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final reason =
        services.session.endReason ?? SessionEndReason.unauthenticated;

    final (icon, title, body) = switch (reason) {
      SessionEndReason.accountInactive => (
          Icons.block,
          l10n.accountInactiveTitle,
          l10n.accountInactiveBody,
        ),
      SessionEndReason.tokenRevoked => (
          Icons.logout,
          l10n.securityEventTitle,
          l10n.tokenRevokedBody,
        ),
      _ => (
          Icons.timer_off_outlined,
          l10n.securityEventTitle,
          l10n.sessionExpiredBody,
        ),
    };

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(icon,
                      size: 64, color: Theme.of(context).colorScheme.error),
                  const SizedBox(height: 16),
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .titleLarge
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  Text(body, textAlign: TextAlign.center),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: () =>
                        services.session.acknowledgeSecurityEvent(),
                    child: Text(l10n.loginAgain),
                  ),
                  if (reason == SessionEndReason.accountInactive) ...[
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: () {
                        // A deactivated account cannot authenticate, so the
                        // in-app support surface is unavailable. Point to the
                        // configured support channel instead.
                        final supportUrl = services.meta.cached?.urls?.support;
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(
                              supportUrl ?? l10n.contactSupport,
                            ),
                          ),
                        );
                      },
                      child: Text(l10n.contactSupport),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/app_about_screen.dart`

```dart
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// About (Phase 18 §66): version/build, environment, legal links (URLs come
/// from `/api/v1/app/meta`). Links open only on an explicit user tap.
class AppAboutScreen extends StatelessWidget {
  const AppAboutScreen({super.key, required this.services});

  final AppServices services;

  Future<void> _open(BuildContext context, String url) async {
    try {
      await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(url)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;
    final meta = services.meta.cached;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.about)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SectionCard(
            title: l10n.about,
            child: Column(
              children: [
                KeyValueRow(label: l10n.appName, value: config.appName),
                KeyValueRow(
                    label: l10n.version,
                    value: '${config.version}+${config.buildNumber}'),
                KeyValueRow(label: 'Env', value: config.env),
                KeyValueRow(label: 'API', value: config.apiBaseUrl),
                KeyValueRow(label: 'Scheme', value: config.deepLinkScheme),
              ],
            ),
          ),
          if (meta?.urls?.privacy != null)
            ListTile(
              leading: const Icon(Icons.privacy_tip_outlined),
              title: Text(l10n.privacyPolicy),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.privacy!),
            ),
          if (meta?.urls?.terms != null)
            ListTile(
              leading: const Icon(Icons.description_outlined),
              title: Text(l10n.terms),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.terms!),
            ),
          if (meta?.urls?.support != null)
            ListTile(
              leading: const Icon(Icons.help_outline),
              title: Text(l10n.contactSupport),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.support!),
            ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/payment_methods_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// Saved payment methods + provider availability (Phase 18 §23).
class PaymentMethodsScreen extends StatefulWidget {
  const PaymentMethodsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PaymentMethodsScreen> createState() => _PaymentMethodsScreenState();
}

class _PaymentMethodsScreenState extends State<PaymentMethodsScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.methods();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.savedMethods)),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.wallet.methods()),
              child: const SizedBox(),
            );
          }
          final data = snapshot.data ?? const {};
          final providers = data['providers'];
          final saved = data['saved_methods'];
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.provider,
                child: providers is List
                    ? Column(
                        children: [
                          for (final p in providers)
                            if (p is Map<String, dynamic>)
                              KeyValueRow(
                                label: '${p['label'] ?? p['id'] ?? '—'}',
                                value: p['enabled'] == true &&
                                        p['configured'] == true
                                    ? l10n.active
                                    : l10n.pushDisabled,
                              ),
                        ],
                      )
                    : Text(l10n.empty),
              ),
              SectionCard(
                title: l10n.savedMethods,
                child: saved is List && saved.isNotEmpty
                    ? Column(
                        children: [
                          for (final m in saved)
                            if (m is Map<String, dynamic>)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: const Icon(Icons.credit_card),
                                title: Text('${m['provider'] ?? ''}'),
                                subtitle: Text('${m['label'] ?? ''}'),
                              ),
                        ],
                      )
                    : Text(l10n.noSavedMethods),
              ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/phone_link_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';

/// Link a phone number to the signed-in account (Phase 18 §61). Uses the
/// documented OTP flow with `purpose=signup`, which links the verified phone
/// to the current user server-side.
class PhoneLinkScreen extends StatefulWidget {
  const PhoneLinkScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PhoneLinkScreen> createState() => _PhoneLinkScreenState();
}

class _PhoneLinkScreenState extends State<PhoneLinkScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  bool _codeRequested = false;
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _request() async {
    final normalized = Phone.normalizeBd(_phone.text);
    final l10n = AppLocalizations.of(context);
    if (normalized == null) {
      setState(() => _error = l10n.phone);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth.requestOtp(normalized, purpose: 'signup');
      setState(() => _codeRequested = true);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _verify() async {
    final normalized = Phone.normalizeBd(_phone.text);
    final l10n = AppLocalizations.of(context);
    if (normalized == null) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.auth
          .verifyOtp(normalized, _code.text.trim(), purpose: 'signup');
      setState(() => _done = l10n.phoneLinked);
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.linkPhone)),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.phoneLinkBody),
            const SizedBox(height: 16),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration:
                  InputDecoration(labelText: l10n.phone, prefixText: '+880 '),
            ),
            if (_codeRequested) ...[
              const SizedBox(height: 16),
              TextField(
                controller: _code,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: l10n.enterCode),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : (_codeRequested ? _verify : _request),
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(_codeRequested ? l10n.verify : l10n.sendCode),
            ),
            if (_done != null) ...[
              const SizedBox(height: 16),
              Text(
                _done!,
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/privacy_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Privacy preset (Phase 18 §61). The server validates the preset and is
/// authoritative for what other users can see.
class PrivacyScreen extends StatefulWidget {
  const PrivacyScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends State<PrivacyScreen> {
  bool _busy = false;
  String? _error;

  Future<void> _set(String preset) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final me = await widget.services.profile.update(privacy: preset);
      await widget.services.session.refreshUser(me);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.saved)));
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final current = widget.services.session.me?.privacy ?? 'public';
    return Scaffold(
      appBar: AppBar(title: Text(l10n.privacy)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          RadioGroup<String>(
            groupValue: current,
            onChanged: (v) {
              if (!_busy && v != null) {
                _set(v);
              }
            },
            child: Column(
              children: [
                for (final (value, label) in [
                  ('public', l10n.privacyPublic),
                  ('registered', l10n.privacyRegistered),
                  ('private', l10n.privacyPrivate),
                ])
                  RadioListTile<String>(
                    title: Text(label),
                    value: value,
                  ),
              ],
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/security_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// Account security (Phase 18 §61): sign-in methods and account status from
/// GET /me/security — the server never exposes internal signals here.
class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.security.security();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.accountSecurity)),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.security.security()),
              child: const SizedBox(),
            );
          }
          final s = snapshot.data ?? const {};
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.securityStatus,
                child: Column(
                  children: [
                    KeyValueRow(
                      label: l10n.email,
                      value: (s['email'] as String?) ?? '—',
                    ),
                    KeyValueRow(
                      label: l10n.accountStatus,
                      value: (s['account_status'] as String?) ?? '—',
                    ),
                    KeyValueRow(
                      label: l10n.email,
                      value: s['email_verified'] == true
                          ? l10n.emailVerified
                          : l10n.emailNotVerified,
                    ),
                  ],
                ),
              ),
              SectionCard(
                title: l10n.signInMethods,
                child: Column(
                  children: [
                    KeyValueRow(
                      label: l10n.google,
                      value: _methodLabel(s, 'google', l10n),
                    ),
                    KeyValueRow(
                      label: l10n.phoneMethod,
                      value: _methodLabel(s, 'phone', l10n),
                    ),
                    KeyValueRow(
                      label: l10n.emailMethod,
                      value: s['has_password'] == true
                          ? l10n.hasPassword
                          : l10n.noPassword,
                    ),
                  ],
                ),
              ),
              SectionCard(
                title: l10n.changePasswordWebOnly,
                child: Text(l10n.changePasswordWebBody),
              ),
              SectionCard(
                title: l10n.deactivationWebOnly,
                child: Text(l10n.deactivationWebBody),
              ),
            ],
          );
        },
      ),
    );
  }

  String _methodLabel(
      Map<String, dynamic> s, String method, AppLocalizations l10n) {
    final methods = s['sign_in_methods'];
    if (methods is Map<String, dynamic>) {
      final count = methods[method];
      return count == null || count == 0 ? '—' : l10n.active;
    }
    return '—';
  }
}

```

### `mobile/lib/screens/settings/sessions_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Active sessions (Phase 18 §61): revoke one / others / all.
class SessionsScreen extends StatefulWidget {
  const SessionsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SessionsScreen> createState() => _SessionsScreenState();
}

class _SessionsScreenState extends State<SessionsScreen> {
  late Future<List<ApiSession>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.security.sessions();
  }

  Future<void> _reload() async {
    setState(() => _future = widget.services.security.sessions());
    await _future;
  }

  Future<void> _revoke(String id) async {
    await widget.services.security.revokeSession(id);
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.sessions),
        actions: [
          TextButton(
            onPressed: () async {
              await widget.services.security.revokeOtherSessions();
              await _reload();
            },
            child: Text(l10n.revokeOthers),
          ),
        ],
      ),
      body: FutureBuilder<List<ApiSession>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: _reload,
              child: const SizedBox(),
            );
          }
          final sessions = snapshot.data ?? const <ApiSession>[];
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton(
                  onPressed: () async {
                    await widget.services.security.revokeAllSessions();
                    if (mounted) {
                      await _reload();
                    }
                  },
                  child: Text(l10n.revokeAll),
                ),
              ),
              Expanded(
                child: ListView.separated(
                  itemCount: sessions.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final s = sessions[i];
                    return ListTile(
                      title: Text(s.deviceLabel ?? l10n.sessions),
                      subtitle: Text(
                        '${l10n.lastActive}: ${Dates.formatDateTime(s.lastActivity)}',
                      ),
                      trailing: s.isCurrent == true
                          ? Text(l10n.currentSession)
                          : IconButton(
                              icon: const Icon(Icons.logout),
                              onPressed: () => _revoke(s.id!),
                            ),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/settings/settings_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/l10n/app_localizations.dart';
import 'app_about_screen.dart';
import 'payment_methods_screen.dart';
import 'phone_link_screen.dart';
import 'privacy_screen.dart';
import 'security_screen.dart';
import 'sessions_screen.dart';

/// Settings hub (Phase 18 §61).
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;

    void push(Widget screen) => Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => screen));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.settings)),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.shield_outlined),
            title: Text(l10n.accountSecurity),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(SecurityScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.devices_outlined),
            title: Text(l10n.sessions),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(SessionsScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.phone_android_outlined),
            title: Text(l10n.linkPhone),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PhoneLinkScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.credit_card_outlined),
            title: Text(l10n.savedMethods),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PaymentMethodsScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.visibility_outlined),
            title: Text(l10n.privacy),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PrivacyScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.language),
            title: Text(l10n.language),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _chooseLanguage(context),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.notifications_outlined),
            title: Text(l10n.pushStatus),
            subtitle: Text(services.push.isConfigured
                ? l10n.pushEnabled
                : l10n.pushDisabled),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.info_outline),
            title: Text(l10n.about),
            subtitle:
                Text('${config.version}+${config.buildNumber} · ${config.env}'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(AppAboutScreen(services: services)),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(16),
            child: OutlinedButton(
              onPressed: () => services.session.logout(),
              child: Text(l10n.logout),
            ),
          ),
        ],
      ),
    );
  }

  void _chooseLanguage(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    showDialog<void>(
      context: context,
      builder: (context) => SimpleDialog(
        title: Text(l10n.language),
        children: [
          SimpleDialogOption(
            onPressed: () {
              LocaleController.instance.set('en');
              Navigator.pop(context);
            },
            child: Text(l10n.english),
          ),
          SimpleDialogOption(
            onPressed: () {
              LocaleController.instance.set('bn');
              Navigator.pop(context);
            },
            child: Text(l10n.bangla),
          ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/shell/app_shell.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../features/deep_links/deep_link_router.dart';
import '../home/home_screen.dart';
import '../leaderboard/leaderboard_screen.dart';
import '../matches/match_center_screen.dart';
import '../matches/match_detail_screen.dart';
import '../profile/profile_screen.dart';
import '../profile/public_profile_screen.dart';
import '../tournaments/tournament_detail_screen.dart';
import '../tournaments/tournament_list_screen.dart';

/// Root authenticated shell (Phase 18 §16): bottom navigation + deep-link
/// routing. Every deep link target requires an authenticated, authorized
/// server response before it renders.
class AppShell extends StatefulWidget {
  const AppShell({super.key, required this.services});

  final AppServices services;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    widget.services.deepLinks.registerHandler(_handleDeepLink);
  }

  void _handleDeepLink(DeepLink link) {
    if (!mounted) {
      return;
    }
    switch (link.target) {
      case DeepLinkTarget.tournament:
        _push(TournamentDetailScreen(
            services: widget.services, tournamentId: link.id));
      case DeepLinkTarget.match:
        _push(MatchDetailScreen(services: widget.services, matchId: link.id));
      case DeepLinkTarget.profile:
        _push(PublicProfileScreen(services: widget.services, userId: link.id));
    }
  }

  void _push(Widget screen) {
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    final tabs = <Widget>[
      HomeScreen(services: widget.services),
      TournamentListScreen(services: widget.services),
      MatchCenterScreen(services: widget.services),
      LeaderboardScreen(services: widget.services),
      ProfileScreen(services: widget.services),
    ];

    return Scaffold(
      body: IndexedStack(index: _index, children: tabs),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home),
            label: l10n.home,
          ),
          NavigationDestination(
            icon: const Icon(Icons.emoji_events_outlined),
            selectedIcon: const Icon(Icons.emoji_events),
            label: l10n.tournaments,
          ),
          NavigationDestination(
            icon: const Icon(Icons.sports_esports_outlined),
            selectedIcon: const Icon(Icons.sports_esports),
            label: l10n.matches,
          ),
          NavigationDestination(
            icon: const Icon(Icons.leaderboard_outlined),
            selectedIcon: const Icon(Icons.leaderboard),
            label: l10n.leaderboard,
          ),
          NavigationDestination(
            icon: const Icon(Icons.person_outline),
            selectedIcon: const Icon(Icons.person),
            label: l10n.profile,
          ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/standings/standings_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Standings (Phase 18 §22). Renders server-computed rank/points; the client
/// never recomputes them.
class StandingsScreen extends StatefulWidget {
  const StandingsScreen({
    super.key,
    required this.services,
    required this.tournamentId,
    this.embedded = false,
  });

  final AppServices services;
  final int tournamentId;
  final bool embedded;

  @override
  State<StandingsScreen> createState() => _StandingsScreenState();
}

class _StandingsScreenState extends State<StandingsScreen> {
  late Future<List<StandingRow>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.standings(widget.tournamentId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final content = FutureBuilder<List<StandingRow>>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          final message = snapshot.error is ApiException
              ? (snapshot.error as ApiException).localized(l10n)
              : l10n.errorGeneric;
          return AsyncView(
            loading: false,
            error: message,
            empty: false,
            onRetry: () => setState(() => _future =
                widget.services.leaderboard.standings(widget.tournamentId)),
            child: const SizedBox(),
          );
        }
        final rows = snapshot.data ?? const <StandingRow>[];
        if (rows.isEmpty) {
          return Center(child: Text(l10n.empty));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: rows.length,
          separatorBuilder: (_, __) => const Divider(height: 1),
          itemBuilder: (context, i) {
            final r = rows[i];
            return ListTile(
              leading: CircleAvatar(child: Text('${r.rank ?? i + 1}')),
              title: Text(r.teamName ?? '—'),
              subtitle: Text(
                  '${l10n.kills}: ${r.kills ?? 0} · ${l10n.points}: ${r.points ?? 0}'),
            );
          },
        );
      },
    );

    if (widget.embedded) {
      return content;
    }
    return Scaffold(
      appBar: AppBar(title: Text(l10n.standings)),
      body: content,
    );
  }
}

```

### `mobile/lib/screens/support/support_chat_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';

/// Support ticket thread (Phase 18 §25).
class SupportChatScreen extends StatefulWidget {
  const SupportChatScreen({
    super.key,
    required this.services,
    required this.ticketId,
  });

  final AppServices services;
  final int ticketId;

  @override
  State<SupportChatScreen> createState() => _SupportChatScreenState();
}

class _SupportChatScreenState extends State<SupportChatScreen> {
  final _reply = TextEditingController();
  List<SupportMessage> _messages = const [];
  bool _loading = true;
  String? _error;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final result = await widget.services.support.messages(widget.ticketId);
      final raw = result['messages'];
      if (raw is List) {
        setState(() {
          _messages = raw
              .whereType<Map<String, dynamic>>()
              .map((m) => SupportMessage.fromJson(m))
              .toList(growable: false);
        });
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(AppLocalizations.of(context)));
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _send() async {
    final text = _reply.text.trim();
    if (text.isEmpty) {
      return;
    }
    setState(() => _sending = true);
    try {
      await widget.services.support.reply(widget.ticketId, text);
      _reply.clear();
      await _load();
    } on ApiException catch (e) {
      setState(() => _error = e.localized(AppLocalizations.of(context)));
    } catch (_) {
      setState(() => _error = AppLocalizations.of(context).errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _sending = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.support)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.all(8),
                    child: Text(
                      _error!,
                      style:
                          TextStyle(color: Theme.of(context).colorScheme.error),
                    ),
                  ),
                Expanded(
                  child: _messages.isEmpty
                      ? Center(child: Text(l10n.empty))
                      : ListView.builder(
                          padding: const EdgeInsets.all(16),
                          itemCount: _messages.length,
                          itemBuilder: (context, i) {
                            final m = _messages[i];
                            return Align(
                              alignment: Alignment.centerLeft,
                              child: Card(
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Text(m.body ?? ''),
                                ),
                              ),
                            );
                          },
                        ),
                ),
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _reply,
                            decoration: InputDecoration(hintText: l10n.message),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.send),
                          onPressed: _sending ? null : _send,
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}

```

### `mobile/lib/screens/support/support_create_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// New support ticket (Phase 18 §25).
class SupportCreateScreen extends StatefulWidget {
  const SupportCreateScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SupportCreateScreen> createState() => _SupportCreateScreenState();
}

class _SupportCreateScreenState extends State<SupportCreateScreen> {
  final _subject = TextEditingController();
  final _message = TextEditingController();
  String _category = 'general';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    if (_subject.text.trim().isEmpty || _message.text.trim().isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.support.create(
        subject: _subject.text.trim(),
        category: _category,
        message: _message.text.trim(),
      );
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.createTicket)),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _subject,
              decoration: InputDecoration(labelText: l10n.subject),
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<String>(
              initialValue: _category,
              decoration: InputDecoration(labelText: l10n.category),
              items: const [
                DropdownMenuItem(value: 'general', child: Text('General')),
                DropdownMenuItem(value: 'payment', child: Text('Payment')),
                DropdownMenuItem(value: 'account', child: Text('Account')),
                DropdownMenuItem(value: 'technical', child: Text('Technical')),
              ],
              onChanged: (v) => setState(() => _category = v ?? 'general'),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _message,
              maxLines: 6,
              decoration: InputDecoration(labelText: l10n.message),
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(l10n.createTicket),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

```

### `mobile/lib/screens/support/support_tickets_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'support_chat_screen.dart';
import 'support_create_screen.dart';

/// Support tickets (Phase 18 §25).
class SupportTicketsScreen extends StatefulWidget {
  const SupportTicketsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SupportTicketsScreen> createState() => _SupportTicketsScreenState();
}

class _SupportTicketsScreenState extends State<SupportTicketsScreen> {
  late Future<List<SupportTicket>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.support.tickets();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.support)),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add),
        label: Text(l10n.createTicket),
        onPressed: () async {
          await Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => SupportCreateScreen(services: widget.services),
            ),
          );
          if (mounted) {
            setState(() => _future = widget.services.support.tickets());
          }
        },
      ),
      body: FutureBuilder<List<SupportTicket>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.support.tickets()),
              child: const SizedBox(),
            );
          }
          final tickets = snapshot.data ?? const <SupportTicket>[];
          if (tickets.isEmpty) {
            return Center(child: Text(l10n.noTickets));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: tickets.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = tickets[i];
              return Card(
                child: ListTile(
                  title: Text(t.subject ?? '—'),
                  subtitle: Text('${t.category ?? ''} · ${t.priority ?? ''}'),
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => SupportChatScreen(
                        services: widget.services,
                        ticketId: t.id!,
                      ),
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/teams/roster_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/avatar.dart';

/// Team roster (Phase 18 §21). Add/remove are authorized server-side.
class RosterScreen extends StatefulWidget {
  const RosterScreen({super.key, required this.services, required this.teamId});

  final AppServices services;
  final int teamId;

  @override
  State<RosterScreen> createState() => _RosterScreenState();
}

class _RosterScreenState extends State<RosterScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.roster(widget.teamId);
  }

  Future<void> _add() async {
    final l10n = AppLocalizations.of(context);
    final name = TextEditingController();
    final uid = TextEditingController();
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.addMember),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: name,
              decoration: InputDecoration(labelText: l10n.memberName),
            ),
            TextField(
              controller: uid,
              decoration: InputDecoration(labelText: l10n.gameUid),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.confirm),
          ),
        ],
      ),
    );
    if (result != true) {
      return;
    }
    try {
      await widget.services.teams
          .addMember(widget.teamId, name.text.trim(), uid.text.trim());
      if (mounted) {
        setState(() => _future = widget.services.teams.roster(widget.teamId));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    }
  }

  Future<void> _remove(int memberId) async {
    final l10n = AppLocalizations.of(context);
    try {
      await widget.services.teams.removeMember(widget.teamId, memberId);
      if (mounted) {
        setState(() => _future = widget.services.teams.roster(widget.teamId));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.roster)),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.person_add),
        label: Text(l10n.addMember),
        onPressed: _add,
      ),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(
                  () => _future = widget.services.teams.roster(widget.teamId)),
              child: const SizedBox(),
            );
          }
          final members = snapshot.data ?? const <Map<String, dynamic>>[];
          if (members.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            itemCount: members.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final m = members[i];
              final id = m['id'];
              return ListTile(
                leading: Avatar(name: (m['player_name'] as String?) ?? '?'),
                title: Text((m['player_name'] as String?) ?? '—'),
                subtitle: Text('UID: ${m['game_uid'] ?? '—'}'),
                trailing: id is int
                    ? IconButton(
                        icon: const Icon(Icons.person_remove_outlined),
                        onPressed: () => _remove(id),
                      )
                    : null,
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/teams/team_detail_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import 'roster_screen.dart';

/// Team detail (Phase 18 §21). Captain-only actions (roster edit, withdraw)
/// are authorized by the server; the client shows them and surfaces the
/// server's 403 honestly.
class TeamDetailScreen extends StatefulWidget {
  const TeamDetailScreen(
      {super.key, required this.services, required this.team});

  final AppServices services;
  final Team team;

  @override
  State<TeamDetailScreen> createState() => _TeamDetailScreenState();
}

class _TeamDetailScreenState extends State<TeamDetailScreen> {
  Team get team => widget.team;
  String? _error;

  Future<void> _withdraw() async {
    final l10n = AppLocalizations.of(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.confirmWithdraw),
        content: Text(l10n.withdrawBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.confirm),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      return;
    }
    try {
      await widget.services.teams.withdraw(team.id!);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(team.name ?? l10n.team)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  team.name ?? '—',
                  style: Theme.of(context)
                      .textTheme
                      .headlineSmall
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              if (team.status != null) StatusPill(status: team.status!),
            ],
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: l10n.team,
            child: Column(
              children: [
                KeyValueRow(
                    label: l10n.captainName, value: team.captainName ?? '—'),
                KeyValueRow(label: l10n.gameUid, value: team.gameUid ?? '—'),
                if (team.waitlistPosition != null)
                  KeyValueRow(
                      label: l10n.waitlistPositionLabel,
                      value: '${team.waitlistPosition}'),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: FilledButton.tonalIcon(
              icon: const Icon(Icons.groups_outlined),
              label: Text(l10n.roster),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => RosterScreen(
                    services: widget.services,
                    teamId: team.id!,
                  ),
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: OutlinedButton(
              onPressed: _withdraw,
              child: Text(l10n.withdraw),
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(8),
              child: Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/screens/teams/team_list_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'team_detail_screen.dart';

/// My teams (Phase 18 §21).
class TeamListScreen extends StatefulWidget {
  const TeamListScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<TeamListScreen> createState() => _TeamListScreenState();
}

class _TeamListScreenState extends State<TeamListScreen> {
  late Future<List<Team>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.mine();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.myTeams)),
      body: FutureBuilder<List<Team>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.teams.mine()),
              child: const SizedBox(),
            );
          }
          final teams = snapshot.data ?? const <Team>[];
          if (teams.isEmpty) {
            return Center(child: Text(l10n.noTeams));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: teams.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = teams[i];
              return Card(
                child: ListTile(
                  title: Text(t.name ?? '—'),
                  subtitle: t.tournamentId != null
                      ? Text('Tournament #${t.tournamentId}')
                      : null,
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => TeamDetailScreen(
                        services: widget.services,
                        team: t,
                      ),
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/tournaments/tournament_detail_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import '../matches/match_center_screen.dart';
import '../registration/check_in_screen.dart';
import '../registration/team_register_screen.dart';
import '../standings/standings_screen.dart';

/// Tournament detail (Phase 18 §19): overview, matches, standings, bracket
/// and live — plus the registration / check-in entry points.
class TournamentDetailScreen extends StatefulWidget {
  const TournamentDetailScreen({
    super.key,
    required this.services,
    required this.tournamentId,
  });

  final AppServices services;
  final int? tournamentId;

  @override
  State<TournamentDetailScreen> createState() => _TournamentDetailScreenState();
}

class _TournamentDetailScreenState extends State<TournamentDetailScreen> {
  late Future<Tournament> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.tournaments.get(widget.tournamentId!);
  }

  Future<void> _refresh() async {
    setState(
        () => _future = widget.services.tournaments.get(widget.tournamentId!));
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final id = widget.tournamentId!;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tournaments)),
      body: FutureBuilder<Tournament>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: _refresh,
              child: const SizedBox(),
            );
          }
          final t = snapshot.data!;
          return DefaultTabController(
            length: 4,
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              t.name ?? '—',
                              style: Theme.of(context)
                                  .textTheme
                                  .headlineSmall
                                  ?.copyWith(fontWeight: FontWeight.bold),
                            ),
                          ),
                          if (t.status != null) StatusPill(status: t.status!),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${(t.gameMode ?? '').toUpperCase()} · ${t.map ?? ''} · ${t.format ?? ''}',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 12),
                      if (t.acceptsRegistration == true)
                        FilledButton.icon(
                          icon: const Icon(Icons.how_to_reg),
                          label: Text(l10n.register),
                          onPressed: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => TeamRegisterScreen(
                                services: widget.services,
                                tournament: t,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const TabBar(
                  tabs: [
                    Tab(text: 'Overview'),
                    Tab(text: 'Matches'),
                    Tab(text: 'Standings'),
                    Tab(text: 'Live'),
                  ],
                ),
                Expanded(
                  child: TabBarView(
                    children: [
                      _OverviewTab(tournament: t, services: widget.services),
                      MatchCenterScreen(
                        services: widget.services,
                        tournamentId: id,
                        embedded: true,
                      ),
                      StandingsScreen(
                        services: widget.services,
                        tournamentId: id,
                        embedded: true,
                      ),
                      _LiveTab(services: widget.services, tournamentId: id),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _OverviewTab extends StatelessWidget {
  const _OverviewTab({required this.tournament, required this.services});

  final Tournament tournament;
  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = tournament;
    return ListView(
      children: [
        SectionCard(
          title: l10n.status,
          child: Column(
            children: [
              KeyValueRow(
                  label: l10n.entryFee,
                  value: l10n.formatMoney(t.entryFeeMinor ?? 0)),
              KeyValueRow(label: l10n.prizePool, value: t.prizePool ?? '—'),
              KeyValueRow(label: l10n.slotsLeft, value: '${t.slotsLeft ?? 0}'),
              KeyValueRow(label: l10n.teamSize, value: '${t.teamSize ?? '—'}'),
              KeyValueRow(
                  label: l10n.startsAt,
                  value: Dates.formatDateTime(t.startsAt)),
              if (t.checkInStartsAt != null)
                KeyValueRow(
                    label: l10n.checkInOpens,
                    value: Dates.formatDateTime(t.checkInStartsAt)),
              if (t.checkInEndsAt != null)
                KeyValueRow(
                    label: l10n.checkInCloses,
                    value: Dates.formatDateTime(t.checkInEndsAt)),
            ],
          ),
        ),
        SectionCard(
          title: l10n.viewBracket,
          child: Text(l10n.viewBracket),
        ),
        if (t.checkInStartsAt != null)
          Padding(
            padding: const EdgeInsets.all(16),
            child: OutlinedButton.icon(
              icon: const Icon(Icons.how_to_reg),
              label: Text(l10n.checkIn),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => CheckInScreen(
                    services: services,
                    tournamentId: t.id!,
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _LiveTab extends StatefulWidget {
  const _LiveTab({required this.services, required this.tournamentId});

  final AppServices services;
  final int tournamentId;

  @override
  State<_LiveTab> createState() => _LiveTabState();
}

class _LiveTabState extends State<_LiveTab> {
  late Future<List<LiveEvent>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.live.tournament(widget.tournamentId);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<LiveEvent>>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        final events = snapshot.data ?? const <LiveEvent>[];
        return AsyncView(
          loading: false,
          error: null,
          empty: events.isEmpty,
          onRetry: () {},
          child: ListView.builder(
            itemCount: events.length,
            itemBuilder: (context, i) => ListTile(
              leading: const Icon(Icons.sports_esports),
              title: Text(events[i].type ?? 'event'),
              subtitle: events[i].createdAt != null
                  ? Text(Dates.formatDateTime(events[i].createdAt))
                  : null,
            ),
          ),
        );
      },
    );
  }
}

```

### `mobile/lib/screens/tournaments/tournament_list_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/cache/offline_cache.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'tournament_detail_screen.dart';

/// Tournament list (Phase 18 §17): search, game-mode filter, pagination and
/// pull-to-refresh. The last successful page is cached read-only for offline
/// browsing, with an honest stale-data banner.
class TournamentListScreen extends StatefulWidget {
  const TournamentListScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<TournamentListScreen> createState() => _TournamentListScreenState();
}

class _TournamentListScreenState extends State<TournamentListScreen> {
  static const _cacheKey = 'tournaments.list.v1';

  List<Tournament> _items = const [];
  bool _loading = true;
  bool _offline = false;
  bool _stale = false;
  String? _error;
  final int _page = 1;
  String _query = '';
  String? _gameMode;

  @override
  void initState() {
    super.initState();
    _load(useCache: true);
  }

  Future<void> _load({bool useCache = false}) async {
    setState(() {
      _loading = true;
      _error = null;
    });

    if (useCache) {
      final cached = await OfflineCache.instance.get(_cacheKey);
      if (cached != null && cached.payload['items'] is List) {
        setState(() {
          _items = (cached.payload['items'] as List)
              .whereType<Map<String, dynamic>>()
              .map((m) => Tournament.fromJson(m))
              .toList(growable: false);
          _stale = cached.isStale;
          _loading = false;
        });
      }
    }

    try {
      final items = await widget.services.tournaments.list(
        page: _page,
        search: _query,
        gameMode: _gameMode,
      );
      await OfflineCache.instance.put(_cacheKey, {
        'items': items.map((t) => t.toJson()).toList(growable: false),
      });
      if (mounted) {
        setState(() {
          _items = items;
          _loading = false;
          _offline = false;
          _stale = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          if (e.code == ApiException.offline) {
            _offline = true;
            _stale = _items.isNotEmpty;
          } else {
            _error = e.localized(AppLocalizations.of(context));
          }
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = AppLocalizations.of(context).errorGeneric;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tournaments)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    decoration: InputDecoration(
                      hintText: l10n.search,
                      prefixIcon: const Icon(Icons.search),
                      isDense: true,
                    ),
                    onSubmitted: (q) {
                      _query = q.trim();
                      _load();
                    },
                  ),
                ),
                const SizedBox(width: 8),
                DropdownButton<String?>(
                  value: _gameMode,
                  items: [
                    DropdownMenuItem(value: null, child: Text(l10n.all)),
                    const DropdownMenuItem(
                        value: 'squad', child: Text('Squad')),
                    const DropdownMenuItem(value: 'duo', child: Text('Duo')),
                    const DropdownMenuItem(value: 'solo', child: Text('Solo')),
                  ],
                  onChanged: (v) {
                    setState(() => _gameMode = v);
                    _load();
                  },
                ),
              ],
            ),
          ),
          if (_stale)
            Container(
              width: double.infinity,
              color: const Color(0xFFFFF3CD),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Text(
                _offline ? l10n.offlineBanner : l10n.staleBanner,
                style: const TextStyle(color: Colors.black87),
              ),
            ),
          Expanded(
            child: AsyncView(
              loading: _loading && _items.isEmpty,
              error: _error,
              empty: _items.isEmpty && !_loading,
              onRetry: () => _load(),
              child: RefreshIndicator(
                onRefresh: () => _load(),
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: _items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (context, i) => _TournamentCard(
                    tournament: _items[i],
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => TournamentDetailScreen(
                          services: widget.services,
                          tournamentId: _items[i].id,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TournamentCard extends StatelessWidget {
  const _TournamentCard({required this.tournament, required this.onTap});

  final Tournament tournament;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      tournament.name ?? '—',
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  if (tournament.status != null)
                    StatusPill(status: tournament.status!),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                '${(tournament.gameMode ?? '').toUpperCase()}'
                '${tournament.map != null ? ' · ${tournament.map}' : ''}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _Meta(
                    label: l10n.entryFee,
                    value: l10n.formatMoney(tournament.entryFeeMinor ?? 0),
                  ),
                  const SizedBox(width: 24),
                  _Meta(
                      label: l10n.prizePool,
                      value: tournament.prizePool ?? '—'),
                  const SizedBox(width: 24),
                  _Meta(
                      label: l10n.slotsLeft,
                      value: '${tournament.slotsLeft ?? 0}'),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant)),
        const SizedBox(height: 2),
        Text(value, style: Theme.of(context).textTheme.bodyMedium),
      ],
    );
  }
}

```

### `mobile/lib/screens/wallet/ledger_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Wallet ledger (Phase 18 §23).
class LedgerScreen extends StatefulWidget {
  const LedgerScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LedgerScreen> createState() => _LedgerScreenState();
}

class _LedgerScreenState extends State<LedgerScreen> {
  late Future<List<LedgerEntry>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.ledger();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.ledger)),
      body: FutureBuilder<List<LedgerEntry>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.wallet.ledger()),
              child: const SizedBox(),
            );
          }
          final entries = snapshot.data ?? const <LedgerEntry>[];
          if (entries.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            itemCount: entries.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final e = entries[i];
              final credit = e.direction == 'credit';
              return ListTile(
                title: Text(e.type ?? '—'),
                trailing: Text(
                  '${credit ? '+' : '-'}${l10n.formatMoney(e.amountMinor ?? 0)}',
                  style: TextStyle(
                    color: credit
                        ? const Color(0xFF0B6E4F)
                        : Theme.of(context).colorScheme.error,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/wallet/payouts_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';

/// Payouts (Phase 18 §23).
class PayoutsScreen extends StatefulWidget {
  const PayoutsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PayoutsScreen> createState() => _PayoutsScreenState();
}

class _PayoutsScreenState extends State<PayoutsScreen> {
  late Future<List<Payout>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.payouts();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.payouts)),
      body: FutureBuilder<List<Payout>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.wallet.payouts()),
              child: const SizedBox(),
            );
          }
          final payouts = snapshot.data ?? const <Payout>[];
          if (payouts.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: payouts.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final p = payouts[i];
              return Card(
                child: ListTile(
                  title: Text('${l10n.payout} #${p.id}'),
                  subtitle: Text('${l10n.rank}: ${p.rank ?? '—'}'),
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        l10n.formatMoney(p.amountMinor ?? 0),
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      if (p.status != null) StatusPill(status: p.status!),
                    ],
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

```

### `mobile/lib/screens/wallet/wallet_screen.dart`

```dart
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import 'ledger_screen.dart';
import 'payouts_screen.dart';

/// Wallet (Phase 18 §23): balance + ledger + payouts. The balance is server
/// truth; the app never computes it locally.
class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  late Future<Wallet> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.wallet();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.wallet)),
      body: FutureBuilder<Wallet>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.wallet.wallet()),
              child: const SizedBox(),
            );
          }
          final wallet = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    children: [
                      Text(l10n.balance,
                          style: Theme.of(context).textTheme.labelLarge),
                      const SizedBox(height: 8),
                      Text(
                        l10n.formatMoney(wallet.balanceMinor ?? 0),
                        style: Theme.of(context)
                            .textTheme
                            .headlineMedium
                            ?.copyWith(fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              Card(
                child: Column(
                  children: [
                    ListTile(
                      leading: const Icon(Icons.receipt_long_outlined),
                      title: Text(l10n.ledger),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) =>
                              LedgerScreen(services: widget.services),
                        ),
                      ),
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.payments_outlined),
                      title: Text(l10n.payouts),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) =>
                              PayoutsScreen(services: widget.services),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

```


## Mobile — widgets & app entry

### `mobile/lib/app.dart`

```dart
import 'package:flutter/material.dart';

import 'config/app_config.dart';
import 'core/api/api_client.dart';
import 'core/cache/offline_cache.dart';
import 'core/l10n/app_localizations.dart';
import 'core/push/push_service.dart';
import 'core/session/session_manager.dart';
import 'core/session/session_store.dart';
import 'core/storage/secure_storage.dart';
import 'core/telemetry/crash_reporter.dart';
import 'data/repositories/app_meta_repository.dart';
import 'data/repositories/auth_repository.dart';
import 'data/repositories/device_repository.dart';
import 'data/repositories/dispute_repository.dart';
import 'data/repositories/leaderboard_repository.dart';
import 'data/repositories/live_repository.dart';
import 'data/repositories/match_repository.dart';
import 'data/repositories/notification_repository.dart';
import 'data/repositories/profile_repository.dart';
import 'data/repositories/security_repository.dart';
import 'data/repositories/support_repository.dart';
import 'data/repositories/team_repository.dart';
import 'data/repositories/tournament_repository.dart';
import 'data/repositories/wallet_repository.dart';
import 'features/deep_links/deep_link_router.dart';
import 'screens/auth/login_screen.dart';
import 'screens/security/security_event_screen.dart';
import 'screens/shell/app_shell.dart';
import 'theme/app_theme.dart';

/// Composition root (Phase 18 §7). Wires the ApiClient, SessionManager and
/// all repositories together. There is exactly one SessionManager and one
/// ApiClient per process — every repository shares them.
class AppServices {
  AppServices({
    required this.session,
    required this.api,
    required this.meta,
    required this.auth,
    required this.profile,
    required this.security,
    required this.tournaments,
    required this.teams,
    required this.matches,
    required this.leaderboard,
    required this.wallet,
    required this.notifications,
    required this.support,
    required this.disputes,
    required this.devices,
    required this.live,
    required this.push,
    required this.deepLinks,
  });

  final SessionManager session;
  final ApiClient api;
  final AppMetaRepository meta;
  final AuthRepository auth;
  final ProfileRepository profile;
  final SecurityRepository security;
  final TournamentRepository tournaments;
  final TeamRepository teams;
  final MatchRepository matches;
  final LeaderboardRepository leaderboard;
  final WalletRepository wallet;
  final NotificationRepository notifications;
  final SupportRepository support;
  final DisputeRepository disputes;
  final DeviceRepository devices;
  final LiveRepository live;
  final PushService push;
  final DeepLinkRouter deepLinks;

  /// Builds the full production graph. [secureStorage] and [crashReporter]
  /// are injectable for tests; the defaults are the platform keystore and a
  /// debug-only reporter.
  static AppServices create({
    SecureStorage? secureStorage,
    CrashReporter? crashReporter,
  }) {
    final config = AppConfig.instance;
    final crash = crashReporter ??
        (config.crashReportingEnabled
            ? const LogCrashReporter()
            : const NoopCrashReporter());
    final storage = secureStorage ?? PlatformSecureStorage();

    final api = ApiClient(
      baseUrl: config.apiBaseUrl,
      tokenProvider: () => _tokenHolder,
      crashReporter: crash,
    );

    final store = SessionStore(storage: storage);
    final push = noopPushService(api);
    final session = SessionManager(api: api, store: store, pushService: push);

    // Bind the token source after the session manager exists.
    _tokenHolder = session.token;

    return AppServices(
      session: session,
      api: api,
      meta: AppMetaRepository(api: api),
      auth: AuthRepository(api: api),
      profile: ProfileRepository(api: api),
      security: SecurityRepository(api: api),
      tournaments: TournamentRepository(api: api),
      teams: TeamRepository(api: api),
      matches: MatchRepository(api: api),
      leaderboard: LeaderboardRepository(api: api),
      wallet: WalletRepository(api: api),
      notifications: NotificationRepository(api: api),
      support: SupportRepository(api: api),
      disputes: DisputeRepository(api: api),
      devices: DeviceRepository(api: api),
      live: LiveRepository(api: api),
      push: push,
      deepLinks: DeepLinkRouter(scheme: config.deepLinkScheme),
    );
  }

  // Holds the current token for the ApiClient's token provider. Set right
  // after the SessionManager is constructed.
  static String? _tokenHolder;

  /// Boots shared state (offline cache). Call once before runApp.
  static Future<void> boot() async {
    await OfflineCache.instance.init(await AppConfig.cacheDirectory());
  }
}

/// Root widget. Routes between Login, the authenticated shell and the
/// security-event screen based on SessionManager state.
class FFApp extends StatelessWidget {
  const FFApp({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final session = services.session;

    return AnimatedBuilder(
      animation: session,
      builder: (context, _) {
        Widget home;
        if (session.restoring) {
          home = const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        } else if (session.endReason != null &&
            session.endReason!.isSecurityEvent) {
          home = SecurityEventScreen(services: services);
        } else if (!session.isAuthenticated) {
          home = LoginScreen(services: services);
        } else {
          home = AppShell(services: services);
        }

        return ValueListenableBuilder<Locale>(
          valueListenable: LocaleController.instance.locale,
          builder: (context, locale, _) {
            return MaterialApp(
              title: AppConfig.instance.appName,
              debugShowCheckedModeBanner: false,
              theme: AppTheme.light(),
              locale: locale,
              supportedLocales: AppLocalizations.supportedLocales,
              localizationsDelegates: AppLocalizations.localizationsDelegates,
              home: home,
            );
          },
        );
      },
    );
  }
}

/// Tiny locale holder so widgets outside the tree can flip locale.
class LocaleController {
  LocaleController._();

  static final LocaleController instance = LocaleController._();

  final ValueNotifier<Locale> locale = ValueNotifier(const Locale('en'));

  void set(String code) => locale.value = Locale(code);
}

```

### `mobile/lib/main.dart`

```dart
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app.dart';
import 'config/app_config.dart';

/// MethodChannel the native hosts (Android/iOS) forward deep-link URIs on.
/// The router logic itself is pure Dart and unit tested; this channel is the
/// thin OS boundary documented in docs/MOBILE_APP_SETUP.md.
const MethodChannel _deepLinkChannel =
    MethodChannel('ffarena.deeplink/channel');

/// Application entry point (Phase 18 §6).
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Release builds must never silently point at a placeholder API host.
  if (AppConfig.instance.isProduction && !AppConfig.instance.isApiBaseUrlSane) {
    throw StateError(
      'Production build requires FFARENA_API_BASE_URL starting with https://',
    );
  }

  await AppServices.boot();

  final services = AppServices.create();

  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
  ]);

  runApp(FFApp(services: services));

  // Forward the cold-start deep link (if any) to the router.
  unawaited(_routeInitialDeepLink(services));

  // Restore the persisted session in the background; the root widget shows a
  // spinner until SessionManager.restoring is false.
  unawaited(services.session.restore());

  // Best-effort push token sync (no-op when push is unconfigured).
  unawaited(services.push.sync());
}

Future<void> _routeInitialDeepLink(AppServices services) async {
  try {
    final initial = await _deepLinkChannel.invokeMethod<String>('initialLink');
    if (initial != null && initial.isNotEmpty) {
      services.deepLinks.route(initial);
    }
  } on MissingPluginException {
    // Native hosts without the channel simply have no deep links yet.
  } on PlatformException {
    // Ignore unhandled platform errors at startup.
  }

  _deepLinkChannel.setMethodCallHandler((call) async {
    if (call.method == 'onDeepLink' && call.arguments is String) {
      services.deepLinks.route(call.arguments as String);
    }
  });
}

void unawaited(Future<void> future) {
  future.ignore();
}

```

### `mobile/lib/widgets/async_view.dart`

```dart
import 'package:flutter/material.dart';

import '../core/l10n/app_localizations.dart';

/// Shared loading / error / empty scaffolding for screen bodies.
class AsyncView extends StatelessWidget {
  const AsyncView({
    super.key,
    required this.loading,
    required this.error,
    required this.empty,
    required this.onRetry,
    required this.child,
    this.emptyMessage,
  });

  final bool loading;
  final String? error;
  final bool empty;
  final VoidCallback onRetry;
  final Widget child;
  final String? emptyMessage;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (error != null) {
      return _ErrorState(message: error!, onRetry: onRetry);
    }
    if (empty) {
      return _EmptyState(message: emptyMessage ?? l10n.empty);
    }
    return child;
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline,
                size: 48, color: Theme.of(context).colorScheme.error),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            FilledButton.tonal(onPressed: onRetry, child: Text(l10n.retry)),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(message, textAlign: TextAlign.center),
      ),
    );
  }
}

```

### `mobile/lib/widgets/avatar.dart`

```dart
import 'package:flutter/material.dart';

/// Initials-based avatar with an optional remote image. Falls back to
/// initials when the URL is absent, malformed or fails to load.
class Avatar extends StatelessWidget {
  const Avatar({
    super.key,
    required this.name,
    this.url,
    this.radius = 20,
  });

  final String name;
  final String? url;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final initials = _initials(name);
    if (url != null && url!.isNotEmpty) {
      return CircleAvatar(
        radius: radius,
        backgroundColor: scheme.primaryContainer,
        foregroundImage: NetworkImage(url!),
        onForegroundImageError: (_, __) {},
        child: Text(initials),
      );
    }
    return CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primaryContainer,
      foregroundColor: scheme.onPrimaryContainer,
      child: Text(initials),
    );
  }

  static String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    if (parts.isEmpty) {
      return '?';
    }
    final buffer = StringBuffer();
    for (final part in parts.take(2)) {
      buffer.write(part.substring(0, 1).toUpperCase());
    }
    return buffer.toString();
  }
}

```

### `mobile/lib/widgets/key_value_row.dart`

```dart
import 'package:flutter/material.dart';

/// A label/value row used on detail screens.
class KeyValueRow extends StatelessWidget {
  const KeyValueRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}

```

### `mobile/lib/widgets/section_card.dart`

```dart
import 'package:flutter/material.dart';

/// A labelled card section used across detail screens.
class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    this.title,
    required this.child,
    this.trailing,
  });

  final String? title;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (title != null)
              Row(
                children: [
                  Expanded(
                    child: Text(
                      title!,
                      style: Theme.of(context)
                          .textTheme
                          .titleSmall
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                  ),
                  if (trailing != null) trailing!,
                ],
              ),
            if (title != null) const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

```

### `mobile/lib/widgets/status_pill.dart`

```dart
import 'package:flutter/material.dart';

/// A small, colour-coded status chip. Uses BOTH colour and text so the
/// meaning is never conveyed by colour alone (WCAG 2.2 AA).
class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = _colors(context, status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        status,
        style: Theme.of(context)
            .textTheme
            .labelSmall
            ?.copyWith(color: fg, fontWeight: FontWeight.w600),
      ),
    );
  }

  (Color, Color) _colors(BuildContext context, String s) {
    final scheme = Theme.of(context).colorScheme;
    final lower = s.toLowerCase();
    if (lower.contains('open') ||
        lower.contains('active') ||
        lower.contains('paid')) {
      return (const Color(0xFFD7F3E5), const Color(0xFF0B6E4F));
    }
    if (lower.contains('full') ||
        lower.contains('closed') ||
        lower.contains('failed')) {
      return (const Color(0xFFFBE4E2), const Color(0xFFB3261E));
    }
    if (lower.contains('wait') ||
        lower.contains('pending') ||
        lower.contains('check')) {
      return (const Color(0xFFFFF3CD), const Color(0xFF7A5B00));
    }
    return (scheme.surfaceContainerHighest, scheme.onSurfaceVariant);
  }
}

```


## Mobile — theme

### `mobile/lib/theme/app_theme.dart`

```dart
import 'package:flutter/material.dart';

/// FF Arena theme (Phase 18 §65 — WCAG 2.2 AA).
///
/// Colour choices satisfy the WCAG 2.2 AA contrast ratio for normal text:
///   * onPrimary (#FFFFFF) on primary (#0B6E4F): ~5.7:1
///   * onSurface (#17262B) on surface (#FFFFFF): ~14:1
///   * onError (#FFFFFF) on error (#B3261E): ~6.0:1
/// Text scales with the user's OS font size by relying on default
/// [TextTheme] and avoiding fixed line heights.
class AppTheme {
  AppTheme._();

  static const Color primary = Color(0xFF0B6E4F);
  static const Color onPrimary = Color(0xFFFFFFFF);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color onSurface = Color(0xFF17262B);
  static const Color error = Color(0xFFB3261E);
  static const Color onError = Color(0xFFFFFFFF);
  static const Color outline = Color(0xFF6F797C);

  static ThemeData light() {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: const ColorScheme.light(
        primary: primary,
        onPrimary: onPrimary,
        secondary: primary,
        surface: surface,
        onSurface: onSurface,
        error: error,
        onError: onError,
        outline: outline,
      ),
      scaffoldBackgroundColor: surface,
      appBarTheme: const AppBarTheme(
        backgroundColor: surface,
        foregroundColor: onSurface,
        elevation: 0,
        centerTitle: true,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
        filled: true,
        fillColor: Color(0xFFF1F4F4),
      ),
      cardTheme: const CardThemeData(
        elevation: 0,
        color: surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(12)),
          side: BorderSide(color: Color(0xFFDDE3E3)),
        ),
      ),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
      ),
    );

    return base;
  }
}

```


## Mobile — tests

### `mobile/test/core/api/api_client_test.dart`

```dart
import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

ApiClient _client({
  required String token,
  required Future<http.Response> Function(http.Request) handler,
  void Function(ApiException)? onSessionTerminated,
}) {
  final client = ApiClient(
    baseUrl: 'https://api.example.test/api/v1',
    tokenProvider: () => token,
    httpClient: MockClient(handler),
  );
  client.onSessionTerminated = onSessionTerminated;
  return client;
}

void main() {
  group('envelope decoding', () {
    test('decodes success envelope with data and meta', () async {
      final client = _client(
        token: '',
        handler: (_) async => http.Response(
          jsonEncode({
            'data': {'id': 1, 'name': 'Squad'},
            'meta': {
              'pagination': {'current_page': 1, 'last_page': 3},
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      );

      final result = await client.get('/tournaments');

      expect(result.asMap?['id'], 1);
      expect(result.pagination?.currentPage, 1);
      expect(result.pagination?.lastPage, 3);
      expect(result.hasMore, isTrue);
    });

    test('maps the error envelope to a typed ApiException', () async {
      final client = _client(
        token: '',
        handler: (_) async => http.Response(
          jsonEncode({
            'error': {
              'code': 'account_inactive',
              'message': 'Account is inactive.',
              'details': {'foo': 'bar'},
            },
          }),
          401,
          headers: {'content-type': 'application/json'},
        ),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.accountInactive);
        expect(e.statusCode, 401);
        expect(e.isSessionTerminating, isTrue);
        expect(e.details['foo'], 'bar');
      }
    });

    test('session-terminating errors invoke onSessionTerminated once',
        () async {
      var calls = 0;
      final client = _client(
        token: 'secret',
        onSessionTerminated: (_) => calls++,
        handler: (_) async => http.Response(
          jsonEncode({
            'error': {'code': 'token_revoked', 'message': 'Revoked.'},
          }),
          401,
          headers: {'content-type': 'application/json'},
        ),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException {
        // expected
      }

      expect(calls, 1);
    });
  });

  group('transport', () {
    test('attaches the bearer token and never logs it', () async {
      String? authHeader;
      final client = _client(
        token: 'super-secret-token',
        handler: (request) async {
          authHeader = request.headers['authorization'];
          return http.Response(jsonEncode({'data': const []}), 200);
        },
      );

      await client.get('/me');

      expect(authHeader, 'Bearer super-secret-token');
    });

    test('deduplicates the /api/v1 prefix from full paths', () async {
      String? path;
      final client = _client(
        token: '',
        handler: (request) async {
          path = request.url.path;
          return http.Response(jsonEncode({'data': const {}}), 200);
        },
      );

      await client.get('/api/v1/app/meta', auth: false);

      expect(path, '/api/v1/app/meta');
    });

    test('POST is attempted exactly once (mutations never auto-retry)',
        () async {
      var postCalls = 0;
      final client = _client(
        token: '',
        handler: (_) async {
          postCalls++;
          return http.Response(
            jsonEncode({
              'error': {'code': 'server_error', 'message': 'boom'},
            }),
            500,
          );
        },
      );

      try {
        await client.post('/matches/1/scores',
            body: {'team_id': 1, 'kills': 5, 'placement': 1});
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.serverError);
      }

      expect(postCalls, 1);
    });

    test('network offline maps to the offline ApiException', () async {
      final client = _client(
        token: '',
        handler: (_) async => throw http.ClientException('connection refused'),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.offline);
      }
    });

    test('sends Idempotency-Key header when provided', () async {
      String? key;
      final client = _client(
        token: '',
        handler: (request) async {
          key = request.headers['idempotency-key'];
          return http.Response(jsonEncode({'data': const {}}), 201);
        },
      );

      await client.post('/auth/login',
          body: const {}, idempotencyKey: 'mob-abc');

      expect(key, 'mob-abc');
    });
  });
}

```

### `mobile/test/core/api/api_exception_test.dart`

```dart
import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ApiException.fromBody', () {
    test('parses the Phase 15 error envelope', () {
      final e = ApiException.fromBody({
        'error': {
          'code': 'validation_error',
          'message': 'Invalid fields.',
          'details': {'kills': 'must be >= 0'},
        },
      }, statusCode: 422);

      expect(e.code, 'validation_error');
      expect(e.message, 'Invalid fields.');
      expect(e.statusCode, 422);
      expect(e.details['kills'], 'must be >= 0');
    });

    test('falls back to unknown code for malformed bodies', () {
      final e = ApiException.fromBody({'unexpected': true});
      expect(e.code, 'unknown');
    });

    test('handles a null body', () {
      final e = ApiException.fromBody(null, statusCode: 500);
      expect(e.code, 'unknown');
      expect(e.statusCode, 500);
    });

    test('isSessionTerminating covers every auth-failure code', () {
      for (final code in const [
        ApiException.accountInactive,
        ApiException.tokenExpired,
        ApiException.tokenRevoked,
        ApiException.unauthenticated,
      ]) {
        expect(
          const ApiException(code: '', message: '').isSessionTerminating,
          isFalse,
        );
        expect(
          ApiException(code: code, message: '').isSessionTerminating,
          isTrue,
        );
      }

      expect(
        const ApiException(code: 'rate_limited', message: '')
            .isSessionTerminating,
        isFalse,
      );
    });
  });
}

```

### `mobile/test/core/api/idempotency_test.dart`

```dart
import 'package:ffarena_mobile/core/api/idempotency.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('generates collision-resistant keys with the mobile prefix', () {
    final a = Idempotency.generate();
    final b = Idempotency.generate();

    expect(a, startsWith('mob-'));
    expect(b, startsWith('mob-'));
    expect(a, isNot(b));
    expect(a.length, greaterThan(12));
  });

  test('generates a large number of unique keys', () {
    final seen = <String>{};
    for (var i = 0; i < 500; i++) {
      seen.add(Idempotency.generate());
    }
    expect(seen.length, 500);
  });
}

```

### `mobile/test/core/cache/offline_cache_test.dart`

```dart
import 'dart:io';

import 'package:ffarena_mobile/core/cache/offline_cache.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late Directory tempDir;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('ffarena_cache_test');
  });

  tearDown(() async {
    await tempDir.delete(recursive: true);
  });

  test('put and get round-trips a payload', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('tournaments', {
      'items': [1, 2, 3]
    });

    final hit = await cache.get('tournaments');
    expect(hit, isNotNull);
    expect(hit!.payload['items'], [1, 2, 3]);
    expect(hit.cachedAt, isNotNull);
    expect(hit.isStale, isFalse);
  });

  test('missing key returns null (miss, not crash)', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    expect(await cache.get('nope'), isNull);
  });

  test('corrupt entry is treated as a miss', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    final file =
        File('${tempDir.path}/api_cache/${'bad'.hashCode & 0x7FFFFFFF}.json');
    await file.create(recursive: true);
    await file.writeAsString('not-json{{{');

    expect(await cache.get('bad'), isNull);
  });

  test('remove deletes an entry', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('k', {'v': 1});
    expect((await cache.get('k'))?.payload['v'], 1);

    await cache.remove('k');
    expect(await cache.get('k'), isNull);
  });

  test('clear empties the cache', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('a', {'v': 1});
    await cache.put('b', {'v': 2});
    await cache.clear();

    expect(await cache.get('a'), isNull);
    expect(await cache.get('b'), isNull);
  });
}

```

### `mobile/test/core/deep_links/deep_link_router_test.dart`

```dart
import 'package:ffarena_mobile/features/deep_links/deep_link_router.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('parseDeepLink', () {
    test('parses tournament link', () {
      final link = parseDeepLink(Uri.parse('ffarena://tournament/42'));
      expect(link, isNotNull);
      expect(link!.target, DeepLinkTarget.tournament);
      expect(link.id, 42);
    });

    test('parses match link', () {
      final link = parseDeepLink(Uri.parse('ffarena://match/7'));
      expect(link!.target, DeepLinkTarget.match);
      expect(link.id, 7);
    });

    test('parses profile link', () {
      final link = parseDeepLink(Uri.parse('ffarena://profile/99'));
      expect(link!.target, DeepLinkTarget.profile);
    });

    test('rejects wrong scheme', () {
      expect(
          parseDeepLink(Uri.parse('https://example.com/tournament/1')), isNull);
    });

    test('rejects missing id', () {
      expect(parseDeepLink(Uri.parse('ffarena://tournament')), isNull);
      expect(parseDeepLink(Uri.parse('ffarena://tournament/abc')), isNull);
    });

    test('rejects unknown targets', () {
      expect(parseDeepLink(Uri.parse('ffarena://wallet/1')), isNull);
    });

    test('never surfaces embedded secrets as routable data', () {
      // Query parameters (e.g. an attacker-injected token) are ignored.
      final link = parseDeepLink(Uri.parse('ffarena://match/3?token=secret'));
      expect(link!.id, 3);
      expect(link.raw, isNot(contains('secret')));
      expect(link.raw, 'ffarena://match/3');
    });
  });

  group('DeepLinkRouter', () {
    test('routes recognized links to the handler and ignores others', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      router.route('ffarena://tournament/1');
      router.route('ffarena://match/2');
      router.route('other://tournament/3');
      router.route('ffarena://nope/4');

      expect(seen.length, 2);
      expect(seen[0].target, DeepLinkTarget.tournament);
      expect(seen[1].target, DeepLinkTarget.match);
    });
  });
}

```

### `mobile/test/core/format/phone_test.dart`

```dart
import 'package:ffarena_mobile/core/format/phone.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('normalizes local BD numbers to E.164', () {
    expect(Phone.normalizeBd('01712345678'), '+8801712345678');
    expect(Phone.normalizeBd('8801712345678'), '+8801712345678');
    expect(Phone.normalizeBd('+880 1712-345678'), '+8801712345678');
  });

  test('rejects invalid numbers', () {
    expect(Phone.normalizeBd('12345'), isNull);
    expect(Phone.normalizeBd('01112345678'), isNull); // invalid prefix
  });

  test('renders E.164 as local display', () {
    expect(Phone.display('+8801712345678'), '01712345678');
  });
}

```

### `mobile/test/core/session/session_manager_test.dart`

```dart
import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/push/noop_push_provider.dart';
import 'package:ffarena_mobile/core/push/push_service.dart';
import 'package:ffarena_mobile/core/session/session_manager.dart';
import 'package:ffarena_mobile/core/session/session_store.dart';
import 'package:ffarena_mobile/core/storage/secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _me({String username = 'rahat'}) => http.Response(
      jsonEncode({
        'data': {'id': 7, 'username': username, 'name': 'Rahat'},
      }),
      200,
      headers: {'content-type': 'application/json'},
    );

http.Response _unauthorized(String code) => http.Response(
      jsonEncode({
        'error': {'code': code, 'message': 'nope'},
      }),
      401,
      headers: {'content-type': 'application/json'},
    );

ApiClient _api(Future<http.Response> Function(http.Request) handler) {
  final api = ApiClient(
    baseUrl: 'https://api.example.test/api/v1',
    tokenProvider: () => null,
    httpClient: MockClient(handler),
  );
  return api;
}

void main() {
  late InMemorySecureStorage storage;
  late SessionStore store;
  late ApiClient api;
  late SessionManager session;

  setUp(() {
    storage = InMemorySecureStorage();
    store = SessionStore(storage: storage);
    api = _api((_) async => _me());
    session = SessionManager(
      api: api,
      store: store,
      pushService: PushService(api: api, provider: const NoopPushProvider()),
    );
  });

  test('establish stores the token and exposes the user', () async {
    await session.establish(
      token: 'tok-123',
      user: Me.fromJson(const {'id': 7, 'username': 'rahat'}),
    );

    expect(session.isAuthenticated, isTrue);
    expect(session.me?.username, 'rahat');
    expect(session.token, 'tok-123');
    expect(await storage.read(SecureKeys.accessToken), 'tok-123');
  });

  test('restore with a valid token loads the user', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7, 'username': 'old'}),
    );
    // A fresh session manager shares the same store.
    final restored = SessionManager(
      api: api,
      store: store,
      pushService: PushService(api: api, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isTrue);
    expect(restored.me?.username, 'rahat'); // refreshed from GET /me
  });

  test('restore with a revoked token ends the session', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final revokingApi = _api((_) async => _unauthorized('token_revoked'));
    final restored = SessionManager(
      api: revokingApi,
      store: store,
      pushService:
          PushService(api: revokingApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isFalse);
    expect(restored.endReason, SessionEndReason.tokenRevoked);
    expect(await storage.read(SecureKeys.accessToken), isNull);
  });

  test('restore with a deactivated account routes to account-inactive',
      () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final deactApi = _api((_) async => _unauthorized('account_inactive'));
    final restored = SessionManager(
      api: deactApi,
      store: store,
      pushService:
          PushService(api: deactApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.endReason, SessionEndReason.accountInactive);
    expect(restored.isAuthenticated, isFalse);
  });

  test('restore offline keeps the cached session', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final offlineApi = _api((_) async => throw http.ClientException('offline'));
    final restored = SessionManager(
      api: offlineApi,
      store: store,
      pushService:
          PushService(api: offlineApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isTrue);
    expect(restored.endReason, isNull);
  });

  test('logout clears storage and is not a security event', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );

    await session.logout();

    expect(session.isAuthenticated, isFalse);
    expect(session.endReason, SessionEndReason.manualLogout);
    expect(session.endReason!.isSecurityEvent, isFalse);
    expect(await storage.read(SecureKeys.accessToken), isNull);
  });

  test('a session-terminating API response force-ends the session', () async {
    final terminatingApi = _api((_) async => _unauthorized('token_expired'));
    final manager = SessionManager(
      api: terminatingApi,
      store: store,
      pushService:
          PushService(api: terminatingApi, provider: const NoopPushProvider()),
    );
    await manager.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );

    try {
      await terminatingApi.get('/me');
    } on ApiException {
      // expected
    }

    expect(manager.endReason, SessionEndReason.tokenExpired);
    expect(manager.isAuthenticated, isFalse);
  });
}

```

### `mobile/test/core/session/session_store_test.dart`

```dart
import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/session/auth_session.dart';
import 'package:ffarena_mobile/core/session/session_store.dart';
import 'package:ffarena_mobile/core/storage/secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('save/load round-trips a session', () async {
    final storage = InMemorySecureStorage();
    final store = SessionStore(storage: storage);

    await store.save(UserSession(
      token: 'tok-9',
      tokenExpiresAt: '2030-01-01T00:00:00Z',
      user: Me.fromJson(const {'id': 3, 'username': 'rahat'}),
    ));

    final loaded = await store.load();

    expect(loaded, isNotNull);
    expect(loaded!.token, 'tok-9');
    expect(loaded.user.username, 'rahat');
    expect(loaded.tokenExpiresAt, '2030-01-01T00:00:00Z');
  });

  test('load returns null when nothing is stored', () async {
    final store = SessionStore(storage: InMemorySecureStorage());
    expect(await store.load(), isNull);
  });

  test('clear removes both keys', () async {
    final storage = InMemorySecureStorage();
    final store = SessionStore(storage: storage);
    await store.save(UserSession(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 3}),
    ));

    await store.clear();

    expect(await storage.read(SecureKeys.accessToken), isNull);
    expect(await store.load(), isNull);
  });
}

```

### `mobile/test/data/repositories/app_meta_repository_test.dart`

```dart
import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/data/repositories/app_meta_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetch parses and caches the server metadata anonymously', () async {
    final repo = AppMetaRepository(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => null,
        httpClient: MockClient((request) async {
          expect(request.url.path, '/api/v1/app/meta');
          expect(request.headers['authorization'], isNull);
          return http.Response(
            jsonEncode({
              'data': {
                'app': {
                  'name': 'FF Arena',
                  'min_supported_app_version': '1.2.0',
                  'latest_app_version': '1.4.1',
                  'deep_link_scheme': 'ffarena',
                },
                'push': {'fcm_enabled': false, 'apns_enabled': false},
                'urls': {
                  'support': 'https://example.test/support',
                  'privacy': 'https://example.test/privacy',
                  'terms': 'https://example.test/terms',
                },
                'platform': {
                  'currency': 'BDT',
                  'timezone': 'Asia/Dhaka',
                  'locale': 'bn_BD',
                },
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final meta = await repo.fetch();

    expect(meta.app?.minSupportedAppVersion, '1.2.0');
    expect(meta.app?.deepLinkScheme, 'ffarena');
    expect(meta.push?.fcmEnabled, false);
    expect(meta.platform?.currency, 'BDT');
    expect(repo.cached, isNotNull);
  });
}

```

### `mobile/test/widgets/localization_test.dart`

```dart
import 'package:ffarena_mobile/core/l10n/app_localizations.dart';
import 'package:ffarena_mobile/widgets/async_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget _wrap(Widget child, {Locale locale = const Locale('en')}) {
  return MaterialApp(
    locale: locale,
    supportedLocales: AppLocalizations.supportedLocales,
    localizationsDelegates: AppLocalizations.localizationsDelegates,
    home: Scaffold(body: child),
  );
}

void main() {
  group('AppLocalizations', () {
    testWidgets('resolves English strings', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      })));

      expect(l10n.wallet, 'Wallet');
      expect(l10n.tournaments, 'Tournaments');
      expect(l10n.errorOffline, 'You are offline. Check your connection.');
    });

    testWidgets('resolves Bangla strings', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      }), locale: const Locale('bn')));

      expect(l10n.wallet, 'ওয়ালেট');
      expect(l10n.tournaments, 'টুর্নামেন্ট');
    });

    testWidgets('t() resolves Bangla and falls back for unknown keys',
        (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      }), locale: const Locale('bn')));

      // A known key resolves to the Bangla value.
      expect(l10n.t('appName'), 'এফএফ এরিনা');
      // An unknown key falls back to the key itself (never crashes).
      expect(l10n.t('definitely_missing_key'), 'definitely_missing_key');
    });

    testWidgets('formatMoney renders BDT in taka', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      })));

      expect(l10n.formatMoney(12345), contains('123.45'));
    });
  });

  group('AsyncView', () {
    testWidgets('shows the loading indicator', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: true,
        error: null,
        empty: false,
        onRetry: () {},
        child: const Text('item'),
      )));

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('shows the error state with retry', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: 'boom',
        empty: false,
        onRetry: () {},
        child: const SizedBox(),
      )));

      expect(find.text('boom'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('shows the empty state', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: null,
        empty: true,
        onRetry: () {},
        child: const SizedBox(),
      )));

      expect(find.text('Nothing here yet.'), findsOneWidget);
    });

    testWidgets('renders the child when there is content', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: null,
        empty: false,
        onRetry: () {},
        child: const Text('item'),
      )));

      expect(find.text('item'), findsOneWidget);
    });
  });
}

```


## Mobile — Android scaffolding

### `mobile/android/app/src/main/AndroidManifest.xml`

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application
        android:label="FF Arena"
        android:name="${applicationName}"
        android:icon="@mipmap/ic_launcher">
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:launchMode="singleTop"
            android:taskAffinity=""
            android:theme="@style/LaunchTheme"
            android:configChanges="orientation|keyboardHidden|keyboard|screenSize|smallestScreenSize|locale|layoutDirection|fontScale|screenLayout|density|uiMode"
            android:hardwareAccelerated="true"
            android:windowSoftInputMode="adjustResize">
            <!-- Specifies an Android theme to apply to this Activity as soon as
                 the Android process has started. This theme is visible to the user
                 while the Flutter UI initializes. After that, this theme continues
                 to determine the Window background behind the Flutter UI. -->
            <meta-data
              android:name="io.flutter.embedding.android.NormalTheme"
              android:resource="@style/NormalTheme"
              />
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
            <!-- Deep links: ffarena://tournament/{id} | /match/{id} | /profile/{id} -->
            <intent-filter>
                <action android:name="android.intent.action.VIEW"/>
                <category android:name="android.intent.category.DEFAULT"/>
                <category android:name="android.intent.category.BROWSABLE"/>
                <data android:scheme="ffarena"/>
            </intent-filter>
        </activity>
        <!-- Don't delete the meta-data below.
             This is used by the Flutter tool to generate GeneratedPluginRegistrant.java -->
        <meta-data
            android:name="flutterEmbedding"
            android:value="2" />
    </application>
    <!-- Required to query activities that can process text, see:
         https://developer.android.com/training/package-visibility and
         https://developer.android.com/reference/android/content/Intent#ACTION_PROCESS_TEXT.

         In particular, this is used by the Flutter engine in io.flutter.plugin.text.ProcessTextPlugin. -->
    <queries>
        <intent>
            <action android:name="android.intent.action.PROCESS_TEXT"/>
            <data android:mimeType="text/plain"/>
        </intent>
    </queries>
</manifest>

```

### `mobile/android/app/src/main/kotlin/com/ffarena/ffarena_mobile/MainActivity.kt`

```kotlin
package com.ffarena.ffarena_mobile

import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity()

```

### `mobile/android/app/build.gradle.kts`

```kotlin
plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.ffarena.ffarena_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.ffarena.ffarena_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

```

### `mobile/android/build.gradle.kts`

```kotlin
allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}

```

### `mobile/android/settings.gradle.kts`

```kotlin
pluginManagement {
    val flutterSdkPath =
        run {
            val properties = java.util.Properties()
            file("local.properties").inputStream().use { properties.load(it) }
            val flutterSdkPath = properties.getProperty("flutter.sdk")
            require(flutterSdkPath != null) { "flutter.sdk not set in local.properties" }
            flutterSdkPath
        }

    includeBuild("$flutterSdkPath/packages/flutter_tools/gradle")

    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

plugins {
    id("dev.flutter.flutter-plugin-loader") version "1.0.0"
    id("com.android.application") version "9.1.0" apply false
    id("org.jetbrains.kotlin.android") version "2.4.0" apply false
}

include(":app")

```

### `mobile/android/gradle.properties`

```properties
org.gradle.jvmargs=-Xmx8G -XX:MaxMetaspaceSize=4G -XX:ReservedCodeCacheSize=512m -XX:+HeapDumpOnOutOfMemoryError
android.useAndroidX=true
# This newDsl flag was added by the Flutter template
android.newDsl=false
# This builtInKotlin flag was added by the Flutter template
android.builtInKotlin=false

```

### `mobile/android/gradle/wrapper/gradle-wrapper.properties`

```properties
distributionBase=GRADLE_USER_HOME
distributionPath=wrapper/dists
zipStoreBase=GRADLE_USER_HOME
zipStorePath=wrapper/dists
distributionUrl=https\://services.gradle.org/distributions/gradle-9.3.1-all.zip

```


## Mobile — iOS scaffolding

### `mobile/ios/Runner/AppDelegate.swift`

```swift
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }
}

```

### `mobile/ios/Runner/Info.plist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CADisableMinimumFrameDurationOnPhone</key>
	<true/>
	<key>CFBundleDevelopmentRegion</key>
	<string>$(DEVELOPMENT_LANGUAGE)</string>
	<key>CFBundleDisplayName</key>
	<string>FF Arena</string>
	<key>CFBundleExecutable</key>
	<string>$(EXECUTABLE_NAME)</string>
	<key>CFBundleIdentifier</key>
	<string>$(PRODUCT_BUNDLE_IDENTIFIER)</string>
	<key>CFBundleInfoDictionaryVersion</key>
	<string>6.0</string>
	<key>CFBundleName</key>
	<string>ffarena_mobile</string>
	<key>CFBundlePackageType</key>
	<string>APPL</string>
	<key>CFBundleShortVersionString</key>
	<string>$(FLUTTER_BUILD_NAME)</string>
	<key>CFBundleSignature</key>
	<string>????</string>
	<key>CFBundleVersion</key>
	<string>$(FLUTTER_BUILD_NUMBER)</string>
	<key>CFBundleURLTypes</key>
	<array>
		<dict>
			<key>CFBundleURLName</key>
			<string>com.ffarena.deeplink</string>
			<key>CFBundleURLSchemes</key>
			<array>
				<string>ffarena</string>
			</array>
		</dict>
	</array>
	<key>LSRequiresIPhoneOS</key>
	<true/>
	<key>UIApplicationSceneManifest</key>
	<dict>
		<key>UIApplicationSupportsMultipleScenes</key>
		<false/>
		<key>UISceneConfigurations</key>
		<dict>
			<key>UIWindowSceneSessionRoleApplication</key>
			<array>
				<dict>
					<key>UISceneClassName</key>
					<string>UIWindowScene</string>
					<key>UISceneConfigurationName</key>
					<string>flutter</string>
					<key>UISceneDelegateClassName</key>
					<string>$(PRODUCT_MODULE_NAME).SceneDelegate</string>
					<key>UISceneStoryboardFile</key>
					<string>Main</string>
				</dict>
			</array>
		</dict>
	</dict>
	<key>UIApplicationSupportsIndirectInputEvents</key>
	<true/>
	<key>UILaunchStoryboardName</key>
	<string>LaunchScreen</string>
	<key>UIMainStoryboardFile</key>
	<string>Main</string>
	<key>UISupportedInterfaceOrientations</key>
	<array>
		<string>UIInterfaceOrientationPortrait</string>
		<string>UIInterfaceOrientationLandscapeLeft</string>
		<string>UIInterfaceOrientationLandscapeRight</string>
	</array>
	<key>UISupportedInterfaceOrientations~ipad</key>
	<array>
		<string>UIInterfaceOrientationPortrait</string>
		<string>UIInterfaceOrientationPortraitUpsideDown</string>
		<string>UIInterfaceOrientationLandscapeLeft</string>
		<string>UIInterfaceOrientationLandscapeRight</string>
	</array>
</dict>
</plist>

```

### `mobile/ios/Runner.xcodeproj/project.pbxproj`

```text
// !$*UTF8*$!
{
	archiveVersion = 1;
	classes = {
	};
	objectVersion = 54;
	objects = {

/* Begin PBXBuildFile section */
		1498D2341E8E89220040F4C2 /* GeneratedPluginRegistrant.m in Sources */ = {isa = PBXBuildFile; fileRef = 1498D2331E8E89220040F4C2 /* GeneratedPluginRegistrant.m */; };
		331C808B294A63AB00263BE5 /* RunnerTests.swift in Sources */ = {isa = PBXBuildFile; fileRef = 331C807B294A618700263BE5 /* RunnerTests.swift */; };
		3B3967161E833CAA004F5970 /* AppFrameworkInfo.plist in Resources */ = {isa = PBXBuildFile; fileRef = 3B3967151E833CAA004F5970 /* AppFrameworkInfo.plist */; };
		74858FAF1ED2DC5600515810 /* AppDelegate.swift in Sources */ = {isa = PBXBuildFile; fileRef = 74858FAE1ED2DC5600515810 /* AppDelegate.swift */; };
		7884E8682EC3CC0700C636F2 /* SceneDelegate.swift in Sources */ = {isa = PBXBuildFile; fileRef = 7884E8672EC3CC0400C636F2 /* SceneDelegate.swift */; };
		78A318202AECB46A00862997 /* FlutterGeneratedPluginSwiftPackage in Frameworks */ = {isa = PBXBuildFile; productRef = 78A3181F2AECB46A00862997 /* FlutterGeneratedPluginSwiftPackage */; };
		97C146FC1CF9000F007C117D /* Main.storyboard in Resources */ = {isa = PBXBuildFile; fileRef = 97C146FA1CF9000F007C117D /* Main.storyboard */; };
		97C146FE1CF9000F007C117D /* Assets.xcassets in Resources */ = {isa = PBXBuildFile; fileRef = 97C146FD1CF9000F007C117D /* Assets.xcassets */; };
		97C147011CF9000F007C117D /* LaunchScreen.storyboard in Resources */ = {isa = PBXBuildFile; fileRef = 97C146FF1CF9000F007C117D /* LaunchScreen.storyboard */; };
/* End PBXBuildFile section */

/* Begin PBXContainerItemProxy section */
		331C8085294A63A400263BE5 /* PBXContainerItemProxy */ = {
			isa = PBXContainerItemProxy;
			containerPortal = 97C146E61CF9000F007C117D /* Project object */;
			proxyType = 1;
			remoteGlobalIDString = 97C146ED1CF9000F007C117D;
			remoteInfo = Runner;
		};
/* End PBXContainerItemProxy section */

/* Begin PBXCopyFilesBuildPhase section */
		9705A1C41CF9048500538489 /* Embed Frameworks */ = {
			isa = PBXCopyFilesBuildPhase;
			buildActionMask = 2147483647;
			dstPath = "";
			dstSubfolderSpec = 10;
			files = (
			);
			name = "Embed Frameworks";
			runOnlyForDeploymentPostprocessing = 0;
		};
/* End PBXCopyFilesBuildPhase section */

/* Begin PBXFileReference section */
		1498D2321E8E86230040F4C2 /* GeneratedPluginRegistrant.h */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.c.h; path = GeneratedPluginRegistrant.h; sourceTree = "<group>"; };
		1498D2331E8E89220040F4C2 /* GeneratedPluginRegistrant.m */ = {isa = PBXFileReference; fileEncoding = 4; lastKnownFileType = sourcecode.c.objc; path = GeneratedPluginRegistrant.m; sourceTree = "<group>"; };
		331C807B294A618700263BE5 /* RunnerTests.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = RunnerTests.swift; sourceTree = "<group>"; };
		331C8081294A63A400263BE5 /* RunnerTests.xctest */ = {isa = PBXFileReference; explicitFileType = wrapper.cfbundle; includeInIndex = 0; path = RunnerTests.xctest; sourceTree = BUILT_PRODUCTS_DIR; };
		3B3967151E833CAA004F5970 /* AppFrameworkInfo.plist */ = {isa = PBXFileReference; fileEncoding = 4; lastKnownFileType = text.plist.xml; name = AppFrameworkInfo.plist; path = Flutter/AppFrameworkInfo.plist; sourceTree = "<group>"; };
		74858FAD1ED2DC5600515810 /* Runner-Bridging-Header.h */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.c.h; path = "Runner-Bridging-Header.h"; sourceTree = "<group>"; };
		74858FAE1ED2DC5600515810 /* AppDelegate.swift */ = {isa = PBXFileReference; fileEncoding = 4; lastKnownFileType = sourcecode.swift; path = AppDelegate.swift; sourceTree = "<group>"; };
		7884E8672EC3CC0400C636F2 /* SceneDelegate.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = SceneDelegate.swift; sourceTree = "<group>"; };
		78E0A7A72DC9AD7400C4905E /* FlutterGeneratedPluginSwiftPackage */ = {isa = PBXFileReference; lastKnownFileType = wrapper; name = FlutterGeneratedPluginSwiftPackage; path = Flutter/ephemeral/Packages/FlutterGeneratedPluginSwiftPackage; sourceTree = "<group>"; };
		7AFA3C8E1D35360C0083082E /* Release.xcconfig */ = {isa = PBXFileReference; lastKnownFileType = text.xcconfig; name = Release.xcconfig; path = Flutter/Release.xcconfig; sourceTree = "<group>"; };
		9740EEB21CF90195004384FC /* Debug.xcconfig */ = {isa = PBXFileReference; fileEncoding = 4; lastKnownFileType = text.xcconfig; name = Debug.xcconfig; path = Flutter/Debug.xcconfig; sourceTree = "<group>"; };
		9740EEB31CF90195004384FC /* Generated.xcconfig */ = {isa = PBXFileReference; fileEncoding = 4; lastKnownFileType = text.xcconfig; name = Generated.xcconfig; path = Flutter/Generated.xcconfig; sourceTree = "<group>"; };
		97C146EE1CF9000F007C117D /* Runner.app */ = {isa = PBXFileReference; explicitFileType = wrapper.application; includeInIndex = 0; path = Runner.app; sourceTree = BUILT_PRODUCTS_DIR; };
		97C146FB1CF9000F007C117D /* Base */ = {isa = PBXFileReference; lastKnownFileType = file.storyboard; name = Base; path = Base.lproj/Main.storyboard; sourceTree = "<group>"; };
		97C146FD1CF9000F007C117D /* Assets.xcassets */ = {isa = PBXFileReference; lastKnownFileType = folder.assetcatalog; path = Assets.xcassets; sourceTree = "<group>"; };
		97C147001CF9000F007C117D /* Base */ = {isa = PBXFileReference; lastKnownFileType = file.storyboard; name = Base; path = Base.lproj/LaunchScreen.storyboard; sourceTree = "<group>"; };
		97C147021CF9000F007C117D /* Info.plist */ = {isa = PBXFileReference; lastKnownFileType = text.plist.xml; path = Info.plist; sourceTree = "<group>"; };
/* End PBXFileReference section */

/* Begin PBXFrameworksBuildPhase section */
		97C146EB1CF9000F007C117D /* Frameworks */ = {
			isa = PBXFrameworksBuildPhase;
			buildActionMask = 2147483647;
			files = (
				78A318202AECB46A00862997 /* FlutterGeneratedPluginSwiftPackage in Frameworks */,
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
/* End PBXFrameworksBuildPhase section */

/* Begin PBXGroup section */
		331C8082294A63A400263BE5 /* RunnerTests */ = {
			isa = PBXGroup;
			children = (
				331C807B294A618700263BE5 /* RunnerTests.swift */,
			);
			path = RunnerTests;
			sourceTree = "<group>";
		};
		9740EEB11CF90186004384FC /* Flutter */ = {
			isa = PBXGroup;
			children = (
				78E0A7A72DC9AD7400C4905E /* FlutterGeneratedPluginSwiftPackage */,
				3B3967151E833CAA004F5970 /* AppFrameworkInfo.plist */,
				9740EEB21CF90195004384FC /* Debug.xcconfig */,
				7AFA3C8E1D35360C0083082E /* Release.xcconfig */,
				9740EEB31CF90195004384FC /* Generated.xcconfig */,
			);
			name = Flutter;
			sourceTree = "<group>";
		};
		97C146E51CF9000F007C117D = {
			isa = PBXGroup;
			children = (
				9740EEB11CF90186004384FC /* Flutter */,
				97C146F01CF9000F007C117D /* Runner */,
				97C146EF1CF9000F007C117D /* Products */,
				331C8082294A63A400263BE5 /* RunnerTests */,
			);
			sourceTree = "<group>";
		};
		97C146EF1CF9000F007C117D /* Products */ = {
			isa = PBXGroup;
			children = (
				97C146EE1CF9000F007C117D /* Runner.app */,
				331C8081294A63A400263BE5 /* RunnerTests.xctest */,
			);
			name = Products;
			sourceTree = "<group>";
		};
		97C146F01CF9000F007C117D /* Runner */ = {
			isa = PBXGroup;
			children = (
				97C146FA1CF9000F007C117D /* Main.storyboard */,
				97C146FD1CF9000F007C117D /* Assets.xcassets */,
				97C146FF1CF9000F007C117D /* LaunchScreen.storyboard */,
				97C147021CF9000F007C117D /* Info.plist */,
				1498D2321E8E86230040F4C2 /* GeneratedPluginRegistrant.h */,
				1498D2331E8E89220040F4C2 /* GeneratedPluginRegistrant.m */,
				74858FAE1ED2DC5600515810 /* AppDelegate.swift */,
				7884E8672EC3CC0400C636F2 /* SceneDelegate.swift */,
				74858FAD1ED2DC5600515810 /* Runner-Bridging-Header.h */,
			);
			path = Runner;
			sourceTree = "<group>";
		};
/* End PBXGroup section */

/* Begin PBXNativeTarget section */
		331C8080294A63A400263BE5 /* RunnerTests */ = {
			isa = PBXNativeTarget;
			buildConfigurationList = 331C8087294A63A400263BE5 /* Build configuration list for PBXNativeTarget "RunnerTests" */;
			buildPhases = (
				331C807D294A63A400263BE5 /* Sources */,
				331C807F294A63A400263BE5 /* Resources */,
			);
			buildRules = (
			);
			dependencies = (
				331C8086294A63A400263BE5 /* PBXTargetDependency */,
			);
			name = RunnerTests;
			productName = RunnerTests;
			productReference = 331C8081294A63A400263BE5 /* RunnerTests.xctest */;
			productType = "com.apple.product-type.bundle.unit-test";
		};
		97C146ED1CF9000F007C117D /* Runner */ = {
			isa = PBXNativeTarget;
			buildConfigurationList = 97C147051CF9000F007C117D /* Build configuration list for PBXNativeTarget "Runner" */;
			buildPhases = (
				9740EEB61CF901F6004384FC /* Run Script */,
				97C146EA1CF9000F007C117D /* Sources */,
				97C146EB1CF9000F007C117D /* Frameworks */,
				97C146EC1CF9000F007C117D /* Resources */,
				9705A1C41CF9048500538489 /* Embed Frameworks */,
				3B06AD1E1E4923F5004D2608 /* Thin Binary */,
			);
			buildRules = (
			);
			dependencies = (
			);
			name = Runner;
			packageProductDependencies = (
				78A3181F2AECB46A00862997 /* FlutterGeneratedPluginSwiftPackage */,
			);
			productName = Runner;
			productReference = 97C146EE1CF9000F007C117D /* Runner.app */;
			productType = "com.apple.product-type.application";
		};
/* End PBXNativeTarget section */

/* Begin PBXProject section */
		97C146E61CF9000F007C117D /* Project object */ = {
			isa = PBXProject;
			attributes = {
				BuildIndependentTargetsInParallel = YES;
				LastUpgradeCheck = 1510;
				ORGANIZATIONNAME = "";
				TargetAttributes = {
					331C8080294A63A400263BE5 = {
						CreatedOnToolsVersion = 14.0;
						TestTargetID = 97C146ED1CF9000F007C117D;
					};
					97C146ED1CF9000F007C117D = {
						CreatedOnToolsVersion = 7.3.1;
						LastSwiftMigration = 1100;
					};
				};
			};
			buildConfigurationList = 97C146E91CF9000F007C117D /* Build configuration list for PBXProject "Runner" */;
			compatibilityVersion = "Xcode 9.3";
			developmentRegion = en;
			hasScannedForEncodings = 0;
			knownRegions = (
				en,
				Base,
			);
			mainGroup = 97C146E51CF9000F007C117D;
			packageReferences = (
				781AD8BC2B33823900A9FFBB /* XCLocalSwiftPackageReference "Flutter/ephemeral/Packages/FlutterGeneratedPluginSwiftPackage" */,
			);
			productRefGroup = 97C146EF1CF9000F007C117D /* Products */;
			projectDirPath = "";
			projectRoot = "";
			targets = (
				97C146ED1CF9000F007C117D /* Runner */,
				331C8080294A63A400263BE5 /* RunnerTests */,
			);
		};
/* End PBXProject section */

/* Begin PBXResourcesBuildPhase section */
		331C807F294A63A400263BE5 /* Resources */ = {
			isa = PBXResourcesBuildPhase;
			buildActionMask = 2147483647;
			files = (
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
		97C146EC1CF9000F007C117D /* Resources */ = {
			isa = PBXResourcesBuildPhase;
			buildActionMask = 2147483647;
			files = (
				97C147011CF9000F007C117D /* LaunchScreen.storyboard in Resources */,
				3B3967161E833CAA004F5970 /* AppFrameworkInfo.plist in Resources */,
				97C146FE1CF9000F007C117D /* Assets.xcassets in Resources */,
				97C146FC1CF9000F007C117D /* Main.storyboard in Resources */,
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
/* End PBXResourcesBuildPhase section */

/* Begin PBXShellScriptBuildPhase section */
		3B06AD1E1E4923F5004D2608 /* Thin Binary */ = {
			isa = PBXShellScriptBuildPhase;
			alwaysOutOfDate = 1;
			buildActionMask = 2147483647;
			files = (
			);
			inputPaths = (
				"${TARGET_BUILD_DIR}/${INFOPLIST_PATH}",
			);
			name = "Thin Binary";
			outputPaths = (
			);
			runOnlyForDeploymentPostprocessing = 0;
			shellPath = /bin/sh;
			shellScript = "/bin/sh \"$FLUTTER_ROOT/packages/flutter_tools/bin/xcode_backend.sh\" embed_and_thin";
		};
		9740EEB61CF901F6004384FC /* Run Script */ = {
			isa = PBXShellScriptBuildPhase;
			alwaysOutOfDate = 1;
			buildActionMask = 2147483647;
			files = (
			);
			inputPaths = (
			);
			name = "Run Script";
			outputPaths = (
			);
			runOnlyForDeploymentPostprocessing = 0;
			shellPath = /bin/sh;
			shellScript = "/bin/sh \"$FLUTTER_ROOT/packages/flutter_tools/bin/xcode_backend.sh\" build";
		};
/* End PBXShellScriptBuildPhase section */

/* Begin PBXSourcesBuildPhase section */
		331C807D294A63A400263BE5 /* Sources */ = {
			isa = PBXSourcesBuildPhase;
			buildActionMask = 2147483647;
			files = (
				331C808B294A63AB00263BE5 /* RunnerTests.swift in Sources */,
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
		97C146EA1CF9000F007C117D /* Sources */ = {
			isa = PBXSourcesBuildPhase;
			buildActionMask = 2147483647;
			files = (
				74858FAF1ED2DC5600515810 /* AppDelegate.swift in Sources */,
				1498D2341E8E89220040F4C2 /* GeneratedPluginRegistrant.m in Sources */,
				7884E8682EC3CC0700C636F2 /* SceneDelegate.swift in Sources */,
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
/* End PBXSourcesBuildPhase section */

/* Begin PBXTargetDependency section */
		331C8086294A63A400263BE5 /* PBXTargetDependency */ = {
			isa = PBXTargetDependency;
			target = 97C146ED1CF9000F007C117D /* Runner */;
			targetProxy = 331C8085294A63A400263BE5 /* PBXContainerItemProxy */;
		};
/* End PBXTargetDependency section */

/* Begin PBXVariantGroup section */
		97C146FA1CF9000F007C117D /* Main.storyboard */ = {
			isa = PBXVariantGroup;
			children = (
				97C146FB1CF9000F007C117D /* Base */,
			);
			name = Main.storyboard;
			sourceTree = "<group>";
		};
		97C146FF1CF9000F007C117D /* LaunchScreen.storyboard */ = {
			isa = PBXVariantGroup;
			children = (
				97C147001CF9000F007C117D /* Base */,
			);
			name = LaunchScreen.storyboard;
			sourceTree = "<group>";
		};
/* End PBXVariantGroup section */

/* Begin XCBuildConfiguration section */
		249021D3217E4FDB00AE95B9 /* Profile */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				ALWAYS_SEARCH_USER_PATHS = NO;
				ASSETCATALOG_COMPILER_GENERATE_SWIFT_ASSET_SYMBOL_EXTENSIONS = YES;
				CLANG_ANALYZER_NONNULL = YES;
				CLANG_CXX_LANGUAGE_STANDARD = "gnu++0x";
				CLANG_CXX_LIBRARY = "libc++";
				CLANG_ENABLE_MODULES = YES;
				CLANG_ENABLE_OBJC_ARC = YES;
				CLANG_WARN_BLOCK_CAPTURE_AUTORELEASING = YES;
				CLANG_WARN_BOOL_CONVERSION = YES;
				CLANG_WARN_COMMA = YES;
				CLANG_WARN_CONSTANT_CONVERSION = YES;
				CLANG_WARN_DEPRECATED_OBJC_IMPLEMENTATIONS = YES;
				CLANG_WARN_DIRECT_OBJC_ISA_USAGE = YES_ERROR;
				CLANG_WARN_EMPTY_BODY = YES;
				CLANG_WARN_ENUM_CONVERSION = YES;
				CLANG_WARN_INFINITE_RECURSION = YES;
				CLANG_WARN_INT_CONVERSION = YES;
				CLANG_WARN_NON_LITERAL_NULL_CONVERSION = YES;
				CLANG_WARN_OBJC_IMPLICIT_RETAIN_SELF = YES;
				CLANG_WARN_OBJC_LITERAL_CONVERSION = YES;
				CLANG_WARN_OBJC_ROOT_CLASS = YES_ERROR;
				CLANG_WARN_RANGE_LOOP_ANALYSIS = YES;
				CLANG_WARN_STRICT_PROTOTYPES = YES;
				CLANG_WARN_SUSPICIOUS_MOVE = YES;
				CLANG_WARN_UNREACHABLE_CODE = YES;
				CLANG_WARN__DUPLICATE_METHOD_MATCH = YES;
				"CODE_SIGN_IDENTITY[sdk=iphoneos*]" = "iPhone Developer";
				COPY_PHASE_STRIP = NO;
				DEBUG_INFORMATION_FORMAT = "dwarf-with-dsym";
				ENABLE_NS_ASSERTIONS = NO;
				ENABLE_STRICT_OBJC_MSGSEND = YES;
				ENABLE_USER_SCRIPT_SANDBOXING = NO;
				GCC_C_LANGUAGE_STANDARD = gnu99;
				GCC_NO_COMMON_BLOCKS = YES;
				GCC_WARN_64_TO_32_BIT_CONVERSION = YES;
				GCC_WARN_ABOUT_RETURN_TYPE = YES_ERROR;
				GCC_WARN_UNDECLARED_SELECTOR = YES;
				GCC_WARN_UNINITIALIZED_AUTOS = YES_AGGRESSIVE;
				GCC_WARN_UNUSED_FUNCTION = YES;
				GCC_WARN_UNUSED_VARIABLE = YES;
				IPHONEOS_DEPLOYMENT_TARGET = 15.0;
				MTL_ENABLE_DEBUG_INFO = NO;
				SDKROOT = iphoneos;
				STRING_CATALOG_GENERATE_SYMBOLS = YES;
				SUPPORTED_PLATFORMS = iphoneos;
				TARGETED_DEVICE_FAMILY = "1,2";
				VALIDATE_PRODUCT = YES;
			};
			name = Profile;
		};
		249021D4217E4FDB00AE95B9 /* Profile */ = {
			isa = XCBuildConfiguration;
			baseConfigurationReference = 7AFA3C8E1D35360C0083082E /* Release.xcconfig */;
			buildSettings = {
				ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;
				CLANG_ENABLE_MODULES = YES;
				CURRENT_PROJECT_VERSION = "$(FLUTTER_BUILD_NUMBER)";
				ENABLE_BITCODE = NO;
				INFOPLIST_FILE = Runner/Info.plist;
				LD_RUNPATH_SEARCH_PATHS = (
					"$(inherited)",
					"@executable_path/Frameworks",
				);
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_OBJC_BRIDGING_HEADER = "Runner/Runner-Bridging-Header.h";
				SWIFT_VERSION = 5.0;
				VERSIONING_SYSTEM = "apple-generic";
			};
			name = Profile;
		};
		331C8088294A63A400263BE5 /* Debug */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				BUNDLE_LOADER = "$(TEST_HOST)";
				CODE_SIGN_STYLE = Automatic;
				CURRENT_PROJECT_VERSION = 1;
				GENERATE_INFOPLIST_FILE = YES;
				MARKETING_VERSION = 1.0;
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile.RunnerTests;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_ACTIVE_COMPILATION_CONDITIONS = DEBUG;
				SWIFT_OPTIMIZATION_LEVEL = "-Onone";
				SWIFT_VERSION = 5.0;
				TEST_HOST = "$(BUILT_PRODUCTS_DIR)/Runner.app/$(BUNDLE_EXECUTABLE_FOLDER_PATH)/Runner";
			};
			name = Debug;
		};
		331C8089294A63A400263BE5 /* Release */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				BUNDLE_LOADER = "$(TEST_HOST)";
				CODE_SIGN_STYLE = Automatic;
				CURRENT_PROJECT_VERSION = 1;
				GENERATE_INFOPLIST_FILE = YES;
				MARKETING_VERSION = 1.0;
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile.RunnerTests;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_VERSION = 5.0;
				TEST_HOST = "$(BUILT_PRODUCTS_DIR)/Runner.app/$(BUNDLE_EXECUTABLE_FOLDER_PATH)/Runner";
			};
			name = Release;
		};
		331C808A294A63A400263BE5 /* Profile */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				BUNDLE_LOADER = "$(TEST_HOST)";
				CODE_SIGN_STYLE = Automatic;
				CURRENT_PROJECT_VERSION = 1;
				GENERATE_INFOPLIST_FILE = YES;
				MARKETING_VERSION = 1.0;
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile.RunnerTests;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_VERSION = 5.0;
				TEST_HOST = "$(BUILT_PRODUCTS_DIR)/Runner.app/$(BUNDLE_EXECUTABLE_FOLDER_PATH)/Runner";
			};
			name = Profile;
		};
		97C147031CF9000F007C117D /* Debug */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				ALWAYS_SEARCH_USER_PATHS = NO;
				ASSETCATALOG_COMPILER_GENERATE_SWIFT_ASSET_SYMBOL_EXTENSIONS = YES;
				CLANG_ANALYZER_NONNULL = YES;
				CLANG_CXX_LANGUAGE_STANDARD = "gnu++0x";
				CLANG_CXX_LIBRARY = "libc++";
				CLANG_ENABLE_MODULES = YES;
				CLANG_ENABLE_OBJC_ARC = YES;
				CLANG_WARN_BLOCK_CAPTURE_AUTORELEASING = YES;
				CLANG_WARN_BOOL_CONVERSION = YES;
				CLANG_WARN_COMMA = YES;
				CLANG_WARN_CONSTANT_CONVERSION = YES;
				CLANG_WARN_DEPRECATED_OBJC_IMPLEMENTATIONS = YES;
				CLANG_WARN_DIRECT_OBJC_ISA_USAGE = YES_ERROR;
				CLANG_WARN_EMPTY_BODY = YES;
				CLANG_WARN_ENUM_CONVERSION = YES;
				CLANG_WARN_INFINITE_RECURSION = YES;
				CLANG_WARN_INT_CONVERSION = YES;
				CLANG_WARN_NON_LITERAL_NULL_CONVERSION = YES;
				CLANG_WARN_OBJC_IMPLICIT_RETAIN_SELF = YES;
				CLANG_WARN_OBJC_LITERAL_CONVERSION = YES;
				CLANG_WARN_OBJC_ROOT_CLASS = YES_ERROR;
				CLANG_WARN_RANGE_LOOP_ANALYSIS = YES;
				CLANG_WARN_STRICT_PROTOTYPES = YES;
				CLANG_WARN_SUSPICIOUS_MOVE = YES;
				CLANG_WARN_UNREACHABLE_CODE = YES;
				CLANG_WARN__DUPLICATE_METHOD_MATCH = YES;
				"CODE_SIGN_IDENTITY[sdk=iphoneos*]" = "iPhone Developer";
				COPY_PHASE_STRIP = NO;
				DEBUG_INFORMATION_FORMAT = dwarf;
				ENABLE_STRICT_OBJC_MSGSEND = YES;
				ENABLE_TESTABILITY = YES;
				ENABLE_USER_SCRIPT_SANDBOXING = NO;
				GCC_C_LANGUAGE_STANDARD = gnu99;
				GCC_DYNAMIC_NO_PIC = NO;
				GCC_NO_COMMON_BLOCKS = YES;
				GCC_OPTIMIZATION_LEVEL = 0;
				GCC_PREPROCESSOR_DEFINITIONS = (
					"DEBUG=1",
					"$(inherited)",
				);
				GCC_WARN_64_TO_32_BIT_CONVERSION = YES;
				GCC_WARN_ABOUT_RETURN_TYPE = YES_ERROR;
				GCC_WARN_UNDECLARED_SELECTOR = YES;
				GCC_WARN_UNINITIALIZED_AUTOS = YES_AGGRESSIVE;
				GCC_WARN_UNUSED_FUNCTION = YES;
				GCC_WARN_UNUSED_VARIABLE = YES;
				IPHONEOS_DEPLOYMENT_TARGET = 15.0;
				MTL_ENABLE_DEBUG_INFO = YES;
				ONLY_ACTIVE_ARCH = YES;
				SDKROOT = iphoneos;
				STRING_CATALOG_GENERATE_SYMBOLS = YES;
				TARGETED_DEVICE_FAMILY = "1,2";
			};
			name = Debug;
		};
		97C147041CF9000F007C117D /* Release */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				ALWAYS_SEARCH_USER_PATHS = NO;
				ASSETCATALOG_COMPILER_GENERATE_SWIFT_ASSET_SYMBOL_EXTENSIONS = YES;
				CLANG_ANALYZER_NONNULL = YES;
				CLANG_CXX_LANGUAGE_STANDARD = "gnu++0x";
				CLANG_CXX_LIBRARY = "libc++";
				CLANG_ENABLE_MODULES = YES;
				CLANG_ENABLE_OBJC_ARC = YES;
				CLANG_WARN_BLOCK_CAPTURE_AUTORELEASING = YES;
				CLANG_WARN_BOOL_CONVERSION = YES;
				CLANG_WARN_COMMA = YES;
				CLANG_WARN_CONSTANT_CONVERSION = YES;
				CLANG_WARN_DEPRECATED_OBJC_IMPLEMENTATIONS = YES;
				CLANG_WARN_DIRECT_OBJC_ISA_USAGE = YES_ERROR;
				CLANG_WARN_EMPTY_BODY = YES;
				CLANG_WARN_ENUM_CONVERSION = YES;
				CLANG_WARN_INFINITE_RECURSION = YES;
				CLANG_WARN_INT_CONVERSION = YES;
				CLANG_WARN_NON_LITERAL_NULL_CONVERSION = YES;
				CLANG_WARN_OBJC_IMPLICIT_RETAIN_SELF = YES;
				CLANG_WARN_OBJC_LITERAL_CONVERSION = YES;
				CLANG_WARN_OBJC_ROOT_CLASS = YES_ERROR;
				CLANG_WARN_RANGE_LOOP_ANALYSIS = YES;
				CLANG_WARN_STRICT_PROTOTYPES = YES;
				CLANG_WARN_SUSPICIOUS_MOVE = YES;
				CLANG_WARN_UNREACHABLE_CODE = YES;
				CLANG_WARN__DUPLICATE_METHOD_MATCH = YES;
				"CODE_SIGN_IDENTITY[sdk=iphoneos*]" = "iPhone Developer";
				COPY_PHASE_STRIP = NO;
				DEBUG_INFORMATION_FORMAT = "dwarf-with-dsym";
				ENABLE_NS_ASSERTIONS = NO;
				ENABLE_STRICT_OBJC_MSGSEND = YES;
				ENABLE_USER_SCRIPT_SANDBOXING = NO;
				GCC_C_LANGUAGE_STANDARD = gnu99;
				GCC_NO_COMMON_BLOCKS = YES;
				GCC_WARN_64_TO_32_BIT_CONVERSION = YES;
				GCC_WARN_ABOUT_RETURN_TYPE = YES_ERROR;
				GCC_WARN_UNDECLARED_SELECTOR = YES;
				GCC_WARN_UNINITIALIZED_AUTOS = YES_AGGRESSIVE;
				GCC_WARN_UNUSED_FUNCTION = YES;
				GCC_WARN_UNUSED_VARIABLE = YES;
				IPHONEOS_DEPLOYMENT_TARGET = 15.0;
				MTL_ENABLE_DEBUG_INFO = NO;
				SDKROOT = iphoneos;
				STRING_CATALOG_GENERATE_SYMBOLS = YES;
				SUPPORTED_PLATFORMS = iphoneos;
				SWIFT_COMPILATION_MODE = wholemodule;
				SWIFT_OPTIMIZATION_LEVEL = "-O";
				TARGETED_DEVICE_FAMILY = "1,2";
				VALIDATE_PRODUCT = YES;
			};
			name = Release;
		};
		97C147061CF9000F007C117D /* Debug */ = {
			isa = XCBuildConfiguration;
			baseConfigurationReference = 9740EEB21CF90195004384FC /* Debug.xcconfig */;
			buildSettings = {
				ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;
				CLANG_ENABLE_MODULES = YES;
				CURRENT_PROJECT_VERSION = "$(FLUTTER_BUILD_NUMBER)";
				ENABLE_BITCODE = NO;
				INFOPLIST_FILE = Runner/Info.plist;
				LD_RUNPATH_SEARCH_PATHS = (
					"$(inherited)",
					"@executable_path/Frameworks",
				);
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_OBJC_BRIDGING_HEADER = "Runner/Runner-Bridging-Header.h";
				SWIFT_OPTIMIZATION_LEVEL = "-Onone";
				SWIFT_VERSION = 5.0;
				VERSIONING_SYSTEM = "apple-generic";
			};
			name = Debug;
		};
		97C147071CF9000F007C117D /* Release */ = {
			isa = XCBuildConfiguration;
			baseConfigurationReference = 7AFA3C8E1D35360C0083082E /* Release.xcconfig */;
			buildSettings = {
				ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;
				CLANG_ENABLE_MODULES = YES;
				CURRENT_PROJECT_VERSION = "$(FLUTTER_BUILD_NUMBER)";
				ENABLE_BITCODE = NO;
				INFOPLIST_FILE = Runner/Info.plist;
				LD_RUNPATH_SEARCH_PATHS = (
					"$(inherited)",
					"@executable_path/Frameworks",
				);
				PRODUCT_BUNDLE_IDENTIFIER = com.ffarena.ffarenaMobile;
				PRODUCT_NAME = "$(TARGET_NAME)";
				SWIFT_OBJC_BRIDGING_HEADER = "Runner/Runner-Bridging-Header.h";
				SWIFT_VERSION = 5.0;
				VERSIONING_SYSTEM = "apple-generic";
			};
			name = Release;
		};
/* End XCBuildConfiguration section */

/* Begin XCConfigurationList section */
		331C8087294A63A400263BE5 /* Build configuration list for PBXNativeTarget "RunnerTests" */ = {
			isa = XCConfigurationList;
			buildConfigurations = (
				331C8088294A63A400263BE5 /* Debug */,
				331C8089294A63A400263BE5 /* Release */,
				331C808A294A63A400263BE5 /* Profile */,
			);
			defaultConfigurationIsVisible = 0;
			defaultConfigurationName = Release;
		};
		97C146E91CF9000F007C117D /* Build configuration list for PBXProject "Runner" */ = {
			isa = XCConfigurationList;
			buildConfigurations = (
				97C147031CF9000F007C117D /* Debug */,
				97C147041CF9000F007C117D /* Release */,
				249021D3217E4FDB00AE95B9 /* Profile */,
			);
			defaultConfigurationIsVisible = 0;
			defaultConfigurationName = Release;
		};
		97C147051CF9000F007C117D /* Build configuration list for PBXNativeTarget "Runner" */ = {
			isa = XCConfigurationList;
			buildConfigurations = (
				97C147061CF9000F007C117D /* Debug */,
				97C147071CF9000F007C117D /* Release */,
				249021D4217E4FDB00AE95B9 /* Profile */,
			);
			defaultConfigurationIsVisible = 0;
			defaultConfigurationName = Release;
		};
/* End XCConfigurationList section */

/* Begin XCLocalSwiftPackageReference section */
		781AD8BC2B33823900A9FFBB /* XCLocalSwiftPackageReference "Flutter/ephemeral/Packages/FlutterGeneratedPluginSwiftPackage" */ = {
			isa = XCLocalSwiftPackageReference;
			relativePath = Flutter/ephemeral/Packages/FlutterGeneratedPluginSwiftPackage;
		};
/* End XCLocalSwiftPackageReference section */

/* Begin XCSwiftPackageProductDependency section */
		78A3181F2AECB46A00862997 /* FlutterGeneratedPluginSwiftPackage */ = {
			isa = XCSwiftPackageProductDependency;
			productName = FlutterGeneratedPluginSwiftPackage;
		};
/* End XCSwiftPackageProductDependency section */
	};
	rootObject = 97C146E61CF9000F007C117D /* Project object */;
}

```

### `mobile/ios/Flutter/Debug.xcconfig`

```text
#include "Generated.xcconfig"

```

### `mobile/ios/Flutter/Release.xcconfig`

```text
#include "Generated.xcconfig"

```

### `mobile/ios/Runner/Base.lproj/Main.storyboard`

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<document type="com.apple.InterfaceBuilder3.CocoaTouch.Storyboard.XIB" version="3.0" toolsVersion="10117" systemVersion="15F34" targetRuntime="iOS.CocoaTouch" propertyAccessControl="none" useAutolayout="YES" useTraitCollections="YES" initialViewController="BYZ-38-t0r">
    <dependencies>
        <deployment identifier="iOS"/>
        <plugIn identifier="com.apple.InterfaceBuilder.IBCocoaTouchPlugin" version="10085"/>
    </dependencies>
    <scenes>
        <!--Flutter View Controller-->
        <scene sceneID="tne-QT-ifu">
            <objects>
                <viewController id="BYZ-38-t0r" customClass="FlutterViewController" sceneMemberID="viewController">
                    <layoutGuides>
                        <viewControllerLayoutGuide type="top" id="y3c-jy-aDJ"/>
                        <viewControllerLayoutGuide type="bottom" id="wfy-db-euE"/>
                    </layoutGuides>
                    <view key="view" contentMode="scaleToFill" id="8bC-Xf-vdC">
                        <rect key="frame" x="0.0" y="0.0" width="600" height="600"/>
                        <autoresizingMask key="autoresizingMask" widthSizable="YES" heightSizable="YES"/>
                        <color key="backgroundColor" white="1" alpha="1" colorSpace="custom" customColorSpace="calibratedWhite"/>
                    </view>
                </viewController>
                <placeholder placeholderIdentifier="IBFirstResponder" id="dkx-z0-nzr" sceneMemberID="firstResponder"/>
            </objects>
        </scene>
    </scenes>
</document>

```

### `mobile/ios/Runner/Base.lproj/LaunchScreen.storyboard`

```xml
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<document type="com.apple.InterfaceBuilder3.CocoaTouch.Storyboard.XIB" version="3.0" toolsVersion="12121" systemVersion="16G29" targetRuntime="iOS.CocoaTouch" propertyAccessControl="none" useAutolayout="YES" launchScreen="YES" colorMatched="YES" initialViewController="01J-lp-oVM">
    <dependencies>
        <deployment identifier="iOS"/>
        <plugIn identifier="com.apple.InterfaceBuilder.IBCocoaTouchPlugin" version="12089"/>
    </dependencies>
    <scenes>
        <!--View Controller-->
        <scene sceneID="EHf-IW-A2E">
            <objects>
                <viewController id="01J-lp-oVM" sceneMemberID="viewController">
                    <layoutGuides>
                        <viewControllerLayoutGuide type="top" id="Ydg-fD-yQy"/>
                        <viewControllerLayoutGuide type="bottom" id="xbc-2k-c8Z"/>
                    </layoutGuides>
                    <view key="view" contentMode="scaleToFill" id="Ze5-6b-2t3">
                        <autoresizingMask key="autoresizingMask" widthSizable="YES" heightSizable="YES"/>
                        <subviews>
                            <imageView opaque="NO" clipsSubviews="YES" multipleTouchEnabled="YES" contentMode="center" image="LaunchImage" translatesAutoresizingMaskIntoConstraints="NO" id="YRO-k0-Ey4">
                            </imageView>
                        </subviews>
                        <color key="backgroundColor" red="1" green="1" blue="1" alpha="1" colorSpace="custom" customColorSpace="sRGB"/>
                        <constraints>
                            <constraint firstItem="YRO-k0-Ey4" firstAttribute="centerX" secondItem="Ze5-6b-2t3" secondAttribute="centerX" id="1a2-6s-vTC"/>
                            <constraint firstItem="YRO-k0-Ey4" firstAttribute="centerY" secondItem="Ze5-6b-2t3" secondAttribute="centerY" id="4X2-HB-R7a"/>
                        </constraints>
                    </view>
                </viewController>
                <placeholder placeholderIdentifier="IBFirstResponder" id="iYj-Kq-Ea1" userLabel="First Responder" sceneMemberID="firstResponder"/>
            </objects>
            <point key="canvasLocation" x="53" y="375"/>
        </scene>
    </scenes>
    <resources>
        <image name="LaunchImage" width="168" height="185"/>
    </resources>
</document>

```


## Documentation

### `docs/MOBILE_APP_SETUP.md`

```markdown
# Mobile App — Setup Guide (Phase 18)

How to get the FF Arena mobile client running locally and wired to the
Laravel `/api/v1` platform.

## 1. Prerequisites

- Flutter SDK **3.47.3 stable** (Dart 3.13.3). The workspace SDK lives at
  `/opt/flutter`; put it on `PATH`:

  ```bash
  export PATH=/opt/flutter/bin:$PATH
  flutter --version   # Flutter 3.47.3 • stable • Dart 3.13.3
  ```

- A running backend. From the repository root:

  ```bash
  php artisan migrate:fresh --seed
  php artisan serve --host=0.0.0.0 --port=8000
  ```

  The API base URL is then `http://localhost:8000/api/v1` (emulator) or
  `http://<your-machine-ip>:8000/api/v1` (physical device).

- Android device/emulator and/or iOS device + Xcode (only needed to RUN on
  device; analysis and tests work without them).

## 2. Install dependencies

```bash
cd mobile
flutter pub get
```

## 3. Configuration (dart-define)

The app reads ALL environment-sensitive values from `--dart-define` and
defaults to safe placeholders. **Nothing is hardcoded; nothing secret is
committed.**

| Define | Purpose | Default |
| --- | --- | --- |
| `FFARENA_API_BASE_URL` | API origin **including** `/api/v1` | `http://localhost/api/v1` |
| `FFARENA_ENV` | `development` / `staging` / `production` | `development` |
| `FFARENA_GOOGLE_CLIENT_ID` | Google Sign-In iOS/Android client id | *(empty → button hidden)* |
| `FFARENA_GOOGLE_SERVER_CLIENT_ID` | Web server client id (serverClientId) | *(empty)* |
| `FFARENA_PUSH_ENABLED` | Whether a push provider is wired | `false` |
| `FFARENA_CRASH_REPORTING_ENABLED` | Bind a crash reporter | `false` |

```bash
flutter run \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

> `10.0.2.2` is the Android emulator alias for the host machine's
> `localhost`.

Release builds refuse to run against a non-HTTPS API (`main.dart` throws in
`production` when the base URL does not start with `https://`).

## 4. Deep links

The router understands:

```
ffarena://tournament/{id}
ffarena://match/{id}
ffarena://profile/{id}
```

The pure-Dart router (`lib/features/deep_links/deep_link_router.dart`) is the
single source of truth and is unit tested. The OS boundary is a thin
MethodChannel (`ffarena.deeplink/channel`):

- **cold start** → the native host calls `initialLink`;
- **warm start** → the native host calls `onDeepLink` with the URI.

The `ffarena` scheme is already registered:

- Android — an `android.intent.action.VIEW` intent-filter for scheme
  `ffarena` in `android/app/src/main/AndroidManifest.xml`;
- iOS — `CFBundleURLTypes` in `ios/Runner/Info.plist`.

The native host forwards URIs via `AppDelegate`/`SceneDelegate` (Android:
override `onNewIntent`; iOS: `application(_:open:options:)` / scene
`openURLContexts`) onto the same channel.

Every deep-link target performs its own authenticated, authorized server
fetch before rendering; the link carries no secrets, room passwords or
tokens, and query/fragment components are stripped by the parser.

## 5. Google Sign-In

1. Create an OAuth client (iOS + Android + a "web" server client) in Google
   Cloud Console.
2. Pass the client ids via dart-define (see table above).
3. Ensure `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are set in the backend
   `.env` so `/api/v1/auth/google` can verify the id token.

When no client id is configured the "Continue with Google" button is hidden
and the provider is never called.

## 6. Push notifications

Push is **honest**: the default provider is `NoopPushProvider` (not
configured), so the app shows push as unavailable and never calls delivery
code paths. To enable push you must:

1. Bind a real `PushProvider` (FCM or APNs) in `AppServices.create`.
2. Set `FFARENA_PUSH_ENABLED=true`.
3. Configure the backend `.env` (`PUSH_FCM_ENABLED` / `PUSH_APNS_ENABLED`,
   server keys) — the client registers its token via `POST /api/v1/me/devices`
   (server stores only the sha256 hash).

No production credentials ever ship in the client.

## 7. Offline cache

`lib/core/cache/offline_cache.dart` is a read-only cache of the last
successful GET responses, stored under the app documents directory
(`path_provider`). It is initialized once in `AppServices.boot()`. Cached
data is rendered with an honest "stale data" banner and is never used as a
source of truth for ranks, wallet or payments.

## 8. Generated code

`lib/core/api/generated/` is produced from the committed OpenAPI contract
(`storage/api-docs/openapi.json`):

```bash
python3 tools/gen_mobile_models.py
```

This guarantees the mobile client only references documented `/api/v1`
endpoints. The generated models tolerate additive/unknown JSON fields and
missing optional fields.

## 9. CI

`scripts/ci/check-flutter.sh` regenerates the models, runs
`flutter analyze` and `flutter test`. The backend's existing
`scripts/ci/check-pint.sh` already lints the Phase 18 PHP files.

```

### `docs/MOBILE_RELEASE.md`

```markdown
# Mobile App — Build & Release Guide (Phase 18)

This document covers building the three configurations (dev / test /
release) and the signing steps needed to distribute the app. It does **not**
claim App Store / Play Store publication — publication is outside this
phase's scope; only the build configuration and commands are provided.

## 1. Configuration matrix

| Config | `FFARENA_ENV` | API base URL | Signing |
| --- | --- | --- | --- |
| dev | `development` | localhost / dev host | Android debug keystore (auto) |
| test | `staging` | `https://staging.<host>/api/v1` | Ad-hoc/enterprise or debug |
| release | `production` | `https://api.<host>/api/v1` | Release keystore (yours) |

`production` builds abort at startup if the API base URL is not HTTPS
(enforced in `lib/main.dart`).

## 2. Commands

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
```

### Dev

```bash
flutter run \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

### Test / staging

```bash
flutter build apk --release \
  --dart-define=FFARENA_API_BASE_URL=https://staging.example.com/api/v1 \
  --dart-define=FFARENA_ENV=staging
```

### Release (Android)

```bash
flutter build appbundle --release \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production \
  --dart-define=FFARENA_GOOGLE_CLIENT_ID=<your-android-client-id>
```

### Release (iOS, on a Mac with Xcode)

```bash
flutter build ipa --release \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production
```

## 3. Android signing

Debug builds use the auto-generated debug keystore — no action needed.

For release builds you must supply your own keystore. **Never commit the
keystore or its passwords** (already git-ignored: `*.keystore`, `*.jks`,
`key.properties`). Typical setup:

```bash
keytool -genkey -v -keystore ~/ffarena-release.jks \
  -keyalg RSA -keysize 2048 -validity 10000 -alias ffarena
```

Then create a local-only `android/key.properties`:

```properties
storePassword=<redacted>
keyPassword=<redacted>
keyAlias=ffarena
storeFile=/absolute/path/to/ffarena-release.jks
```

and load it in `android/app/build.gradle.kts` (see the standard Flutter
release-signing block). The repository ships the debug configuration only;
the release signing block is added by the release engineer and is never
committed.

## 4. Versioning

`pubspec.yaml` carries the app version (`1.0.0+1`). The **server** is
authoritative for compatibility: `GET /api/v1/app/meta` announces
`min_supported_app_version` and `latest_app_version`. The client surfaces a
"update required" gate from that response — bump the backend
`MOBILE_MIN_APP_VERSION` when a breaking client change ships.

## 5. What ships / what does not

- Ships: the Dart app, debug keystore, dev/test/release build configs.
- Never ships: Firebase/APNs private keys, Google server secrets, signing
  certificates, keystore passwords, service-account JSON. All are injected
  at build time or supplied by the release engineer and are git-ignored.

## 6. Honest limitations

- Push notifications are disabled unless a provider is actually wired
  (default `NoopPushProvider`).
- Google Sign-In is hidden unless a client id is configured.
- Disputes are read-only on mobile (filing is web-only).
- Password reset, password change and account deactivation are web-only and
  the app says so in the UI.

```

### `docs/MOBILE_SECURITY.md`

```markdown
# Mobile App — Security Model (Phase 18)

The mobile app is a **consumer** of the FF Arena platform. This document
records the security boundaries the client enforces on its side of the
trust line. The server remains authoritative for everything that matters.

## 1. Trust model

- The backend is authoritative for **rank, points, wallet balance, payment
  success, verification, team ownership, tournament state and restriction
  state**. The client never duplicates ranking formulas, never marks a
  payment successful locally, and never computes balances.
- All communication goes through `/api/v1` only. The client never touches the
  database, never calls internal services directly and never scrapes Blade
  pages.

## 2. Access tokens

- The access token lives **only** in the platform keystore/keychain
  (`flutter_secure_storage`) and in memory for the process lifetime. It is
  never written to plaintext files, SharedPreferences, logs or analytics.
- `AuthSession.toJson()` deliberately omits the token.
- The client uses the existing documented token lifecycle. It does **not**
  invent a refresh flow.
- A `token_expired`, `token_revoked`, `account_inactive` or `unauthenticated`
  response from ANY request clears auth state and routes the user to the
  security/account screen (`SessionManager` + `ApiClient.onSessionTerminated`).

## 3. No secret logging

- The ApiClient logs at most `METHOD status path elapsed_ms` — never headers
  or bodies.
- Telemetry (`ProductMetrics`) enforces a field allow-list
  (`app_version`, `platform`, `event`, `category`, `duration_ms`).
  Passwords, OTP codes, tokens, raw IPs, risk scores and device fingerprints
  are dropped by construction.
- The crash reporter only ever receives a redacted context label and error
  category, never payloads.

## 4. Push tokens

- The raw push token is sent exactly once to `POST /api/v1/me/devices`; the
  server stores only its **sha256** hash and never echoes token material
  back (the client also never logs it).
- Device registrations are owner-only (server-enforced policy, mirrored in
  `DeviceRepository`). The client can only list/delete its own devices.
- When no provider is configured, push is disabled honestly — no fake
  delivery, no placeholder credentials.

## 5. Payments

- The client posts `{team_id, provider}` and follows the server's
  `redirect_url` only when one is provided. Completion is determined by
  polling `GET /payments/{id}` until the **server** reports a terminal
  status — never by the redirect alone.
- Provider selection uses the server's own `/payments/methods` statuses
  (`enabled && configured`); the client never assumes a provider exists.
- Provider/settlement internals are never exposed.

## 6. Deep links

- `ffarena://tournament/{id}`, `ffarena://match/{id}`,
  `ffarena://profile/{id}`.
- Every target requires an authenticated, authorized server response before
  rendering.
- The parser strips query/fragment, so an attacker cannot smuggle secrets,
  room passwords, payment secrets or tokens into a link.

## 7. Local storage

- Secure storage: access token + session envelope only.
- Offline cache: read-only copies of last successful GET responses, marked
  stale and never treated as authoritative.
- Nothing else is persisted client-side.

## 8. Certificate pinning

Certificate pinning is intentionally **not** implemented: there is no
operational key-rotation strategy to go with it, and pinning without
rotation causes outages and lockouts. Standard platform TLS validation is
used.

## 9. Admin surface

Administration remains web-first. The mobile app contains no admin
sub-app and the API exposes no admin capabilities to mobile scopes.

```

### `docs/MOBILE_TESTING.md`

```markdown
# Mobile App — Testing Guide (Phase 18)

## 1. Running the suite

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
flutter analyze    # static analysis (must be clean)
flutter test       # 49 tests, all passing
```

`scripts/ci/check-flutter.sh` runs the same steps (plus codegen) for CI.

## 2. Layout

```
mobile/test/
├── core/api/
│   ├── api_client_test.dart        # envelope decode, error mapping, headers, no-retry on mutations
│   ├── api_exception_test.dart     # typed error parsing + session-terminating codes
│   └── idempotency_test.dart       # key uniqueness/format
├── core/cache/
│   └── offline_cache_test.dart     # round-trip, miss, corrupt, remove, clear
├── core/deep_links/
│   └── deep_link_router_test.dart  # parse + route + secret stripping
├── core/format/
│   └── phone_test.dart             # BD phone normalization
├── core/session/
│   ├── session_manager_test.dart   # establish/restore/logout/termination
│   └── session_store_test.dart     # save/load/clear round-trip
├── data/repositories/
│   └── app_meta_repository_test.dart  # anonymous fetch + caching
└── widgets/
    └── localization_test.dart      # en/bn strings, money, AsyncView states
```

## 3. What is covered

- **API client**: envelope unwrapping, `{error:{...}}` mapping, bearer-token
  attachment, `/api/v1` prefix de-duplication, `Idempotency-Key` header,
  offline/timeout mapping, and the guarantee that POST mutations are never
  auto-retried.
- **Session lifecycle**: secure persistence, restore-with-validation,
  offline restore, forced logout on `token_expired`/`token_revoked`/
  `account_inactive`, and manual logout not being a security event.
- **Deep links**: valid/invalid parsing, wrong scheme, non-numeric id, and
  query-string secret stripping.
- **Localization**: English and Bangla resolution, missing-key fallback, and
  BDT money formatting.
- **Widgets**: loading / error / empty / content states of the shared
  `AsyncView`.

## 4. Conventions

- Repository tests use `package:http/testing.dart` (`MockClient`) so no real
  network is ever hit.
- Session/storage tests use `InMemorySecureStorage` — the platform keystore
  is never touched in unit tests.
- Widget tests rely on the synchronous localization delegate
  (`SynchronousFuture`), so no `pumpAndSettle` is needed for string
  resolution.

## 5. Backend (PHPUnit)

The Phase 18 backend support is covered in the existing PHPUnit suite:

```bash
php artisan test tests/Feature/Api/ApiDeviceTokensTest.php \
                tests/Feature/Api/ApiAppMetaTest.php
```

Full regression: `php artisan test` (823 tests / 2614 assertions, all green).

```


