# PHASE 10 — ANTI-FRAUD / ANTI-CHEAT / IDENTITY / DEVICE-IP / BAN-EVASION
## FF Arena — Production-Grade Defensive Trust & Safety Foundation

**Date:** 2026-09-07 (Asia/Dhaka)
**Stack:** Laravel 12 · SQLite (portable to MySQL/PostgreSQL) · server-rendered Blade
**Scope:** Phase 10 only. Phases 01–09 behavior is fully preserved; nothing was rebuilt or weakened.

---

## 1. Objective

Layered, **defensive-only** anti-fraud and anti-cheat foundation on top of the
existing FF Arena platform (Phases 01–09). Every signal is deterministic,
server-derived, and *never* a conviction: strong signals raise risk and (at
most) require manual review or a granular, auditable, revocable restriction.
No automatic permanent bans; no confiscation of legitimate prizes on a signal
alone; no ML; no raw sensitive identifiers stored; no wallet/ledger mutation
from anti-fraud code.

---

## 2. What was built

### 2.1 Data layer (1 new migration, 11 new models)
`database/migrations/2026_09_06_000000_add_anti_fraud.php` adds 11 tables:

| Table | Purpose |
|---|---|
| `risk_profiles` | Per-user `risk_score` (0–100), deterministic `risk_level` (low/medium/high/critical), `account_flags`, `manual_review_required`, `restricted_until`, `status` (active/restricted/suspended). |
| `risk_events` | Append-only (`UPDATED_AT = null`) signal log with `type`, `severity`, `score_contribution`, `source`, JSON `metadata`, optional user/tournament. |
| `devices` / `device_links` | Pseudonymous server-derived device hash (HMAC-SHA256 of observable headers, keyed with APP_KEY) + user associations. No raw fingerprint stored. |
| `ip_intel` / `ip_links` | Hashed full IP + hashed subnet grouping, observation counts, user associations. No raw IPs stored. |
| `account_links` | Canonical-pair account similarity links (weak/moderate/strong) with categorized `reasons` and `source`. |
| `restrictions` | Granular auditable restrictions (7 types) with `reason`, `source`, `actor_id`, `starts_at`, `expires_at`, `status`, `lifted_by`, `lifted_at`. |
| `identity_verifications` | unverified → pending → verified/rejected/expired state machine; `provider`, `reviewed_by`, `verified_at`, `expires_at`, `notes`. |
| `anti_cheat_incidents` | Match/tournament/accused/reporter/category/severity/evidence/status/reviewer/resolution audit trail. |
| `match_anomalies` | Append-only anomaly observations (abnormal_kill_ratio / repeated_pattern / unexpected_participation) with anomaly/suspicious/requires_review statuses. |

### 2.2 Risk rule engine (deterministic, no ML)
`app/Services/FraudRiskService.php` — the single authority for signals, scoring,
levels and actions. Score = `min(100, Σ score_contribution)`; level from
`config('antifraud.risk.thresholds')` (medium 30 / high 60 / critical 90);
severity scores info 0 / low 5 / medium 15 / high 30 / critical 50. Actions per
level: low→allow, medium→flag, high→require_review, critical→restrict.

### 2.3 Privacy-conscious device / IP architecture
- `DeviceFingerprintService` — server-derived pseudonymous hash; never trusts a
  client "trusted device" flag; shared-device signals only above configured
  tolerance; a shared device is a *signal*, never an auto-ban.
- `IpIntelligenceService` — HMAC of full IP + HMAC of subnet; observation
  counts; shared-network tolerance (default 20 accounts) so NATs/cafés are not
  false-flagged; raw IPs never persisted or shown to players.

### 2.4 Account similarity + ban evasion
`AccountLinkService` — canonical-pair links with strength upgrade (never
downgrade), categorized reasons; `detectBanEvasion` records a ban-evasion
signal and requires manual review for a *strong* link to a currently restricted
account. Weak/moderate signals never auto-restrict.

### 2.5 Identity verification
`IdentityVerificationService` + `IdentityVerificationProviderInterface` +
`ManualIdentityProvider` — honest state machine. `verified` is reachable **only**
via explicit admin manual review (or a future real KYC adapter); the manual
adapter returns `pending` and never fabricates a provider success. Lazy expiry
of `verified` → `expired`.

### 2.6 Anti-cheat incidents + match anomalies
`AntiCheatService` — incident workflow `flagged → under_review →
cleared/confirmed/restricted/dismissed`; confirmed/restricted outcomes apply
auditable restrictions via `RestrictionService`; cleared/dismissed are
false-positive-safe. `MatchAnomaly` observations are deterministic and are
**never** auto-labelled "cheating".

### 2.7 Enforcement gates (high-value actions)
`FraudRiskService::gate(user, context)` enforces restriction types per context
and the risk-level action. Wired into:
- Registration (`TeamController@register`) and check-in (`TeamController@checkIn`)
- Score submission (`MatchController@submitScore`) + deterministic anomaly analysis
- Dispute creation signal (`DisputeController@store`)
- Payment creation (`PaymentController@verify`) + failed-payment signal (`AdminController@failPayment`)
- Payout processing (`PayoutService::process`) — held, never auto-failed, with audited `processWithOverride`
- Prize distribution (`PrizeDistributionService::process`) — holds without failing
- Device/IP observation on auth (`AuthController@register`/`login`)

### 2.8 Admin/moderation UI
- `SecurityController` + five `admin/security/*` Blade views (dashboard, users,
  investigation detail, events, incidents).
- `ModerationController@security` — read-only staff review (admin + moderator).
- Wallet page shows the user's own identity-verification status + self-service
  request (players never see anyone else's risk/device/IP data).

### 2.9 Authorization
- `RiskProfilePolicy` (admins manage; admins/moderators view; users only ever see
  their own summary — never raw device/IP internals).
- `AntiCheatIncidentPolicy` (staff review/resolve; organizers view their own
  tournaments' incidents read-only; reporters view their own reports).
- `IdentityVerificationPolicy` (self request only; admin verify/reject).
- Admin security routes are additionally behind the `admin` middleware.

---

## 3. Verification results

| Check | Result |
|---|---|
| `php -l` on every new/modified file | **All clean** |
| `php artisan migrate:fresh --seed --force` | **20 migrations applied, seeded** |
| `php artisan test` (full suite) | **415 passed · 1274 assertions · 0 failures** |
| Phase 10 suites (`--filter=AntiFraud`) | **75 passed · 206 assertions** |
| Phase 01–09 regression (415 − 75) | **340 passed · 1068 assertions** |
| `php artisan route:list` | **97 routes** (82 baseline + 15 Phase 10) |
| HTTP smoke | All target pages verified (see §4) |

### 3.1 Phase 10 test coverage (75 tests)
- **Risk engine** (17): lazy profile creation, deterministic severity scores &
  level thresholds, 100-score cap, append-only recalculation, unknown-severity
  rejection, null-user signals, per-level actions, low/medium/high/critical
  gates, context-specific restriction blocking, restriction reason/type
  validation, suspension freeze + audit, expiry, lift + status restore,
  double-lift rejection, multi-suspension backstop, mass-assignment rejection.
- **Trust & safety** (35): identity state machine (unverified→pending→verified,
  idempotent request, lazy expiry, reject→re-request, verified-not-rejectable,
  manual provider never fabricates, unknown provider), pseudonymous device hash
  determinism, device/link registration, shared-device tolerance + signal-only
  above threshold, device block, pseudonymous IP hash/subnet grouping, IP intel
  without raw IP, shared-network tolerance, canonical non-duplicated account
  links, strength upgrade, self-link + invalid-strength rejection, strong
  ban-evasion (signal + review, never auto-ban), weak-link no-ban-evasion,
  incident category/severity validation, confirmed→audited restriction,
  cleared→false-positive-safe, enforced transitions, anomaly kind validation,
  abnormal-kill-ratio + repeated-pattern detection, no-anomaly normal path.
- **Security/HTTP** (23): guest redirects, player/organizer/moderator blocked
  from admin security, admin dashboard/users/events/detail 200, player cannot
  view others' risk detail, incident queue role gating, organizer cannot open,
  moderator open/review/resolve, player cannot resolve, moderator cannot
  restrict, admin restrict+lift, self identity request, player cannot
  self-verify, admin verify → wallet badge, payout fraud gate (low processes,
  high held-not-failed, authorized override with audited reason, override
  requires reason), suspended-user registration blocked.


---

## 4. HTTP smoke (against seeded dev database)

```
guest /                              → 200
guest /tournaments                   → 200
guest /tournaments/{slug}            → 200
guest /tournaments/{slug}/leaderboard→ 200
guest /login                         → 200
guest /admin/security                → 302 (redirect to login)
guest /wallet                        → 302 (redirect to login)

admin  /admin/security (dashboard)   → 200 "Security Dashboard"
admin  /admin/security/users         → 200 "Suspicious Users"
admin  /admin/security/events        → 200 "Risk Events"
admin  /security/incidents           → 200 "Anti-cheat Incidents"
admin  /moderation/security          → 200 "Security Review"
admin  /wallet                       → 200 "Identity Verification"
admin  /admin/payouts                → 200
admin  /admin/security/users/1       → 200 "Risk Profile"

organizer /admin/security            → 403
organizer /moderation/security       → 403
organizer /security/incidents        → 200 (own tournaments only)
player    /security/incidents        → 403
player    /moderation/security       → 403

admin POST restrict user#3 (dispute_blocked)   → 302, restriction active
admin POST verify identity user#3               → 302, status=verified
admin POST open incident (aimbot/high)          → 302, incident flagged
admin POST review incident#1                    → 302, under_review
admin POST resolve incident#1 (cleared)         → 302, status=cleared
admin POST lift restriction#1                   → 302, active count=0

Post-action DB state: 1 active restriction → lifted; identity verified;
incident flagged→under_review→cleared; 3 risk profiles; 7 risk events;
1 device (3 links); 1 ip_intel (3 links); 3 account links; hashes pseudonymous.
```


---

## 5. Full file contents

> Every new and modified file is reproduced in full below. No placeholders.

### 5.1 New files


#### `database/migrations/2026_09_06_000000_add_anti_fraud.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — anti-fraud + anti-cheat + identity + device/IP + ban-evasion.
     *
     * risk_profiles         : per-user server-side risk profile (score, level,
     *                         flags, manual-review flag, restriction state).
     * risk_events           : append-only suspicious/signal audit events.
     * devices / device_links: pseudonymous device identities (server-derived
     *                         hashes, never raw fingerprints) and their user
     *                         associations.
     * ip_intel / ip_links   : privacy-aware IP intelligence (hashed IPs +
     *                         hashed subnet grouping) and user associations.
     * account_links         : defensive account-similarity links (weak /
     *                         moderate / strong) with reasons.
     * restrictions          : granular, auditable account restrictions.
     * identity_verifications: identity-verification state machine
     *                         (unverified/pending/verified/rejected/expired/
     *                         review_required) with a provider abstraction —
     *                         never fabricated.
     * anti_cheat_incidents  : defensive anti-cheat cases with controlled
     *                         status machine (flagged/under_review/cleared/
     *                         confirmed/restricted/dismissed).
     * match_anomalies       : deterministic, append-only match anomaly
     *                         signals (never auto-labelled "cheating").
     *
     * No existing table or column is modified. No sensitive raw identifiers
     * (raw IPs, raw device fingerprints, documents, credentials) are stored.
     */
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('risk_score')->default(0);
            $table->string('risk_level', 12)->default('low'); // low|medium|high|critical
            $table->json('account_flags')->nullable();
            $table->boolean('manual_review_required')->default(false);
            $table->timestamp('restricted_until')->nullable();
            $table->string('status', 12)->default('active'); // active|restricted|suspended
            $table->timestamp('last_risk_calculation_at')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('severity', 12); // info|low|medium|high|critical
            $table->unsignedInteger('score_contribution')->default(0);
            $table->string('source', 20);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id', 'risk_events_user_index');
            $table->index('type', 'risk_events_type_index');
            $table->index('severity', 'risk_events_severity_index');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_hash', 64)->unique();
            $table->string('status', 12)->default('active'); // active|blocked
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'user_id'], 'device_links_device_user_unique');
        });

        Schema::create('ip_intel', function (Blueprint $table) {
            $table->id();
            $table->string('ip_hash', 64)->unique();
            $table->string('subnet_hash', 64)->index();
            $table->unsignedInteger('observation_count')->default(0);
            $table->unsignedInteger('suspicious_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_intel_id')->constrained('ip_intel')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['ip_intel_id', 'user_id'], 'ip_links_ip_user_unique');
        });

        Schema::create('account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('strength', 12); // weak|moderate|strong
            $table->json('reasons')->nullable();
            $table->string('source', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'linked_user_id'], 'account_links_pair_unique');
            $table->index('user_id', 'account_links_user_index');
            $table->index('linked_user_id', 'account_links_linked_index');
        });

        Schema::create('restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('reason');
            $table->string('source', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('active'); // active|lifted
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'restrictions_user_index');
            $table->index(['type', 'status'], 'restrictions_type_status_index');
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('unverified'); // unverified|pending|verified|rejected|expired|review_required
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('anti_cheat_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('accused_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20); // system|participant|staff
            $table->string('category', 30);
            $table->string('severity', 12); // low|medium|high|critical
            $table->text('description')->nullable();
            $table->string('evidence_reference', 120)->nullable();
            $table->string('status', 16)->default('flagged'); // flagged|under_review|cleared|confirmed|restricted|dismissed
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tournament_id', 'anti_cheat_tournament_index');
            $table->index('status', 'anti_cheat_status_index');
        });

        Schema::create('match_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40); // abnormal_kill_ratio|repeated_pattern|unexpected_participation
            $table->string('severity', 12); // anomaly|suspicious|requires_review
            $table->string('status', 12)->default('anomaly'); // anomaly|suspicious|requires_review
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('match_id', 'match_anomalies_match_index');
            $table->index('tournament_id', 'match_anomalies_tournament_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_anomalies');
        Schema::dropIfExists('anti_cheat_incidents');
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('restrictions');
        Schema::dropIfExists('account_links');
        Schema::dropIfExists('ip_links');
        Schema::dropIfExists('ip_intel');
        Schema::dropIfExists('device_links');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('risk_profiles');
    }
};
```


#### `config/antifraud.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anti-fraud / trust & safety (Phase 10)
    |--------------------------------------------------------------------------
    |
    | Server-side configuration for the defensive fraud & anti-cheat layer.
    | Every threshold below is a risk *signal*, never an automatic conviction:
    | strong signals raise risk and (at most) require manual review. Nothing
    | here ever permanently bans an account on its own — restrictions are
    | granular, auditable and always revocable by an admin.
    |
    */

    'risk' => [
        // Deterministic level bands by score.
        'thresholds' => [
            'medium' => 30,
            'high' => 60,
            'critical' => 90,
        ],

        // Default action per risk level. Actions:
        //   allow         — proceed silently
        //   flag          — proceed + record an informational audit signal
        //   require_review— proceed + flag the account for manual review
        //   restrict      — block the action (server-enforced)
        'actions' => [
            'low' => 'allow',
            'medium' => 'flag',
            'high' => 'require_review',
            'critical' => 'restrict',
        ],

        // Score contribution per event severity (score is capped at 100).
        'severity_scores' => [
            'info' => 0,
            'low' => 5,
            'medium' => 15,
            'high' => 30,
            'critical' => 50,
        ],
    ],

    'device' => [
        // Distinct accounts on one device before a shared-device signal.
        'max_accounts_shared' => 4,
        // Distinct accounts on one device before a strong signal.
        'strong_accounts_shared' => 8,
    ],

    'ip' => [
        // Shared networks (NAT, carriers, cafés) legitimately host many
        // users — a high tolerance avoids false positives.
        'max_accounts_shared' => 20,
    ],

    'payment' => [
        // Failed payments on an account before a repeat-failure signal.
        'failed_attempts_threshold' => 3,
        // Payment intents created by an account before a churn signal.
        'attempts_threshold' => 10,
    ],

    'registration' => [
        // Team registrations by one account before a volume signal.
        'max_teams' => 5,
    ],

    'payout' => [
        // Actions by recipient risk level when a payout is processed.
        // 'require_review' and 'restrict' both hold the payout pending an
        // authorized override; the difference is only severity of the flag.
        'actions' => [
            'low' => 'allow',
            'medium' => 'allow',
            'high' => 'require_review',
            'critical' => 'restrict',
        ],
        // Completed payouts by one recipient before a repeat-win signal.
        'repeat_wins_threshold' => 3,
    ],

    'dispute' => [
        // Disputes opened by one account before an abuse signal.
        'repeat_threshold' => 3,
    ],

    'withdrawal' => [
        // Team withdrawals by one account before a churn signal.
        'repeat_threshold' => 3,
    ],

    'ban_evasion' => [
        // The minimum account-link strength that triggers a ban-evasion
        // review signal for a previously restricted account.
        'min_strength' => 'strong',
        // Automated action on a strong ban-evasion match. Never a
        // permanent auto-ban: require_review is the strongest default.
        'auto_action' => 'require_review',
    ],

    'anomaly' => [
        // Kills in a single match above this are statistically anomalous.
        'max_kills_per_match' => 60,
        // Identical (kills, placement) submissions by one team before a
        // repeated-pattern anomaly.
        'repeat_pattern_threshold' => 3,
    ],
];
```


#### `app/Models/RiskProfile.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's server-side fraud/risk profile (Phase 10).
 *
 * The score and level are recomputed deterministically by FraudRiskService
 * from the append-only risk_events; no client may set or alter them. Only
 * trusted services and admin actions modify these fields.
 */
class RiskProfile extends Model
{
    use HasFactory;

    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';
    public const LEVEL_CRITICAL = 'critical';

    public const LEVELS = [
        self::LEVEL_LOW,
        self::LEVEL_MEDIUM,
        self::LEVEL_HIGH,
        self::LEVEL_CRITICAL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [];

    protected $casts = [
        'risk_score' => 'integer',
        'account_flags' => 'array',
        'manual_review_required' => 'boolean',
        'restricted_until' => 'datetime',
        'last_risk_calculation_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function levelPill(): string
    {
        return match ($this->risk_level) {
            self::LEVEL_CRITICAL => 'failed',
            self::LEVEL_HIGH => 'disputed',
            self::LEVEL_MEDIUM => 'pending',
            default => 'confirmed',
        };
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isCurrentlyRestricted(): bool
    {
        if ($this->status === self::STATUS_RESTRICTED || $this->status === self::STATUS_SUSPENDED) {
            return true;
        }

        return $this->restricted_until !== null && $this->restricted_until->isFuture();
    }
}
```


#### `app/Models/RiskEvent.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, append-only fraud/risk signal (Phase 10).
 *
 * Each event carries a type, severity, deterministic score contribution,
 * source and (optionally) the tournament it relates to. Events are only ever
 * created by FraudRiskService; they are never edited or deleted, which keeps
 * the risk score fully reproducible.
 */
class RiskEvent extends Model
{
    use HasFactory;

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    // Event types.
    public const TYPE_AUTH_DEVICE_SHARED = 'auth.device_shared';
    public const TYPE_AUTH_IP_SHARED = 'auth.ip_shared';
    public const TYPE_BAN_EVASION = 'auth.ban_evasion';
    public const TYPE_ACCOUNT_LINKED = 'account.linked';
    public const TYPE_ACCOUNT_RESTRICTED = 'account.restricted';
    public const TYPE_REGISTRATION_VOLUME = 'account.registration_volume';
    public const TYPE_WITHDRAWAL_REPEAT = 'account.withdrawal_repeat';
    public const TYPE_PAYMENT_FAILED = 'payment.failed';
    public const TYPE_PAYMENT_REPEAT = 'payment.repeat';
    public const TYPE_PAYOUT_RECIPIENT = 'payout.recipient_risk';
    public const TYPE_PRIZE_WIN_PATTERN = 'prize.win_pattern';
    public const TYPE_DISPUTE_REPEAT = 'dispute.repeat';
    public const TYPE_MATCH_ANOMALY = 'match.anomaly';
    public const TYPE_ANTI_CHEAT_CONFIRMED = 'anti_cheat.confirmed';
    public const TYPE_RISK_FLAG = 'risk.flag';
    public const TYPE_RISK_REVIEW = 'risk.review_required';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'score_contribution' => 'integer',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
```


#### `app/Models/Device.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A pseudonymous device identity (Phase 10).
 *
 * Only a server-derived cryptographic hash is stored — never raw
 * fingerprints, user-agents or other invasive identifiers. A device's
 * association with many accounts is a risk signal, not proof of abuse.
 */
class Device extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function links()
    {
        return $this->hasMany(DeviceLink::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'device_links', 'device_id', 'user_id')
            ->withTimestamps();
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
```


#### `app/Models/DeviceLink.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An association between a user and a pseudonymous device (Phase 10).
 */
class DeviceLink extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```


#### `app/Models/IpIntel.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Privacy-aware IP intelligence (Phase 10).
 *
 * Only cryptographic hashes are stored (the full address hash plus a subnet
 * hash for grouping) — raw IP addresses are never persisted and are never
 * shown to ordinary users. Many legitimate networks (carriers, NAT, cafés,
 * universities, VPNs) contain many users, so an IP is a risk signal, never
 * proof of abuse.
 */
class IpIntel extends Model
{
    use HasFactory;

    protected $table = 'ip_intel';

    protected $fillable = [];

    protected $casts = [
        'observation_count' => 'integer',
        'suspicious_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function links()
    {
        return $this->hasMany(IpLink::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'ip_links', 'ip_intel_id', 'user_id')
            ->withTimestamps();
    }
}
```


#### `app/Models/IpLink.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An association between a user and a hashed IP observation (Phase 10).
 */
class IpLink extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function ipIntel()
    {
        return $this->belongsTo(IpIntel::class, 'ip_intel_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```


#### `app/Models/AccountLink.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A defensive account-similarity link between two accounts (Phase 10).
 *
 * Pairs are stored in canonical order (lower user id first) with a unique
 * constraint, so the same pair can never be recorded twice. Links carry a
 * confidence (weak/moderate/strong) and the reason categories, never raw
 * sensitive matching data.
 */
class AccountLink extends Model
{
    use HasFactory;

    public const STRENGTH_WEAK = 'weak';
    public const STRENGTH_MODERATE = 'moderate';
    public const STRENGTH_STRONG = 'strong';

    public const STRENGTHS = [
        self::STRENGTH_WEAK,
        self::STRENGTH_MODERATE,
        self::STRENGTH_STRONG,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'reasons' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function linkedUser()
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
```


#### `app/Models/Restriction.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A granular, auditable account restriction (Phase 10).
 *
 * Restrictions are specific (a type, a reason, an actor, an optional
 * expiry) rather than a single global "ban" flag, and are always revocable.
 * The enforcement of each type is performed by RestrictionService / the
 * FraudRiskService gates — never by trusting the client.
 */
class Restriction extends Model
{
    use HasFactory;

    public const TYPE_REGISTRATION_BLOCKED = 'registration_blocked';
    public const TYPE_CHECKIN_BLOCKED = 'checkin_blocked';
    public const TYPE_SCORE_SUBMISSION_BLOCKED = 'score_submission_blocked';
    public const TYPE_DISPUTE_BLOCKED = 'dispute_blocked';
    public const TYPE_PAYOUT_REVIEW = 'payout_review';
    public const TYPE_TOURNAMENT_PARTICIPATION_BLOCKED = 'tournament_participation_blocked';
    public const TYPE_ACCOUNT_SUSPENDED = 'account_suspended';

    public const TYPES = [
        self::TYPE_REGISTRATION_BLOCKED,
        self::TYPE_CHECKIN_BLOCKED,
        self::TYPE_SCORE_SUBMISSION_BLOCKED,
        self::TYPE_DISPUTE_BLOCKED,
        self::TYPE_PAYOUT_REVIEW,
        self::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
        self::TYPE_ACCOUNT_SUSPENDED,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LIFTED = 'lifted';

    protected $fillable = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function liftedBy()
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function typeLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->type));
    }
}
```


#### `app/Models/IdentityVerification.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's identity-verification state (Phase 10).
 *
 * Controlled state machine:
 *
 *   unverified → pending → verified / rejected / review_required
 *   verified   → expired (when expires_at passes)
 *   rejected / review_required → pending (re-request) / verified (review)
 *
 * The application never fabricates provider verification: `verified` is only
 * ever reached through an explicit admin manual review (provider = manual) or,
 * in the future, a real provider adapter. All fields are server-controlled.
 */
class IdentityVerification extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_REVIEW_REQUIRED,
    ];

    protected $fillable = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_VERIFIED => 'confirmed',
            self::STATUS_REJECTED, self::STATUS_EXPIRED => 'failed',
            self::STATUS_REVIEW_REQUIRED => 'disputed',
            self::STATUS_PENDING => 'pending',
            default => 'draft',
        };
    }
}
```


#### `app/Models/AntiCheatIncident.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A defensive anti-cheat incident/case (Phase 10).
 *
 * Controlled state machine:
 *
 *   flagged → under_review → cleared | confirmed | restricted | dismissed
 *
 * A `confirmed` or `restricted` outcome applies an auditable account
 * restriction via RestrictionService; a `cleared`/`dismissed` outcome is a
 * false-positive-safe close. The actual moderation decision is always made
 * by an authorized reviewer, never by an automated rule alone.
 */
class AntiCheatIncident extends Model
{
    use HasFactory;

    public const STATUS_FLAGGED = 'flagged';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_FLAGGED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_CLEARED,
        self::STATUS_CONFIRMED,
        self::STATUS_RESTRICTED,
        self::STATUS_DISMISSED,
    ];

    public const TRANSITIONS = [
        self::STATUS_FLAGGED => [self::STATUS_UNDER_REVIEW, self::STATUS_DISMISSED],
        self::STATUS_UNDER_REVIEW => [
            self::STATUS_CLEARED,
            self::STATUS_CONFIRMED,
            self::STATUS_RESTRICTED,
            self::STATUS_DISMISSED,
        ],
        self::STATUS_CLEARED => [],
        self::STATUS_CONFIRMED => [],
        self::STATUS_RESTRICTED => [],
        self::STATUS_DISMISSED => [],
    ];

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PARTICIPANT = 'participant';
    public const SOURCE_STAFF = 'staff';

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const RESOLUTIONS = [
        self::STATUS_CLEARED,
        self::STATUS_CONFIRMED,
        self::STATUS_RESTRICTED,
        self::STATUS_DISMISSED,
    ];

    protected $fillable = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function accusedUser()
    {
        return $this->belongsTo(User::class, 'accused_user_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_FLAGGED, self::STATUS_UNDER_REVIEW], true);
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_CONFIRMED, self::STATUS_RESTRICTED => 'failed',
            self::STATUS_CLEARED, self::STATUS_DISMISSED => 'confirmed',
            self::STATUS_UNDER_REVIEW => 'live',
            default => 'pending',
        };
    }
}
```


#### `app/Models/MatchAnomaly.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A deterministic, append-only match anomaly signal (Phase 10).
 *
 * Anomalies are statistical observations (anomaly / suspicious /
 * requires_review), never automatic accusations of cheating. They are
 * recorded by AntiCheatService from server-side score analysis and feed the
 * moderation workflow as review input.
 */
class MatchAnomaly extends Model
{
    use HasFactory;

    public const SEVERITY_ANOMALY = 'anomaly';
    public const SEVERITY_SUSPICIOUS = 'suspicious';
    public const SEVERITY_REQUIRES_REVIEW = 'requires_review';

    public const SEVERITIES = [
        self::SEVERITY_ANOMALY,
        self::SEVERITY_SUSPICIOUS,
        self::SEVERITY_REQUIRES_REVIEW,
    ];

    public const KIND_ABNORMAL_KILL_RATIO = 'abnormal_kill_ratio';
    public const KIND_REPEATED_PATTERN = 'repeated_pattern';
    public const KIND_UNEXPECTED_PARTICIPATION = 'unexpected_participation';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
```


#### `app/Contracts/IdentityVerificationProviderInterface.php`

```php
<?php

namespace App\Contracts;

use App\Models\User;
use DomainException;

/**
 * Provider abstraction for identity verification (Phase 10).
 *
 * The application never fabricates a successful verification: adapters must
 * honestly report what they can do. Today the only adapter is the manual
 * review provider, which returns `pending` and requires an admin to complete
 * the review. A real KYC provider can be added later without changing the
 * domain.
 */
interface IdentityVerificationProviderInterface
{
    /**
     * The stable provider identifier (stored on identity_verifications.provider).
     */
    public function id(): string;

    /**
     * Whether this provider can perform automated (provider-confirmed)
     * verification. The manual provider cannot.
     */
    public function supportsAutomatedVerification(): bool;

    /**
     * Initiate a verification for the user.
     *
     * @return array{status: string, provider_reference: ?string}
     *
     * @throws DomainException when the provider cannot actually verify.
     */
    public function request(User $user): array;
}
```


#### `app/Gateways/ManualIdentityProvider.php`

```php
<?php

namespace App\Gateways;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Models\User;

/**
 * Manual identity-review adapter (Phase 10).
 *
 * No external KYC provider is configured in this project, so this adapter is
 * deliberately honest: it never reports an automated verification and instead
 * returns `pending`, leaving the decision to an admin's manual review
 * (IdentityVerificationService::verifyManually). When a real KYC provider is
 * integrated, it becomes another adapter behind the same interface.
 */
class ManualIdentityProvider implements IdentityVerificationProviderInterface
{
    public function id(): string
    {
        return 'manual';
    }

    public function supportsAutomatedVerification(): bool
    {
        return false;
    }

    public function request(User $user): array
    {
        return [
            'status' => 'pending',
            'provider_reference' => null,
        ];
    }
}
```


#### `app/Services/FraudRiskService.php`

```php
<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Payment;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The fraud/risk rule engine (Phase 10).
 *
 * The single place where risk signals are recorded, scores are calculated,
 * levels are derived and actions are determined. Controllers call this
 * service's gates; they never compute risk themselves.
 *
 * Scoring is fully deterministic: score = min(100, Σ event score_contribution);
 * the level is derived from the configured thresholds. Defaults are
 * conservative — a fresh account is `low` and every action is allowed.
 */
class FraudRiskService
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_FLAG = 'flag';
    public const ACTION_REQUIRE_REVIEW = 'require_review';
    public const ACTION_RESTRICT = 'restrict';

    /**
     * Get (or lazily create) a user's risk profile.
     */
    public function profileFor(User $user): RiskProfile
    {
        $profile = $user->riskProfile()->first();

        if ($profile !== null) {
            return $profile;
        }

        $profile = new RiskProfile();
        $profile->user_id = $user->id;
        $profile->risk_score = 0;
        $profile->risk_level = RiskProfile::LEVEL_LOW;
        $profile->status = RiskProfile::STATUS_ACTIVE;
        $profile->save();

        return $profile;
    }

    /**
     * Record an immutable risk signal and recalculate the user's score.
     * Null users are accepted (signals with no attributable account) and
     * simply persist the event.
     */
    public function recordSignal(
        ?User $user,
        string $type,
        string $severity,
        string $source,
        array $metadata = [],
        ?Tournament $tournament = null,
    ): RiskEvent {
        if (! in_array($severity, RiskEvent::SEVERITIES, true)) {
            throw new DomainException('Unknown risk severity.');
        }

        $score = $this->scoreForSeverity($severity);

        return DB::transaction(function () use ($user, $type, $severity, $source, $metadata, $tournament, $score) {
            $event = new RiskEvent();
            $event->user_id = $user?->id;
            $event->tournament_id = $tournament?->id;
            $event->type = $type;
            $event->severity = $severity;
            $event->score_contribution = $score;
            $event->source = $source;
            $event->metadata = $metadata;
            $event->save();

            if ($user !== null) {
                $this->recalculate($user);
            }

            return $event;
        });
    }

    /**
     * Deterministically recompute a user's risk score and level.
     */
    public function recalculate(User $user): RiskProfile
    {
        $profile = $this->profileFor($user);

        $score = (int) RiskEvent::where('user_id', $user->id)->sum('score_contribution');
        $score = min(100, $score);

        $profile->risk_score = $score;
        $profile->risk_level = $this->levelFromScore($score);
        $profile->last_risk_calculation_at = now();
        $profile->save();

        return $profile;
    }

    /**
     * Map a score to a deterministic risk level.
     */
    public function levelFromScore(int $score): string
    {
        $thresholds = config('antifraud.risk.thresholds', [
            'medium' => 30,
            'high' => 60,
            'critical' => 90,
        ]);

        if ($score >= (int) $thresholds['critical']) {
            return RiskProfile::LEVEL_CRITICAL;
        }

        if ($score >= (int) $thresholds['high']) {
            return RiskProfile::LEVEL_HIGH;
        }

        if ($score >= (int) $thresholds['medium']) {
            return RiskProfile::LEVEL_MEDIUM;
        }

        return RiskProfile::LEVEL_LOW;
    }

    /**
     * Default score contribution for a severity label.
     */
    public function scoreForSeverity(string $severity): int
    {
        $map = config('antifraud.risk.severity_scores', [
            'info' => 0,
            'low' => 5,
            'medium' => 15,
            'high' => 30,
            'critical' => 50,
        ]);

        return (int) ($map[$severity] ?? 0);
    }

    /**
     * The configured action for a user's current risk level.
     */
    public function actionFor(User $user): string
    {
        $profile = $this->profileFor($user);

        $actions = config('antifraud.risk.actions', [
            'low' => 'allow',
            'medium' => 'flag',
            'high' => 'require_review',
            'critical' => 'restrict',
        ]);

        return (string) ($actions[$profile->risk_level] ?? self::ACTION_ALLOW);
    }

    /**
     * Server-side enforcement gate for a protected action context.
     *
     * Contexts map to the restriction types that block them. `restrict`
     * actions (or any matching active restriction) throw; `flag` /
     * `require_review` record an audit signal without blocking.
     *
     * @throws DomainException when the action is blocked.
     */
    public function gate(User $user, string $context, ?Tournament $tournament = null, ?array $restrictionTypes = null): string
    {
        $types = $restrictionTypes ?? $this->restrictionTypesFor($context);

        if ($types !== [] && app(RestrictionService::class)->isBlocked($user, $types)) {
            throw new DomainException('This account is restricted from this action.');
        }

        $action = $this->actionFor($user);

        if ($action === self::ACTION_RESTRICT) {
            throw new DomainException('This action requires fraud review before it can proceed.');
        }

        if ($action === self::ACTION_REQUIRE_REVIEW) {
            $this->flagForReview($user, $context, $tournament);
        } elseif ($action === self::ACTION_FLAG) {
            $this->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'risk', ['context' => $context], $tournament);
        }

        return $action;
    }

    /**
     * Mark a profile for manual review (idempotent) and audit the trigger.
     */
    public function flagForReview(User $user, string $context, ?Tournament $tournament = null): RiskProfile
    {
        $profile = $this->profileFor($user);

        if (! $profile->manual_review_required) {
            $profile->manual_review_required = true;
            $profile->save();
        }

        $this->recordSignal($user, RiskEvent::TYPE_RISK_REVIEW, RiskEvent::SEVERITY_INFO, 'risk', ['context' => $context], $tournament);

        return $profile;
    }

    // ------------------------------------------------------------------
    // Context-specific evaluations
    // ------------------------------------------------------------------

    /**
     * Evaluate tournament registration for a user.
     */
    public function evaluateRegistration(Tournament $tournament, User $user): string
    {
        return $this->gate($user, 'registration', $tournament);
    }

    /**
     * Evaluate a payment attempt for a user.
     */
    public function evaluatePayment(User $user, ?Tournament $tournament = null): string
    {
        return $this->gate($user, 'payment', $tournament);
    }

    /**
     * Evaluate a payout for its recipient.
     *
     * @return string allow | require_review | restrict
     */
    public function evaluatePayout(Payout $payout): string
    {
        $user = $payout->recipient;

        if ($user === null) {
            return self::ACTION_ALLOW;
        }

        // Repeated-win pattern check (deterministic, non-confiscatory).
        $wins = Payout::where('recipient_user_id', $user->id)
            ->where('status', Payout::STATUS_COMPLETED)
            ->count();

        $threshold = (int) config('antifraud.payout.repeat_wins_threshold', 3);

        if ($wins >= $threshold) {
            $this->recordSignal($user, RiskEvent::TYPE_PRIZE_WIN_PATTERN, RiskEvent::SEVERITY_MEDIUM, 'payout', [
                'payout_id' => $payout->id,
                'completed_wins' => $wins,
            ], $payout->tournament);
        }

        $actions = config('antifraud.payout.actions', [
            'low' => 'allow',
            'medium' => 'allow',
            'high' => 'require_review',
            'critical' => 'restrict',
        ]);

        $profile = $this->profileFor($user);

        $action = (string) ($actions[$profile->risk_level] ?? self::ACTION_ALLOW);

        if ($action !== self::ACTION_ALLOW) {
            $this->recordSignal($user, RiskEvent::TYPE_PAYOUT_RECIPIENT, RiskEvent::SEVERITY_INFO, 'payout', [
                'payout_id' => $payout->id,
                'risk_level' => $profile->risk_level,
                'action' => $action,
            ], $payout->tournament);

            // A held payout always flags the recipient for manual review.
            $this->flagForReview($user, 'payout', $payout->tournament);
        }

        return $action;
    }

    /**
     * Record a failed payment signal for a payer (additive; never mutates
     * the Phase 08 payment/wallet state).
     */
    public function recordPaymentFailure(Payment $payment): void
    {
        $user = $payment->payer;

        if ($user === null) {
            return;
        }

        $this->recordSignal($user, RiskEvent::TYPE_PAYMENT_FAILED, RiskEvent::SEVERITY_LOW, 'payment', [
            'payment_id' => $payment->id,
        ], $payment->tournament);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The restriction types that block each action context.
     *
     * @return string[]
     */
    protected function restrictionTypesFor(string $context): array
    {
        return match ($context) {
            'registration', 'payment' => [
                \App\Models\Restriction::TYPE_REGISTRATION_BLOCKED,
                \App\Models\Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'checkin' => [
                \App\Models\Restriction::TYPE_CHECKIN_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'score_submission' => [
                \App\Models\Restriction::TYPE_SCORE_SUBMISSION_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'dispute' => [
                \App\Models\Restriction::TYPE_DISPUTE_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            default => [
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
        };
    }
}
```


#### `app/Services/DeviceFingerprintService.php`

```php
<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-conscious device identity + sharing detection (Phase 10).
 *
 * A device is a server-derived pseudonymous hash of the request's observable
 * client headers (HMAC-SHA256 keyed with APP_KEY) — the client can never
 * self-declare a "trusted device", and no raw fingerprint is stored.
 *
 * A device shared by many accounts is a *signal*: legitimate shared devices
 * (family computers, cyber cafés) are tolerated up to the configured
 * thresholds; only above them is a risk signal raised. New accounts on a
 * device that already belongs to a restricted account receive a
 * ban-evasion signal via AccountLinkService.
 */
class DeviceFingerprintService
{
    public function __construct(
        protected FraudRiskService $risk,
        protected AccountLinkService $links,
    ) {
    }

    /**
     * Derive the pseudonymous device hash from the request (server-side only).
     */
    public function hashFrom(Request $request): string
    {
        $ua = trim((string) $request->userAgent());
        $language = trim((string) $request->header('Accept-Language', ''));

        return hash_hmac('sha256', ($ua ?: 'unknown') . '|' . $language, (string) config('app.key'));
    }

    /**
     * Register (or refresh) a user's device association and evaluate
     * device-sharing signals. Never throws — observation only.
     */
    public function register(Request $request, User $user): Device
    {
        $hash = $this->hashFrom($request);

        return DB::transaction(function () use ($hash, $user) {
            $device = Device::where('device_hash', $hash)->lockForUpdate()->first();

            if ($device === null) {
                $device = new Device();
                $device->device_hash = $hash;
                $device->status = Device::STATUS_ACTIVE;
                $device->first_seen_at = now();
                $device->last_seen_at = now();
                $device->save();
            } else {
                $device->last_seen_at = now();
                $device->save();
            }

            $link = DeviceLink::where('device_id', $device->id)
                ->where('user_id', $user->id)
                ->first();

            if ($link === null) {
                $link = new DeviceLink();
                $link->device_id = $device->id;
                $link->user_id = $user->id;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            } else {
                $link->last_seen_at = now();
                $link->save();
            }

            // Account-similarity: link this account to the device's other
            // accounts (moderate confidence).
            $others = DeviceLink::where('device_id', $device->id)
                ->where('user_id', '!=', $user->id)
                ->pluck('user_id');

            foreach ($others as $otherId) {
                $other = User::find($otherId);
                if ($other !== null) {
                    $this->links->link($user, $other, 'moderate', ['shared_device'], 'device');
                }
            }

            // Shared-device signals (only above the configured tolerance).
            $distinct = DeviceLink::where('device_id', $device->id)->count();

            $maxShared = (int) config('antifraud.device.max_accounts_shared', 4);
            $strongShared = (int) config('antifraud.device.strong_accounts_shared', 8);

            if ($distinct > $strongShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_HIGH, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            } elseif ($distinct > $maxShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_MEDIUM, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            }

            return $device;
        });
    }

    /**
     * Devices associated with a user.
     */
    public function devicesFor(User $user)
    {
        return Device::query()
            ->whereHas('links', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('links')
            ->get();
    }

    /**
     * Distinct users associated with a device.
     */
    public function usersFor(Device $device)
    {
        return $device->users()->get();
    }

    /**
     * Mark a device blocked (e.g. after a confirmed anti-cheat incident).
     */
    public function block(Device $device): Device
    {
        $device->status = Device::STATUS_BLOCKED;
        $device->save();

        return $device;
    }
}
```


#### `app/Services/IpIntelligenceService.php`

```php
<?php

namespace App\Services;

use App\Models\IpIntel;
use App\Models\IpLink;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-aware IP intelligence (Phase 10).
 *
 * Raw IP addresses are never persisted: observations store an HMAC of the
 * address and an HMAC of its subnet (for grouping). Shared networks (NAT,
 * carriers, cafés, VPNs) are tolerated — an IP becomes a weak signal only
 * above a high threshold, and is never treated as proof of abuse.
 */
class IpIntelligenceService
{
    public function __construct(
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * Pseudonymous hash of an IP address.
     */
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', trim($ip), (string) config('app.key'));
    }

    /**
     * Pseudonymous hash of an IP's grouping subnet (/24 for IPv4, first four
     * groups for IPv6).
     */
    public function subnetHash(string $ip): string
    {
        $subnet = $this->subnetOf($ip);

        return hash_hmac('sha256', $subnet, (string) config('app.key'));
    }

    /**
     * Record a user's IP observation (login/registration). Never throws.
     */
    public function observe(Request $request, User $user): IpIntel
    {
        $ip = (string) $request->ip();

        if ($ip === '') {
            $ip = '0.0.0.0';
        }

        $ipHash = $this->ipHash($ip);
        $subnetHash = $this->subnetHash($ip);

        return DB::transaction(function () use ($ipHash, $subnetHash, $user) {
            $intel = IpIntel::where('ip_hash', $ipHash)->lockForUpdate()->first();

            if ($intel === null) {
                $intel = new IpIntel();
                $intel->ip_hash = $ipHash;
                $intel->subnet_hash = $subnetHash;
                $intel->observation_count = 1;
                $intel->suspicious_count = 0;
                $intel->first_seen_at = now();
                $intel->last_seen_at = now();
                $intel->save();
            } else {
                $intel->observation_count = $intel->observation_count + 1;
                $intel->last_seen_at = now();
                $intel->save();
            }

            $link = IpLink::where('ip_intel_id', $intel->id)
                ->where('user_id', $user->id)
                ->first();

            if ($link === null) {
                $link = new IpLink();
                $link->ip_intel_id = $intel->id;
                $link->user_id = $user->id;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            } else {
                $link->last_seen_at = now();
                $link->save();
            }

            // Shared-network signal — only above a high tolerance, and low
            // severity (an IP is never proof).
            $distinct = IpLink::where('ip_intel_id', $intel->id)->count();
            $maxShared = (int) config('antifraud.ip.max_accounts_shared', 20);

            if ($distinct > $maxShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_IP_SHARED, RiskEvent::SEVERITY_LOW, 'ip', [
                    'ip_intel_id' => $intel->id,
                    'account_count' => $distinct,
                ]);
            }

            return $intel;
        });
    }

    /**
     * Intel record for a raw IP (admin use only).
     */
    public function intelFor(string $ip): ?IpIntel
    {
        return IpIntel::where('ip_hash', $this->ipHash($ip))->first();
    }

    /**
     * Grouping subnet for an IP address.
     */
    protected function subnetOf(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $groups = explode(':', $ip);

            return implode(':', array_slice($groups, 0, 4));
        }

        $octets = explode('.', $ip);

        return implode('.', array_slice($octets, 0, 3));
    }
}
```


#### `app/Services/AccountLinkService.php`

```php
<?php

namespace App\Services;

use App\Models\AccountLink;
use App\Models\RiskEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Defensive account-similarity linking and ban-evasion detection (Phase 10).
 *
 * Links are stored in canonical order (lower user id first) with a unique
 * constraint, so a pair is never duplicated. Linking raises a risk signal on
 * both accounts; a strong link to a previously restricted account raises a
 * ban-evasion signal (never an automatic permanent ban).
 */
class AccountLinkService
{
    public function __construct(
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * Link two accounts with a confidence strength and reason categories.
     */
    public function link(User $a, User $b, string $strength, array $reasons, string $source): AccountLink
    {
        if (! in_array($strength, AccountLink::STRENGTHS, true)) {
            throw new DomainException('Account-link strength must be weak, moderate or strong.');
        }

        if ($a->id === $b->id) {
            throw new DomainException('An account cannot be linked to itself.');
        }

        // Canonical ordering so the unique pair constraint is effective.
        $first = $a->id < $b->id ? $a : $b;
        $second = $a->id < $b->id ? $b : $a;

        return DB::transaction(function () use ($first, $second, $strength, $reasons, $source) {
            $link = AccountLink::where('user_id', $first->id)
                ->where('linked_user_id', $second->id)
                ->first();

            if ($link === null) {
                $link = new AccountLink();
                $link->user_id = $first->id;
                $link->linked_user_id = $second->id;
                $link->strength = $strength;
                $link->reasons = array_values(array_unique($reasons));
                $link->source = $source;
                $link->save();
            } else {
                // Upgrade the strength if a stronger signal arrives.
                $order = array_flip(AccountLink::STRENGTHS);
                if (($order[$strength] ?? 0) > ($order[$link->strength] ?? 0)) {
                    $link->strength = $strength;
                    $link->save();
                }
            }

            $severity = match ($strength) {
                AccountLink::STRENGTH_STRONG => RiskEvent::SEVERITY_HIGH,
                AccountLink::STRENGTH_MODERATE => RiskEvent::SEVERITY_MEDIUM,
                default => RiskEvent::SEVERITY_LOW,
            };

            $this->risk->recordSignal($first, RiskEvent::TYPE_ACCOUNT_LINKED, $severity, 'device', [
                'linked_user_id' => $second->id,
                'strength' => $strength,
                'reasons' => $reasons,
            ]);
            $this->risk->recordSignal($second, RiskEvent::TYPE_ACCOUNT_LINKED, $severity, 'device', [
                'linked_user_id' => $first->id,
                'strength' => $strength,
                'reasons' => $reasons,
            ]);

            $this->detectBanEvasion($first);
            $this->detectBanEvasion($second);

            return $link;
        });
    }

    /**
     * Detect ban-evasion indicators for a user: a strong link to an account
     * that is currently restricted/suspended. Records a signal and (at most)
     * requires manual review — never an automatic permanent ban.
     */
    public function detectBanEvasion(User $user): void
    {
        $minStrength = (string) config('antifraud.ban_evasion.min_strength', 'strong');
        $order = array_flip(AccountLink::STRENGTHS);
        $required = $order[$minStrength] ?? $order[AccountLink::STRENGTH_STRONG];

        $links = AccountLink::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('linked_user_id', $user->id);
            })
            ->get();

        foreach ($links as $link) {
            if (($order[$link->strength] ?? 0) < $required) {
                continue;
            }

            $otherId = $link->user_id === $user->id ? $link->linked_user_id : $link->user_id;
            $other = User::find($otherId);

            if ($other === null) {
                continue;
            }

            $restricted = app(RestrictionService::class)->isBlocked($other, [
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ]);

            if ($restricted) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_BAN_EVASION, RiskEvent::SEVERITY_HIGH, 'device', [
                    'linked_user_id' => $other->id,
                    'strength' => $link->strength,
                ]);

                $this->risk->flagForReview($user, 'ban_evasion');
            }
        }
    }

    /**
     * All links involving a user.
     */
    public function linksFor(User $user)
    {
        return AccountLink::query()
            ->with(['user', 'linkedUser'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('linked_user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->get();
    }
}
```


#### `app/Services/RestrictionService.php`

```php
<?php

namespace App\Services;

use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Granular, auditable account restrictions (Phase 10).
 *
 * Restrictions are specific and revocable — there is no uncontrolled global
 * "ban" flag. Each restriction records its type, reason, source, actor,
 * start and optional expiry. Enforcement is read by the FraudRiskService
 * gates and RestrictionService::isBlocked(); no controller mutates risk or
 * restriction state directly.
 */
class RestrictionService
{
    public function __construct(
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * Apply a restriction to a user.
     */
    public function restrict(
        User $user,
        string $type,
        string $reason,
        string $source = 'manual',
        ?User $actor = null,
        ?Carbon $expiresAt = null,
    ): Restriction {
        if (! in_array($type, Restriction::TYPES, true)) {
            throw new DomainException('Unknown restriction type.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A restriction reason is required.');
        }

        return DB::transaction(function () use ($user, $type, $reason, $source, $actor, $expiresAt) {
            $restriction = new Restriction();
            $restriction->user_id = $user->id;
            $restriction->type = $type;
            $restriction->reason = $reason;
            $restriction->source = $source;
            $restriction->actor_id = $actor?->id;
            $restriction->starts_at = now();
            $restriction->expires_at = $expiresAt;
            $restriction->status = Restriction::STATUS_ACTIVE;
            $restriction->save();

            $severity = $type === Restriction::TYPE_ACCOUNT_SUSPENDED
                ? RiskEvent::SEVERITY_CRITICAL
                : RiskEvent::SEVERITY_HIGH;

            $this->risk->recordSignal($user, RiskEvent::TYPE_ACCOUNT_RESTRICTED, $severity, 'moderation', [
                'restriction_id' => $restriction->id,
                'type' => $type,
                'reason' => $reason,
            ]);

            // Suspensions also freeze the profile so the status is visible
            // even without reading the restrictions table.
            if ($type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $profile = $this->risk->profileFor($user);
                $profile->status = \App\Models\RiskProfile::STATUS_SUSPENDED;
                $profile->restricted_until = $expiresAt;
                $profile->save();
            }

            return $restriction;
        });
    }

    /**
     * Lift a restriction (authorized override). A lifted suspension restores
     * the profile status when no other suspension remains active.
     */
    public function lift(Restriction $restriction, User $actor): Restriction
    {
        if ($restriction->status === Restriction::STATUS_LIFTED) {
            throw new DomainException('This restriction has already been lifted.');
        }

        return DB::transaction(function () use ($restriction, $actor) {
            $restriction->status = Restriction::STATUS_LIFTED;
            $restriction->lifted_by = $actor->id;
            $restriction->lifted_at = now();
            $restriction->save();

            if ($restriction->type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $stillSuspended = Restriction::where('user_id', $restriction->user_id)
                    ->where('type', Restriction::TYPE_ACCOUNT_SUSPENDED)
                    ->where('status', Restriction::STATUS_ACTIVE)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists();

                if (! $stillSuspended) {
                    $profile = $this->risk->profileFor($restriction->user);
                    $profile->status = \App\Models\RiskProfile::STATUS_ACTIVE;
                    $profile->restricted_until = null;
                    $profile->save();
                }
            }

            return $restriction;
        });
    }

    /**
     * The user's active (unexpired, unlifted) restrictions.
     *
     * @return Collection<int, Restriction>
     */
    public function activeRestrictions(User $user): Collection
    {
        return Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    /**
     * Whether a user is blocked by any active restriction of the given
     * types, or by an account suspension.
     */
    public function isBlocked(User $user, array $types = []): bool
    {
        $types[] = Restriction::TYPE_ACCOUNT_SUSPENDED;
        $types = array_values(array_unique($types));

        $blocked = Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->whereIn('type', $types)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($blocked) {
            return true;
        }

        // Profile-level suspension is a backstop.
        $profile = $user->riskProfile()->first();

        return $profile !== null && $profile->isSuspended();
    }
}
```


#### `app/Services/IdentityVerificationService.php`

```php
<?php

namespace App\Services;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Gateways\ManualIdentityProvider;
use App\Models\IdentityVerification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Identity-verification state machine + provider abstraction (Phase 10).
 *
 *   unverified → pending → verified / rejected / review_required
 *   verified   → expired (lazily, when expires_at passes)
 *
 * The application NEVER fabricates verification: `verified` is only reached
 * through an explicit admin manual review. The default provider (manual)
 * cannot perform automated verification; future real KYC providers plug in
 * behind IdentityVerificationProviderInterface without changing the domain.
 */
class IdentityVerificationService
{
    /**
     * Registered providers, keyed by id.
     *
     * @var array<string, IdentityVerificationProviderInterface>
     */
    protected array $providers = [];

    public function __construct(ManualIdentityProvider $manual)
    {
        $this->providers[$manual->id()] = $manual;
    }

    /**
     * The user's verification record (lazily created as unverified).
     */
    public function recordFor(User $user): IdentityVerification
    {
        $record = $user->identityVerification()->first();

        if ($record !== null) {
            return $record;
        }

        $record = new IdentityVerification();
        $record->user_id = $user->id;
        $record->status = IdentityVerification::STATUS_UNVERIFIED;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * The effective status, lazily expiring stale verifications.
     */
    public function effectiveStatus(User $user): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED
            && $record->expires_at !== null
            && $record->expires_at->isPast()) {
            $record->status = IdentityVerification::STATUS_EXPIRED;
            $record->save();
        }

        return $record;
    }

    /**
     * Request verification (self-service). An expired/rejected/unverified
     * record moves to pending; a verified record stays verified.
     */
    public function request(User $user): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (in_array($record->status, [IdentityVerification::STATUS_PENDING, IdentityVerification::STATUS_VERIFIED], true)) {
            return $record;
        }

        $record->status = IdentityVerification::STATUS_PENDING;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * Admin manual verification. This is the ONLY path to `verified` today
     * and is explicitly a human review — never a fabricated provider result.
     */
    public function verifyManually(User $user, User $admin, ?string $notes = null, ?Carbon $expiresAt = null): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (! in_array($record->status, [
            IdentityVerification::STATUS_PENDING,
            IdentityVerification::STATUS_REJECTED,
            IdentityVerification::STATUS_REVIEW_REQUIRED,
            IdentityVerification::STATUS_EXPIRED,
            IdentityVerification::STATUS_UNVERIFIED,
        ], true)) {
            throw new DomainException('This verification cannot be approved from its current state.');
        }

        $record->status = IdentityVerification::STATUS_VERIFIED;
        $record->provider = 'manual';
        $record->reviewed_by = $admin->id;
        $record->verified_at = now();
        $record->expires_at = $expiresAt;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        return $record;
    }

    /**
     * Admin rejection of a verification.
     */
    public function reject(User $user, User $admin, ?string $notes = null): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED) {
            throw new DomainException('A verified identity cannot be rejected; revoke it instead.');
        }

        $record->status = IdentityVerification::STATUS_REJECTED;
        $record->reviewed_by = $admin->id;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        return $record;
    }

    /**
     * Attempt a provider-driven verification. Honest: the manual provider
     * returns pending and never reports a fabricated success.
     */
    public function attemptViaProvider(User $user, string $providerId): array
    {
        $provider = $this->providers[$providerId] ?? null;

        if ($provider === null) {
            throw new DomainException("Unknown identity provider: {$providerId}");
        }

        return $provider->request($user);
    }
}
```


#### `app/Services/AntiCheatService.php`

```php
<?php

namespace App\Services;

use App\Models\AntiCheatIncident;
use App\Models\GameMatch;
use App\Models\MatchAnomaly;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Defensive anti-cheat incidents + match anomaly detection (Phase 10).
 *
 * Anomalies are deterministic, server-side observations and are NEVER
 * auto-labelled "cheating"; the moderation decision is a separate, human
 * workflow. Incidents move through flagged → under_review → cleared /
 * confirmed / restricted / dismissed. Confirmed/restricted outcomes apply
 * auditable restrictions via RestrictionService; cleared/dismissed outcomes
 * are false-positive-safe.
 */
class AntiCheatService
{
    public const CATEGORY_AIMBOT = 'aimbot';
    public const CATEGORY_WALLHACK = 'wallhack';
    public const CATEGORY_SPEED_HACK = 'speed_hack';
    public const CATEGORY_TEAMING = 'teaming';
    public const CATEGORY_SCORE_MANIPULATION = 'score_manipulation';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_AIMBOT,
        self::CATEGORY_WALLHACK,
        self::CATEGORY_SPEED_HACK,
        self::CATEGORY_TEAMING,
        self::CATEGORY_SCORE_MANIPULATION,
        self::CATEGORY_OTHER,
    ];

    public function __construct(
        protected FraudRiskService $risk,
        protected RestrictionService $restrictions,
    ) {
    }

    /**
     * Open an anti-cheat incident.
     */
    public function openIncident(
        Tournament $tournament,
        ?GameMatch $match,
        ?Team $team,
        ?User $accusedUser,
        User $reporter,
        string $source,
        string $category,
        string $severity,
        ?string $description = null,
        ?string $evidenceReference = null,
    ): AntiCheatIncident {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new DomainException('Unknown anti-cheat category.');
        }

        if (! in_array($severity, AntiCheatIncident::SEVERITIES, true)) {
            throw new DomainException('Unknown anti-cheat severity.');
        }

        $incident = new AntiCheatIncident();
        $incident->tournament_id = $tournament->id;
        $incident->match_id = $match?->id;
        $incident->team_id = $team?->id;
        $incident->accused_user_id = $accusedUser?->id;
        $incident->reporter_user_id = $reporter->id;
        $incident->source = in_array($source, [
            AntiCheatIncident::SOURCE_SYSTEM,
            AntiCheatIncident::SOURCE_PARTICIPANT,
            AntiCheatIncident::SOURCE_STAFF,
        ], true) ? $source : AntiCheatIncident::SOURCE_SYSTEM;
        $incident->category = $category;
        $incident->severity = $severity;
        $incident->description = $description !== null && trim($description) !== '' ? trim($description) : null;
        $incident->evidence_reference = $evidenceReference;
        $incident->status = AntiCheatIncident::STATUS_FLAGGED;
        $incident->save();

        return $incident;
    }

    /**
     * Move a flagged incident under review (staff).
     */
    public function review(AntiCheatIncident $incident, User $reviewer): AntiCheatIncident
    {
        if (! $incident->canTransitionTo(AntiCheatIncident::STATUS_UNDER_REVIEW)) {
            throw new DomainException('Only flagged incidents can move under review.');
        }

        $incident->status = AntiCheatIncident::STATUS_UNDER_REVIEW;
        $incident->reviewer_id = $reviewer->id;
        $incident->save();

        return $incident;
    }

    /**
     * Resolve an incident (staff). Confirmed/restricted outcomes apply an
     * auditable restriction; cleared/dismissed are false-positive-safe.
     */
    public function resolve(AntiCheatIncident $incident, User $reviewer, string $resolution, string $resolutionText): AntiCheatIncident
    {
        if (! in_array($resolution, AntiCheatIncident::RESOLUTIONS, true)) {
            throw new DomainException('Unknown incident resolution.');
        }

        if (! $incident->canTransitionTo($resolution)) {
            throw new DomainException('This incident cannot be resolved from its current state.');
        }

        $resolutionText = trim($resolutionText);

        if ($resolutionText === '') {
            throw new DomainException('A resolution reason is required.');
        }

        return DB::transaction(function () use ($incident, $reviewer, $resolution, $resolutionText) {
            $incident->status = $resolution;
            $incident->reviewer_id = $reviewer->id;
            $incident->resolution = $resolutionText;
            $incident->resolved_at = now();
            $incident->save();

            $accused = $incident->accusedUser;

            if ($accused !== null) {
                if ($resolution === AntiCheatIncident::STATUS_CONFIRMED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_HIGH, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_SCORE_SUBMISSION_BLOCKED,
                        'Confirmed anti-cheat incident #' . $incident->id . ' (' . $incident->category . ')',
                        'anti_cheat',
                        $reviewer,
                        now()->addDays(30),
                    );
                } elseif ($resolution === AntiCheatIncident::STATUS_RESTRICTED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_CRITICAL, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
                        'Restricted for anti-cheat incident #' . $incident->id . ' (' . $incident->category . ')',
                        'anti_cheat',
                        $reviewer,
                    );
                } else {
                    // cleared / dismissed — false-positive-safe close.
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'resolution' => $resolution,
                    ], $incident->tournament);
                }
            }

            return $incident;
        });
    }

    /**
     * Record a deterministic match anomaly. Never throws for business
     * reasons — observation only.
     */
    public function recordAnomaly(
        GameMatch $match,
        Tournament $tournament,
        string $kind,
        string $severity,
        array $metadata = [],
    ): MatchAnomaly {
        if (! in_array($kind, [
            MatchAnomaly::KIND_ABNORMAL_KILL_RATIO,
            MatchAnomaly::KIND_REPEATED_PATTERN,
            MatchAnomaly::KIND_UNEXPECTED_PARTICIPATION,
        ], true)) {
            throw new DomainException('Unknown anomaly kind.');
        }

        if (! in_array($severity, MatchAnomaly::SEVERITIES, true)) {
            throw new DomainException('Unknown anomaly severity.');
        }

        $anomaly = new MatchAnomaly();
        $anomaly->match_id = $match->id;
        $anomaly->tournament_id = $tournament->id;
        $anomaly->kind = $kind;
        $anomaly->severity = $severity;
        $anomaly->status = $severity;
        $anomaly->metadata = $metadata;
        $anomaly->save();

        // A low-severity risk signal for the participating captains (never
        // an accusation — an observation feeding the review queue).
        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;
            if ($captain !== null) {
                $this->risk->recordSignal($captain, RiskEvent::TYPE_MATCH_ANOMALY, RiskEvent::SEVERITY_LOW, 'anti_cheat', [
                    'match_id' => $match->id,
                    'anomaly_id' => $anomaly->id,
                    'kind' => $kind,
                ], $tournament);
            }
        }

        return $anomaly;
    }

    /**
     * Deterministic score-submission analysis. Returns the anomalies created.
     * Never throws for business reasons.
     *
     * @return Collection<int, MatchAnomaly>
     */
    public function analyzeScoreSubmission(GameMatch $match, Team $team, int $kills, int $placement): Collection
    {
        $created = new Collection();
        $maxKills = (int) config('antifraud.anomaly.max_kills_per_match', 60);

        if ($kills > $maxKills) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, MatchAnomaly::SEVERITY_SUSPICIOUS, [
                'team_id' => $team->id,
                'kills' => $kills,
                'threshold' => $maxKills,
            ]));
        }

        $patternThreshold = (int) config('antifraud.anomaly.repeat_pattern_threshold', 3);

        $identical = Score::where('team_id', $team->id)
            ->where('kills', $kills)
            ->where('placement', $placement)
            ->where('id', '!=', Score::where('match_id', $match->id)->where('team_id', $team->id)->value('id'))
            ->count();

        if ($identical >= $patternThreshold) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_REPEATED_PATTERN, MatchAnomaly::SEVERITY_ANOMALY, [
                'team_id' => $team->id,
                'kills' => $kills,
                'placement' => $placement,
                'identical_count' => $identical,
            ]));
        }

        return $created;
    }
}
```


#### `app/Exceptions/PayoutReviewRequiredException.php`

```php
<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a payout is held for fraud review (Phase 10).
 *
 * Distinct from a generic DomainException so the distribution processing
 * loop can HOLD (leave the payout pending review) instead of failing it.
 */
class PayoutReviewRequiredException extends DomainException
{
}
```


#### `app/Policies/RiskProfilePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\User;

/**
 * Authorization for fraud/risk data (Phase 10).
 *
 * Admins manage everything. Moderators may view risk summaries and events
 * (but never modify restrictions, scores or verification). Users may view
 * only their own risk summary, never another user's risk/device/IP data.
 */
class RiskProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function view(User $user, RiskProfile $profile): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $profile->user_id === $user->id;
    }

    public function viewUser(User $user, User $subject): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $subject->id === $user->id;
    }

    public function viewEvents(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function manageRestrictions(User $user): bool
    {
        return $user->isAdmin();
    }

    public function liftRestriction(User $user, Restriction $restriction): bool
    {
        return $user->isAdmin();
    }
}
```


#### `app/Policies/AntiCheatIncidentPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\AntiCheatIncident;
use App\Models\User;

/**
 * Anti-cheat incident authorization (Phase 10).
 *
 * Admins and moderators may review and resolve incidents. Organizers may
 * view incidents in their own tournaments but never resolve them. Players
 * may only view incidents they reported — never another player's case.
 */
class AntiCheatIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator() || $user->isOrganizer();
    }

    public function view(User $user, AntiCheatIncident $incident): bool
    {
        if ($user->isAdmin() || $user->isModerator()) {
            return true;
        }

        if ($user->isOrganizer() && $incident->tournament->organizer_id === $user->id) {
            return true;
        }

        return $incident->reporter_user_id === $user->id;
    }

    public function review(User $user, AntiCheatIncident $incident): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function resolve(User $user, AntiCheatIncident $incident): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }
}
```


#### `app/Policies/IdentityVerificationPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Identity-verification authorization (Phase 10).
 *
 * A user may request verification only for themselves and can never mark
 * themselves verified. Only admins approve/reject verification.
 */
class IdentityVerificationPolicy
{
    public function request(User $user): bool
    {
        return true; // self-service, always the authenticated user
    }

    public function verify(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user): bool
    {
        return $user->isAdmin();
    }
}
```


#### `app/Http/Controllers/SecurityController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\MatchAnomaly;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\IdentityVerificationService;
use App\Services\IpIntelligenceService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin/moderation security UI + actions (Phase 10).
 *
 * Every method authorizes per action via the Risk/AntiCheat/Identity
 * policies. Players can never inspect another user's risk, device or IP
 * data, and can never modify restrictions or verification state.
 */
class SecurityController extends Controller
{
    public function __construct(
        protected RestrictionService $restrictions,
        protected IdentityVerificationService $identity,
        protected AntiCheatService $antiCheat,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    // ------------------------------------------------------------------
    // Risk dashboard (admin)
    // ------------------------------------------------------------------

    public function dashboard()
    {
        $this->authorize('viewAny', RiskProfile::class);

        $stats = [
            'critical' => RiskProfile::where('risk_level', RiskProfile::LEVEL_CRITICAL)->count(),
            'high' => RiskProfile::where('risk_level', RiskProfile::LEVEL_HIGH)->count(),
            'medium' => RiskProfile::where('risk_level', RiskProfile::LEVEL_MEDIUM)->count(),
            'review_required' => RiskProfile::where('manual_review_required', true)->count(),
            'active_restrictions' => Restriction::where('status', Restriction::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'open_incidents' => AntiCheatIncident::whereIn('status', [
                AntiCheatIncident::STATUS_FLAGGED,
                AntiCheatIncident::STATUS_UNDER_REVIEW,
            ])->count(),
            'anomalies' => MatchAnomaly::count(),
            'events' => RiskEvent::count(),
        ];

        $recentEvents = RiskEvent::with(['user', 'tournament'])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $reviewQueue = RiskProfile::with('user')
            ->where('manual_review_required', true)
            ->orderByDesc('risk_score')
            ->limit(15)
            ->get();

        return view('admin.security.dashboard', compact('stats', 'recentEvents', 'reviewQueue'));
    }

    /**
     * Suspicious users list (admin).
     */
    public function users(Request $request)
    {
        $this->authorize('viewAny', RiskProfile::class);

        $profiles = RiskProfile::query()->with('user')->orderByDesc('risk_score');

        $level = $request->query('level');
        if ($level !== null && in_array($level, RiskProfile::LEVELS, true)) {
            $profiles->where('risk_level', $level);
        }

        if ($request->query('review') === '1') {
            $profiles->where('manual_review_required', true);
        }

        $profiles = $profiles->paginate(25)->withQueryString();

        return view('admin.security.users', compact('profiles', 'level'));
    }

    /**
     * Investigation detail for one user (admin).
     */
    public function user(User $user)
    {
        $this->authorize('viewUser', [RiskProfile::class, $user]);

        $profile = $user->riskProfile()->first();
        $events = RiskEvent::where('user_id', $user->id)->orderByDesc('id')->limit(100)->get();
        $devices = Device::query()
            ->whereHas('links', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('links')
            ->get();
        $ipIntel = $user->ipLinks()->with('ipIntel')->get();
        $links = $user->linkedAccounts()->orderByDesc('id')->get();
        $restrictions = $user->restrictions()->with('actor', 'liftedBy')->orderByDesc('id')->get();
        $identity = $this->identity->effectiveStatus($user);
        $incidents = AntiCheatIncident::where('accused_user_id', $user->id)
            ->orWhere('reporter_user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return view('admin.security.user', [
            'subject' => $user,
            'profile' => $profile,
            'events' => $events,
            'devices' => $devices,
            'ipIntel' => $ipIntel,
            'links' => $links,
            'restrictions' => $restrictions,
            'identity' => $identity,
            'incidents' => $incidents,
        ]);
    }

    /**
     * Risk events list (admin).
     */
    public function events(Request $request)
    {
        $this->authorize('viewEvents', RiskProfile::class);

        $events = RiskEvent::query()->with(['user', 'tournament'])->orderByDesc('id');

        $severity = $request->query('severity');
        if ($severity !== null && in_array($severity, RiskEvent::SEVERITIES, true)) {
            $events->where('severity', $severity);
        }

        $events = $events->paginate(30)->withQueryString();

        return view('admin.security.events', compact('events', 'severity'));
    }

    // ------------------------------------------------------------------
    // Anti-cheat incidents (staff)
    // ------------------------------------------------------------------

    public function incidents(Request $request)
    {
        $this->authorize('viewAny', AntiCheatIncident::class);

        $user = $request->user();
        $incidents = AntiCheatIncident::query()
            ->with(['tournament', 'match', 'team', 'accusedUser', 'reviewer'])
            ->orderByDesc('created_at');

        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $incidents->whereHas('tournament', fn ($q) => $q->where('organizer_id', $user->id));
        }

        $status = $request->query('status');
        if ($status !== null && in_array($status, AntiCheatIncident::STATUSES, true)) {
            $incidents->where('status', $status);
        }

        $incidents = $incidents->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.security.incidents', compact('incidents', 'tournaments', 'status'));
    }

    /**
     * Open a new incident (staff).
     */
    public function openIncident(Request $request)
    {
        $this->authorize('create', AntiCheatIncident::class);

        $data = $request->validate([
            'tournament_id' => 'required|integer|exists:tournaments,id',
            'match_id' => 'nullable|integer|exists:matches,id',
            'team_id' => 'nullable|integer|exists:teams,id',
            'accused_user_id' => 'nullable|integer|exists:users,id',
            'category' => 'required|in:' . implode(',', AntiCheatService::CATEGORIES),
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'nullable|string|max:5000',
            'evidence_reference' => 'nullable|string|max:120',
        ]);

        $tournament = Tournament::findOrFail($data['tournament_id']);

        try {
            $this->antiCheat->openIncident(
                $tournament,
                ! empty($data['match_id']) ? GameMatch::find($data['match_id']) : null,
                ! empty($data['team_id']) ? Team::find($data['team_id']) : null,
                ! empty($data['accused_user_id']) ? User::find($data['accused_user_id']) : null,
                $request->user(),
                AntiCheatIncident::SOURCE_STAFF,
                $data['category'],
                $data['severity'],
                $data['description'] ?? null,
                $data['evidence_reference'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('security.incidents.index')->with('success', 'Anti-cheat incident opened.');
    }

    public function reviewIncident(AntiCheatIncident $incident)
    {
        $this->authorize('review', $incident);

        try {
            $this->antiCheat->review($incident, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Incident moved under review.');
    }

    public function resolveIncident(Request $request, AntiCheatIncident $incident)
    {
        $this->authorize('resolve', $incident);

        $data = $request->validate([
            'resolution' => 'required|in:' . implode(',', AntiCheatIncident::RESOLUTIONS),
            'resolution_text' => 'required|string|max:5000',
        ]);

        try {
            $this->antiCheat->resolve($incident, $request->user(), $data['resolution'], $data['resolution_text']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Incident resolved.');
    }

    // ------------------------------------------------------------------
    // Restrictions + identity (admin)
    // ------------------------------------------------------------------

    public function restrict(Request $request, User $user)
    {
        $this->authorize('manageRestrictions', RiskProfile::class);

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', Restriction::TYPES),
            'reason' => 'required|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $this->restrictions->restrict(
                $user,
                $data['type'],
                $data['reason'],
                'manual',
                $request->user(),
                ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Restriction applied.');
    }

    public function liftRestriction(Restriction $restriction)
    {
        $this->authorize('liftRestriction', [RiskProfile::class, $restriction]);

        try {
            $this->restrictions->lift($restriction, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Restriction lifted.');
    }

    public function verifyIdentity(Request $request, User $user)
    {
        $this->authorize('verify', IdentityVerification::class);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $this->identity->verifyManually(
                $user,
                $request->user(),
                $data['notes'] ?? null,
                ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Identity verified (manual review).');
    }

    public function rejectIdentity(Request $request, User $user)
    {
        $this->authorize('reject', IdentityVerification::class);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->identity->reject($user, $request->user(), $data['notes'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Identity verification rejected.');
    }

    /**
     * Self-service verification request (authenticated user).
     */
    public function requestVerification()
    {
        $this->authorize('request', IdentityVerification::class);

        $this->identity->request(auth()->user());

        return back()->with('success', 'Verification requested. A staff member will review it.');
    }
}
```


#### `resources/views/admin/security/dashboard.blade.php`

```php
@extends('layouts.app')
@section('title', 'Security — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Security Dashboard</h1>

    <div class="card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <a href="{{ route('admin.security.users') }}" class="btn btn-sm">Suspicious Users</a>
        <a href="{{ route('admin.security.events') }}" class="btn btn-sm">Risk Events</a>
        <a href="{{ route('security.incidents.index') }}" class="btn btn-sm btn-cyan">Anti-cheat Incidents</a>
    </div>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
        <div class="stat"><div class="muted">Critical risk</div><div class="num" style="color:var(--red)">{{ $stats['critical'] }}</div></div>
        <div class="stat"><div class="muted">High risk</div><div class="num" style="color:var(--amber)">{{ $stats['high'] }}</div></div>
        <div class="stat"><div class="muted">Medium risk</div><div class="num">{{ $stats['medium'] }}</div></div>
        <div class="stat"><div class="muted">Manual review required</div><div class="num" style="color:var(--purple)">{{ $stats['review_required'] }}</div></div>
        <div class="stat"><div class="muted">Active restrictions</div><div class="num" style="color:var(--red)">{{ $stats['active_restrictions'] }}</div></div>
        <div class="stat"><div class="muted">Open incidents</div><div class="num" style="color:var(--amber)">{{ $stats['open_incidents'] }}</div></div>
        <div class="stat"><div class="muted">Match anomalies</div><div class="num">{{ $stats['anomalies'] }}</div></div>
        <div class="stat"><div class="muted">Risk events</div><div class="num">{{ $stats['events'] }}</div></div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🔍 Review Queue</h3>
            @if($reviewQueue->isEmpty())
                <p class="muted">No accounts flagged for review.</p>
            @else
                <table>
                    <tr><th>User</th><th>Risk</th><th>Score</th><th></th></tr>
                    @foreach($reviewQueue as $profile)
                        <tr>
                            <td>{{ $profile->user?->name ?? '—' }}</td>
                            <td><span class="pill {{ $profile->levelPill() }}">{{ strtoupper($profile->risk_level) }}</span></td>
                            <td>{{ $profile->risk_score }}/100</td>
                            <td>
                                @if($profile->user)
                                    <a class="btn btn-sm" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>📜 Recent Risk Events</h3>
            @if($recentEvents->isEmpty())
                <p class="muted">No risk events yet.</p>
            @else
                <table>
                    <tr><th>User</th><th>Type</th><th>Severity</th><th>When</th></tr>
                    @foreach($recentEvents as $event)
                        <tr>
                            <td>{{ $event->user?->name ?? '—' }}</td>
                            <td class="muted" style="font-size:12px">{{ $event->type }}</td>
                            <td><span class="pill {{ $event->severity === 'critical' || $event->severity === 'high' ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft') }}">{{ strtoupper($event->severity) }}</span></td>
                            <td class="muted" style="font-size:12px">{{ $event->created_at?->format('d M, h:i A') }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
@endsection
```


#### `resources/views/admin/security/users.blade.php`

```php
@extends('layouts.app')
@section('title', 'Suspicious Users — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">🔍 Suspicious Users</h1>

    <div class="card">
        <form method="GET" action="{{ route('admin.security.users') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
            <div style="min-width:160px">
                <label>Risk level</label>
                <select name="level">
                    <option value="">All levels</option>
                    @foreach(\App\Models\RiskProfile::LEVELS as $l)
                        <option value="{{ $l }}" @selected($level === $l)>{{ ucfirst($l) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:160px">
                <label>&nbsp;</label>
                <label class="muted" style="font-size:13px">
                    <input type="checkbox" name="review" value="1" @checked(request('review') === '1') style="width:auto"> Review required only
                </label>
            </div>
            <button class="btn btn-sm btn-cyan">Filter</button>
        </form>
    </div>

    <div class="card">
        @if($profiles->isEmpty())
            <p class="muted">No accounts match your filters.</p>
        @else
            <table>
                <tr><th>User</th><th>Email</th><th>Risk level</th><th>Score</th><th>Review</th><th>Status</th><th></th></tr>
                @foreach($profiles as $profile)
                    <tr>
                        <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                        <td class="muted" style="font-size:12px">{{ $profile->user?->email ?? '—' }}</td>
                        <td><span class="pill {{ $profile->levelPill() }}">{{ strtoupper($profile->risk_level) }}</span></td>
                        <td>{{ $profile->risk_score }}/100</td>
                        <td>{{ $profile->manual_review_required ? '⚠️ yes' : '—' }}</td>
                        <td class="muted" style="font-size:12px">{{ $profile->status }}</td>
                        <td>
                            @if($profile->user)
                                <a class="btn btn-sm btn-cyan" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $profiles->links() }}</div>
        @endif
    </div>
@endsection
```


#### `resources/views/admin/security/user.blade.php`

```php
@extends('layouts.app')
@section('title', 'Investigation — ' . $subject->name . ' — FF Arena Admin')
@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin:30px 0 4px">
        <h1 style="margin:0">🔍 {{ $subject->name }}</h1>
        <a class="btn btn-sm" href="{{ route('admin.security.users') }}">← All users</a>
    </div>
    <p class="muted" style="margin-bottom:16px">{{ $subject->email }} · role {{ $subject->role }} · user #{{ $subject->id }}</p>

    <div class="grid cols-2">
        <div class="card">
            <h3>Risk Profile</h3>
            @if(!$profile)
                <p class="muted">No risk profile yet (low risk).</p>
            @else
                <table>
                    <tr><th>Score</th><td>{{ $profile->risk_score }}/100</td></tr>
                    <tr><th>Level</th><td><span class="pill {{ $profile->levelPill() }}">{{ strtoupper($profile->risk_level) }}</span></td></tr>
                    <tr><th>Status</th><td>{{ $profile->status }}</td></tr>
                    <tr><th>Manual review</th><td>{{ $profile->manual_review_required ? '⚠️ required' : 'no' }}</td></tr>
                    <tr><th>Restricted until</th><td>{{ $profile->restricted_until?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                    <tr><th>Flags</th><td class="muted" style="font-size:12px">{{ implode(', ', $profile->account_flags ?? []) ?: '—' }}</td></tr>
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Identity Verification</h3>
            <table>
                <tr><th>Status</th><td><span class="pill {{ $identity->statusPill() }}">{{ $identity->statusLabel() }}</span></td></tr>
                <tr><th>Provider</th><td class="muted">{{ $identity->provider }}</td></tr>
                <tr><th>Verified at</th><td class="muted">{{ $identity->verified_at?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                <tr><th>Expires</th><td class="muted">{{ $identity->expires_at?->format('d M Y') ?? '—' }}</td></tr>
                <tr><th>Reviewed by</th><td class="muted">{{ $identity->reviewedBy?->name ?? '—' }}</td></tr>
                <tr><th>Notes</th><td class="muted" style="font-size:12px">{{ $identity->notes ?? '—' }}</td></tr>
            </table>
            @if($identity->status === 'pending' || $identity->status === 'review_required' || $identity->status === 'rejected' || $identity->status === 'expired')
                <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap">
                    <form method="POST" action="{{ route('admin.security.verify', $subject) }}" style="display:inline-flex; gap:6px; align-items:center">
                        @csrf
                        <input type="text" name="notes" placeholder="Review note (optional)" style="max-width:160px">
                        <button class="btn btn-green btn-sm">Verify</button>
                    </form>
                    <form method="POST" action="{{ route('admin.security.reject', $subject) }}" style="display:inline-flex; gap:6px; align-items:center">
                        @csrf
                        <input type="text" name="notes" placeholder="Rejection note" style="max-width:160px">
                        <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Reject</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🔒 Devices</h3>
            @if($devices->isEmpty())
                <p class="muted">No device associations.</p>
            @else
                <table>
                    <tr><th>Device (hash)</th><th>Accounts</th><th>Status</th></tr>
                    @foreach($devices as $device)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ substr($device->device_hash, 0, 16) }}…</td>
                            <td>{{ $device->links_count }}</td>
                            <td><span class="pill {{ $device->isBlocked() ? 'failed' : 'confirmed' }}">{{ $device->status }}</span></td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>🌐 IP Observations</h3>
            @if($ipIntel->isEmpty())
                <p class="muted">No IP observations.</p>
            @else
                <table>
                    <tr><th>IP (hash)</th><th>Observations</th><th>Last seen</th></tr>
                    @foreach($ipIntel as $link)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ substr($link->ipIntel->ip_hash, 0, 16) }}…</td>
                            <td>{{ $link->ipIntel->observation_count }}</td>
                            <td class="muted" style="font-size:12px">{{ $link->ipIntel->last_seen_at?->format('d M, h:i A') }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🔗 Linked Accounts</h3>
            @if($links->isEmpty())
                <p class="muted">No linked accounts.</p>
            @else
                <table>
                    <tr><th>Linked user</th><th>Strength</th><th>Reasons</th></tr>
                    @foreach($links as $link)
                        @php $other = $link->user_id === $subject->id ? $link->linkedUser : $link->user; @endphp
                        <tr>
                            <td>{{ $other?->name ?? '—' }}</td>
                            <td><span class="pill {{ $link->strength === 'strong' ? 'failed' : ($link->strength === 'moderate' ? 'pending' : 'draft') }}">{{ $link->strength }}</span></td>
                            <td class="muted" style="font-size:12px">{{ implode(', ', $link->reasons ?? []) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>🚫 Restrictions</h3>
            @if($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <table>
                    <tr><th>Type</th><th>Status</th><th>Reason</th><th></th></tr>
                    @foreach($restrictions as $restriction)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ $restriction->typeLabel() }}</td>
                            <td><span class="pill {{ $restriction->isActive() ? 'failed' : 'confirmed' }}">{{ $restriction->status }}</span></td>
                            <td class="muted" style="font-size:12px">{{ $restriction->reason }}</td>
                            <td>
                                @if($restriction->isActive())
                                    <form method="POST" action="{{ route('admin.security.lift', $restriction) }}">@csrf
                                        <button class="btn btn-sm btn-green">Lift</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            <form method="POST" action="{{ route('admin.security.restrict', $subject) }}" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-top:12px">
                @csrf
                <div style="min-width:200px">
                    <label>Restriction type</label>
                    <select name="type">
                        @foreach(\App\Models\Restriction::TYPES as $type)
                            <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="min-width:200px">
                    <label>Reason</label>
                    <input type="text" name="reason" required>
                </div>
                <div style="min-width:120px">
                    <label>Expires (days, optional)</label>
                    <input type="number" name="expires_in_days" min="1" max="3650">
                </div>
                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Apply Restriction</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>🧾 Risk Events</h3>
        @if($events->isEmpty())
            <p class="muted">No risk events.</p>
        @else
            <table>
                <tr><th>Type</th><th>Severity</th><th>Score</th><th>Source</th><th>When</th></tr>
                @foreach($events as $event)
                    <tr>
                        <td class="muted" style="font-size:12px">{{ $event->type }}</td>
                        <td><span class="pill {{ in_array($event->severity, ['high','critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft') }}">{{ strtoupper($event->severity) }}</span></td>
                        <td>+{{ $event->score_contribution }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->source }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->created_at?->format('d M, h:i A') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card">
        <h3>🎮 Anti-cheat Incidents</h3>
        @if($incidents->isEmpty())
            <p class="muted">No anti-cheat incidents.</p>
        @else
            <table>
                <tr><th>#</th><th>Tournament</th><th>Category</th><th>Severity</th><th>Status</th><th>Role</th></tr>
                @foreach($incidents as $incident)
                    <tr>
                        <td><strong>#{{ $incident->id }}</strong></td>
                        <td>{{ $incident->tournament?->name ?? '—' }}</td>
                        <td class="muted">{{ $incident->category }}</td>
                        <td class="muted">{{ $incident->severity }}</td>
                        <td><span class="pill {{ $incident->statusPill() }}">{{ $incident->statusLabel() }}</span></td>
                        <td class="muted" style="font-size:12px">{{ $incident->accused_user_id === $subject->id ? 'accused' : 'reporter' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
```


#### `resources/views/admin/security/events.blade.php`

```php
@extends('layouts.app')
@section('title', 'Risk Events — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">📜 Risk Events</h1>

    <div class="card">
        <form method="GET" action="{{ route('admin.security.events') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
            <div style="min-width:160px">
                <label>Severity</label>
                <select name="severity">
                    <option value="">All severities</option>
                    @foreach(\App\Models\RiskEvent::SEVERITIES as $s)
                        <option value="{{ $s }}" @selected($severity === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-cyan">Filter</button>
        </form>
    </div>

    <div class="card">
        @if($events->isEmpty())
            <p class="muted">No risk events match your filters.</p>
        @else
            <table>
                <tr><th>#</th><th>User</th><th>Type</th><th>Severity</th><th>Score</th><th>Source</th><th>Tournament</th><th>When</th></tr>
                @foreach($events as $event)
                    <tr>
                        <td><strong>#{{ $event->id }}</strong></td>
                        <td>{{ $event->user?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->type }}</td>
                        <td><span class="pill {{ in_array($event->severity, ['high','critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft') }}">{{ strtoupper($event->severity) }}</span></td>
                        <td>+{{ $event->score_contribution }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->source }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->tournament?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:12px">{{ $event->created_at?->format('d M, h:i A') }}</td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
```


#### `resources/views/admin/security/incidents.blade.php`

```php
@extends('layouts.app')
@section('title', 'Anti-cheat Incidents — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🎮 Anti-cheat Incidents</h1>

    @can('create', \App\Models\AntiCheatIncident::class)
        <div class="card">
            <h3>Open an Incident</h3>
            <form method="POST" action="{{ route('security.incidents.open') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
                @csrf
                <div style="min-width:200px">
                    <label>Tournament</label>
                    <select name="tournament_id" required>
                        <option value="">Select…</option>
                        @foreach($tournaments as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="min-width:140px">
                    <label>Category</label>
                    <select name="category">
                        @foreach(\App\Services\AntiCheatService::CATEGORIES as $c)
                            <option value="{{ $c }}">{{ ucwords(str_replace('_', ' ', $c)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="min-width:130px">
                    <label>Severity</label>
                    <select name="severity">
                        @foreach(['low','medium','high','critical'] as $s)
                            <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="min-width:180px">
                    <label>Accused user ID (optional)</label>
                    <input type="number" name="accused_user_id" placeholder="user id">
                </div>
                <div style="min-width:240px">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="What was observed?">
                </div>
                <button class="btn btn-sm btn-cyan">Open Incident</button>
            </form>
        </div>
    @endcan

    <div class="card">
        @if($incidents->isEmpty())
            <p class="muted">No incidents.</p>
        @else
            <table>
                <tr>
                    <th>#</th><th>Tournament</th><th>Team</th><th>Accused</th><th>Category</th>
                    <th>Severity</th><th>Status</th><th>Reviewer</th><th>Action</th>
                </tr>
                @foreach($incidents as $incident)
                    <tr>
                        <td><strong>#{{ $incident->id }}</strong></td>
                        <td>{{ $incident->tournament?->name ?? '—' }}</td>
                        <td>{{ $incident->team?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $incident->accusedUser?->name ?? '—' }}</td>
                        <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                        <td class="muted">{{ $incident->severity }}</td>
                        <td><span class="pill {{ $incident->statusPill() }}">{{ $incident->statusLabel() }}</span></td>
                        <td class="muted" style="font-size:13px">{{ $incident->reviewer?->name ?? '—' }}</td>
                        <td>
                            @if($incident->status === 'flagged')
                                <form method="POST" action="{{ route('security.incidents.review', $incident) }}" style="display:inline">@csrf
                                    <button class="btn btn-sm btn-cyan">Review</button>
                                </form>
                            @elseif($incident->status === 'under_review')
                                <form method="POST" action="{{ route('security.incidents.resolve', $incident) }}" style="display:inline-flex; gap:6px; align-items:center">
                                    @csrf
                                    <select name="resolution">
                                        @foreach(\App\Models\AntiCheatIncident::RESOLUTIONS as $r)
                                            <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="resolution_text" placeholder="Resolution reason" style="max-width:140px">
                                    <button class="btn btn-green btn-sm">Resolve</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $incidents->links() }}</div>
        @endif
    </div>
@endsection
```


#### `resources/views/moderation/security.blade.php`

```php
@extends('layouts.app')
@section('title', 'Security Review — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Security Review</h1>

    <div class="card">
        <h3>⚠️ Flagged Accounts</h3>
        @if($flaggedUsers->isEmpty())
            <p class="muted">No accounts flagged for review.</p>
        @else
            <table>
                <tr><th>User</th><th>Risk level</th><th>Score</th><th>Review</th><th>Status</th></tr>
                @foreach($flaggedUsers as $profile)
                    <tr>
                        <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                        <td><span class="pill {{ $profile->levelPill() }}">{{ strtoupper($profile->risk_level) }}</span></td>
                        <td>{{ $profile->risk_score }}/100</td>
                        <td>{{ $profile->manual_review_required ? '⚠️ required' : '—' }}</td>
                        <td class="muted" style="font-size:12px">{{ $profile->status }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card">
        <h3>🎮 Open Anti-cheat Incidents</h3>
        @if($openIncidents->isEmpty())
            <p class="muted">No open incidents.</p>
        @else
            <table>
                <tr><th>#</th><th>Tournament</th><th>Team</th><th>Category</th><th>Severity</th><th>Status</th><th></th></tr>
                @foreach($openIncidents as $incident)
                    <tr>
                        <td><strong>#{{ $incident->id }}</strong></td>
                        <td>{{ $incident->tournament?->name ?? '—' }}</td>
                        <td>{{ $incident->team?->name ?? '—' }}</td>
                        <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                        <td class="muted">{{ $incident->severity }}</td>
                        <td><span class="pill {{ $incident->statusPill() }}">{{ $incident->statusLabel() }}</span></td>
                        <td>
                            <a class="btn btn-sm" href="{{ route('security.incidents.index') }}">Open queue</a>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
```


#### `tests/Feature/AntiFraudRiskServiceTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\FraudRiskService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 — deterministic fraud/risk engine + granular restrictions.
 */
class AntiFraudRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function risk(): FraudRiskService
    {
        return app(FraudRiskService::class);
    }

    protected function restrictions(): RestrictionService
    {
        return app(RestrictionService::class);
    }

    // ------------------------------------------------------------------
    // Risk scoring + levels
    // ------------------------------------------------------------------

    public function test_profile_is_lazily_created_with_low_risk(): void
    {
        $user = $this->makeUser();

        $this->assertNull($user->riskProfile()->first());

        $profile = $this->risk()->profileFor($user);

        $this->assertSame(0, $profile->risk_score);
        $this->assertSame(RiskProfile::LEVEL_LOW, $profile->risk_level);
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $profile->status);
        $this->assertSame($profile->id, $user->riskProfile()->first()->id);
    }

    public function test_severity_scores_are_deterministic(): void
    {
        $this->assertSame(0, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_INFO));
        $this->assertSame(5, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_LOW));
        $this->assertSame(15, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_MEDIUM));
        $this->assertSame(30, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_HIGH));
        $this->assertSame(50, $this->risk()->scoreForSeverity(RiskEvent::SEVERITY_CRITICAL));
    }

    public function test_level_thresholds_are_deterministic(): void
    {
        $this->assertSame(RiskProfile::LEVEL_LOW, $this->risk()->levelFromScore(0));
        $this->assertSame(RiskProfile::LEVEL_LOW, $this->risk()->levelFromScore(29));
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $this->risk()->levelFromScore(30));
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $this->risk()->levelFromScore(59));
        $this->assertSame(RiskProfile::LEVEL_HIGH, $this->risk()->levelFromScore(60));
        $this->assertSame(RiskProfile::LEVEL_HIGH, $this->risk()->levelFromScore(89));
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $this->risk()->levelFromScore(90));
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $this->risk()->levelFromScore(100));
    }

    public function test_score_is_capped_at_one_hundred(): void
    {
        $user = $this->makeUser();

        foreach (range(1, 4) as $_) {
            $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        }

        $profile = $user->riskProfile()->first();

        $this->assertSame(100, $profile->risk_score);
        $this->assertSame(RiskProfile::LEVEL_CRITICAL, $profile->risk_level);
    }

    public function test_record_signal_recalculates_and_is_append_only(): void
    {
        $user = $this->makeUser();

        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', ['k' => 'v']);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);

        $this->assertSame(2, RiskEvent::where('user_id', $user->id)->count());
        $this->assertSame(30, $user->riskProfile()->first()->risk_score);
        $this->assertSame(RiskProfile::LEVEL_MEDIUM, $user->riskProfile()->first()->risk_level);
    }

    public function test_unknown_severity_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        $this->risk()->recordSignal($this->makeUser(), RiskEvent::TYPE_RISK_FLAG, 'super-bad', 'risk', []);
    }

    public function test_null_user_signal_persists_without_profile(): void
    {
        $event = $this->risk()->recordSignal(null, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_LOW, 'risk', []);

        $this->assertNull($event->user_id);
        $this->assertSame(RiskEvent::SEVERITY_LOW, $event->severity);
    }

    // ------------------------------------------------------------------
    // Actions + gates
    // ------------------------------------------------------------------

    public function test_action_for_each_level(): void
    {
        $low = $this->makeUser();
        $this->assertSame(FraudRiskService::ACTION_ALLOW, $this->risk()->actionFor($low));

        $medium = $this->makeUser();
        $this->risk()->recordSignal($medium, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->risk()->recordSignal($medium, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_FLAG, $this->risk()->actionFor($medium));

        $high = $this->makeUser();
        $this->risk()->recordSignal($high, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($high, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_REQUIRE_REVIEW, $this->risk()->actionFor($high));

        $critical = $this->makeUser();
        $this->risk()->recordSignal($critical, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->risk()->recordSignal($critical, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->assertSame(FraudRiskService::ACTION_RESTRICT, $this->risk()->actionFor($critical));
    }

    public function test_low_risk_gate_allows_silently(): void
    {
        $user = $this->makeUser();

        $this->assertSame(FraudRiskService::ACTION_ALLOW, $this->risk()->gate($user, 'registration'));
        $this->assertSame(0, RiskEvent::where('user_id', $user->id)->count());
    }

    public function test_medium_risk_gate_flags_but_allows(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_MEDIUM, 'risk', []);

        $action = $this->risk()->gate($user, 'registration');

        $this->assertSame(FraudRiskService::ACTION_FLAG, $action);
        $this->assertFalse($user->riskProfile()->first()->manual_review_required);
    }

    public function test_high_risk_gate_flags_for_review_and_allows(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $action = $this->risk()->gate($user, 'registration');

        $this->assertSame(FraudRiskService::ACTION_REQUIRE_REVIEW, $action);
        $this->assertTrue($user->riskProfile()->first()->manual_review_required);
    }

    public function test_critical_risk_gate_blocks(): void
    {
        $user = $this->makeUser();
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);
        $this->risk()->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_CRITICAL, 'risk', []);

        $this->expectException(DomainException::class);
        $this->risk()->gate($user, 'registration');
    }

    public function test_context_specific_restrictions_block_gates(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($user, Restriction::TYPE_SCORE_SUBMISSION_BLOCKED, 'test', 'manual', $admin);

        // Score submission is blocked…
        try {
            $this->risk()->gate($user, 'score_submission');
            $this->fail('Expected score submission to be blocked.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('restricted', $e->getMessage());
        }

        // …but an unrelated context is not blocked (no blanket ban). The
        // restriction itself raised an audit signal (high severity → medium
        // risk), so the unrelated action is allowed-but-flagged, not blocked.
        $this->assertNotSame(FraudRiskService::ACTION_RESTRICT, $this->risk()->gate($user, 'registration'));
    }

    // ------------------------------------------------------------------
    // Restrictions
    // ------------------------------------------------------------------

    public function test_restriction_requires_reason_and_valid_type(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        try {
            $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, '   ', 'manual', $admin);
            $this->fail('Expected a DomainException for an empty reason.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->restrictions()->restrict($user, 'not_a_type', 'x', 'manual', $admin);
    }

    public function test_suspension_freezes_profile_and_records_audit(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict(
            $user,
            Restriction::TYPE_ACCOUNT_SUSPENDED,
            'ban evasion review',
            'manual',
            $admin,
        );

        $this->assertSame(Restriction::STATUS_ACTIVE, $restriction->status);
        $this->assertSame($admin->id, $restriction->actor_id);
        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);
        $this->assertTrue($this->restrictions()->isBlocked($user, []));

        $events = RiskEvent::where('user_id', $user->id)->where('type', RiskEvent::TYPE_ACCOUNT_RESTRICTED)->get();
        $this->assertCount(1, $events);
        $this->assertSame(RiskEvent::SEVERITY_CRITICAL, $events->first()->severity);
    }

    public function test_expired_restriction_no_longer_blocks(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict(
            $user,
            Restriction::TYPE_CHECKIN_BLOCKED,
            'temp',
            'manual',
            $admin,
            now()->addDay(),
        );

        $this->assertTrue($this->restrictions()->isBlocked($user, [Restriction::TYPE_CHECKIN_BLOCKED]));

        // Simulate the expiry passing.
        Restriction::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($this->restrictions()->isBlocked($user, [Restriction::TYPE_CHECKIN_BLOCKED]));
    }

    public function test_lift_restores_profile_status(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);

        $this->restrictions()->lift($restriction, $admin);

        $this->assertSame(Restriction::STATUS_LIFTED, $restriction->fresh()->status);
        $this->assertSame($admin->id, $restriction->fresh()->lifted_by);
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $user->riskProfile()->first()->status);
        $this->assertFalse($this->restrictions()->isBlocked($user, []));
    }

    public function test_lifting_twice_is_rejected(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $restriction = $this->restrictions()->restrict($user, Restriction::TYPE_DISPUTE_BLOCKED, 'x', 'manual', $admin);
        $this->restrictions()->lift($restriction, $admin);

        $this->expectException(DomainException::class);
        $this->restrictions()->lift($restriction, $admin);
    }

    public function test_other_suspension_keeps_profile_suspended_after_lift(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $first = $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'one', 'manual', $admin);
        $this->restrictions()->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'two', 'manual', $admin);

        $this->restrictions()->lift($first, $admin);

        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $user->riskProfile()->first()->status);
    }

    // ------------------------------------------------------------------
    // Mass-assignment protection
    // ------------------------------------------------------------------

    public function test_risk_models_reject_mass_assignment(): void
    {
        $user = $this->makeUser();

        // All Phase 10 models declare `$fillable = []`, so `fill()` is
        // totally guarded and throws a MassAssignmentException.
        foreach ([new RiskProfile(), new RiskEvent(), new Restriction()] as $model) {
            try {
                $model->fill([
                    'user_id' => $user->id,
                    'risk_score' => 99,
                    'risk_level' => RiskProfile::LEVEL_CRITICAL,
                    'severity' => RiskEvent::SEVERITY_CRITICAL,
                    'type' => Restriction::TYPE_ACCOUNT_SUSPENDED,
                    'status' => RiskProfile::STATUS_SUSPENDED,
                ]);
                $this->fail(get_class($model) . ' accepted mass assignment.');
            } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```


#### `tests/Feature/AntiFraudTrustSafetyTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\IpIntel;
use App\Models\IpLink;
use App\Models\MatchAnomaly;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AccountLinkService;
use App\Services\AntiCheatService;
use App\Services\DeviceFingerprintService;
use App\Services\IdentityVerificationService;
use App\Services\IpIntelligenceService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 10 — identity verification, device/IP pseudonymization, account
 * linking, ban-evasion detection, anti-cheat incidents and match anomalies.
 */
class AntiFraudTrustSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Test Tournament';
        $t->slug = 'test-tournament-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = 'UID' . rand(100000, 999999);
        $t->status = 'confirmed';
        $t->save();

        return $t;
    }

    protected function makeRequest(array $server = []): Request
    {
        return Request::create('/', 'GET', [], [], [], array_merge([
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'REMOTE_ADDR' => '203.0.113.7',
        ], $server));
    }

    protected function identity(): IdentityVerificationService
    {
        return app(IdentityVerificationService::class);
    }

    protected function devices(): DeviceFingerprintService
    {
        return app(DeviceFingerprintService::class);
    }

    protected function ipIntel(): IpIntelligenceService
    {
        return app(IpIntelligenceService::class);
    }

    protected function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    protected function antiCheat(): AntiCheatService
    {
        return app(AntiCheatService::class);
    }

    protected function restrictions(): RestrictionService
    {
        return app(RestrictionService::class);
    }

    // ------------------------------------------------------------------
    // Identity verification state machine
    // ------------------------------------------------------------------

    public function test_identity_defaults_to_unverified(): void
    {
        $user = $this->makeUser();

        $record = $this->identity()->effectiveStatus($user);

        $this->assertSame(IdentityVerification::STATUS_UNVERIFIED, $record->status);
        $this->assertSame('manual', $record->provider);
        $this->assertFalse($record->isVerified());
    }

    public function test_request_moves_unverified_to_pending(): void
    {
        $user = $this->makeUser();

        $record = $this->identity()->request($user);

        $this->assertSame(IdentityVerification::STATUS_PENDING, $record->status);
        $this->assertFalse($record->isVerified());
    }

    public function test_request_is_idempotent_while_pending(): void
    {
        $user = $this->makeUser();
        $this->identity()->request($user);

        $this->identity()->request($user);

        $this->assertSame(1, IdentityVerification::where('user_id', $user->id)->count());
        $this->assertSame(IdentityVerification::STATUS_PENDING, $user->identityVerification()->first()->status);
    }

    public function test_only_admin_manual_review_verifies(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $this->identity()->request($user);

        $record = $this->identity()->verifyManually($user, $admin, 'documents reviewed');

        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $record->status);
        $this->assertSame($admin->id, $record->reviewed_by);
        $this->assertNotNull($record->verified_at);
        $this->assertSame('documents reviewed', $record->notes);
        $this->assertTrue($record->isVerified());
    }

    public function test_verified_record_expires_lazily(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->identity()->verifyManually($user, $admin, null, now()->addDays(30));
        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $this->identity()->effectiveStatus($user)->status);

        // Force the expiry into the past.
        IdentityVerification::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(IdentityVerification::STATUS_EXPIRED, $this->identity()->effectiveStatus($user)->status);
    }

    public function test_rejection_and_re_request(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->identity()->reject($user, $admin, 'unreadable');
        $this->assertSame(IdentityVerification::STATUS_REJECTED, $user->identityVerification()->first()->status);

        $this->identity()->request($user);
        $this->assertSame(IdentityVerification::STATUS_PENDING, $user->identityVerification()->first()->status);
    }

    public function test_verified_identity_cannot_be_rejected(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $this->identity()->verifyManually($user, $admin);

        $this->expectException(DomainException::class);
        $this->identity()->reject($user, $admin, 'changed my mind');
    }

    public function test_manual_provider_never_fabricates_verification(): void
    {
        $user = $this->makeUser();

        $result = $this->identity()->attemptViaProvider($user, 'manual');

        $this->assertSame(IdentityVerification::STATUS_PENDING, $result['status']);
        $this->assertSame(0, IdentityVerification::where('user_id', $user->id)->where('status', IdentityVerification::STATUS_VERIFIED)->count());
    }

    public function test_unknown_identity_provider_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->identity()->attemptViaProvider($this->makeUser(), 'fake-kyc');
    }

    // ------------------------------------------------------------------
    // Device fingerprinting (pseudonymous)
    // ------------------------------------------------------------------

    public function test_device_hash_is_deterministic_and_pseudonymous(): void
    {
        $hash = $this->devices()->hashFrom($this->makeRequest());

        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, $this->devices()->hashFrom($this->makeRequest()));
        $this->assertStringNotContainsString('TestAgent', $hash);

        $other = $this->devices()->hashFrom($this->makeRequest(['HTTP_USER_AGENT' => 'OtherAgent/9.9']));
        $this->assertNotSame($hash, $other);
    }

    public function test_register_creates_device_and_link(): void
    {
        $user = $this->makeUser();

        $device = $this->devices()->register($this->makeRequest(), $user);

        $this->assertNotNull($device);
        $this->assertSame(Device::STATUS_ACTIVE, $device->status);
        $this->assertSame(1, DeviceLink::where('user_id', $user->id)->count());
        $this->assertSame(1, $this->devices()->devicesFor($user)->count());
    }

    public function test_shared_device_below_tolerance_is_not_flagged(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 3))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->devices()->register($request, $u));

        // 3 accounts on one device — under the default tolerance of 4.
        $this->assertSame(0, RiskEvent::where('type', RiskEvent::TYPE_AUTH_DEVICE_SHARED)->count());
        // …but the accounts are still linked as a similarity signal.
        $this->assertGreaterThan(0, AccountLink::count());
    }

    public function test_shared_device_above_tolerance_raises_signal_only(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 9))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->devices()->register($request, $u));

        $signals = RiskEvent::where('type', RiskEvent::TYPE_AUTH_DEVICE_SHARED)->get();

        $this->assertTrue($signals->isNotEmpty());
        $this->assertTrue($signals->every(fn ($e) => in_array($e->severity, [RiskEvent::SEVERITY_MEDIUM, RiskEvent::SEVERITY_HIGH], true)));

        // No account is suspended by a shared device alone.
        foreach ($users as $u) {
            $this->assertFalse($this->restrictions()->isBlocked($u, []));
        }
    }

    public function test_device_block_marks_blocked(): void
    {
        $user = $this->makeUser();
        $device = $this->devices()->register($this->makeRequest(), $user);

        $this->devices()->block($device);

        $this->assertTrue($device->fresh()->isBlocked());
    }

    // ------------------------------------------------------------------
    // IP intelligence (pseudonymous)
    // ------------------------------------------------------------------

    public function test_ip_hashes_are_pseudonymous_and_grouped(): void
    {
        $service = $this->ipIntel();

        $hash = $service->ipHash('203.0.113.7');
        $subnet = $service->subnetHash('203.0.113.7');

        $this->assertSame(64, strlen($hash));
        $this->assertNotSame('203.0.113.7', $hash);
        $this->assertNotSame($hash, $subnet);
        $this->assertSame($subnet, $service->subnetHash('203.0.113.99')); // same /24
        $this->assertNotSame($subnet, $service->subnetHash('203.0.114.7')); // different /24
    }

    public function test_observe_records_ip_intel_without_raw_ip(): void
    {
        $user = $this->makeUser();

        $intel = $this->ipIntel()->observe($this->makeRequest(), $user);

        $this->assertSame(1, $intel->observation_count);
        $this->assertSame(1, IpLink::where('user_id', $user->id)->count());
        $this->assertSame($this->ipIntel()->ipHash('203.0.113.7'), $intel->ip_hash);

        // The raw IP is never stored anywhere.
        $this->assertStringNotContainsString('203.0.113.7', $intel->ip_hash);
        $this->assertDatabaseMissing('ip_intel', ['ip_hash' => '203.0.113.7']);
    }

    public function test_shared_network_is_tolerated_below_threshold(): void
    {
        $request = $this->makeRequest();

        $users = collect(range(1, 5))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->ipIntel()->observe($request, $u));

        $this->assertSame(0, RiskEvent::where('type', RiskEvent::TYPE_AUTH_IP_SHARED)->count());
    }

    public function test_shared_network_above_threshold_is_a_low_signal_only(): void
    {
        config(['antifraud.ip.max_accounts_shared' => 3]);

        $request = $this->makeRequest();

        $users = collect(range(1, 5))->map(fn () => $this->makeUser());
        $users->each(fn ($u) => $this->ipIntel()->observe($request, $u));

        $signals = RiskEvent::where('type', RiskEvent::TYPE_AUTH_IP_SHARED)->get();

        $this->assertTrue($signals->isNotEmpty());
        $this->assertTrue($signals->every(fn ($e) => $e->severity === RiskEvent::SEVERITY_LOW));
    }

    // ------------------------------------------------------------------
    // Account similarity + ban evasion
    // ------------------------------------------------------------------

    public function test_account_link_is_canonical_and_never_duplicated(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->links()->link($a, $b, AccountLink::STRENGTH_MODERATE, ['shared_device'], 'device');
        $this->links()->link($b, $a, AccountLink::STRENGTH_MODERATE, ['shared_device'], 'device');

        $this->assertSame(1, AccountLink::count());

        $link = AccountLink::first();
        $this->assertSame(min($a->id, $b->id), $link->user_id);
        $this->assertSame(max($a->id, $b->id), $link->linked_user_id);
    }

    public function test_link_strength_upgrades_but_never_downgrades(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->links()->link($a, $b, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');
        $this->links()->link($a, $b, AccountLink::STRENGTH_STRONG, ['payment_match'], 'payment');

        $link = AccountLink::first();
        $this->assertSame(AccountLink::STRENGTH_STRONG, $link->strength);

        $this->links()->link($a, $b, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');
        $this->assertSame(AccountLink::STRENGTH_STRONG, $link->fresh()->strength);
    }

    public function test_self_link_and_invalid_strength_are_rejected(): void
    {
        $a = $this->makeUser();

        try {
            $this->links()->link($a, $a, AccountLink::STRENGTH_WEAK, ['x'], 'x');
            $this->fail('Expected self-link to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->links()->link($a, $this->makeUser(), 'overwhelming', ['x'], 'x');
    }

    public function test_strong_link_to_suspended_account_is_ban_evasion_not_auto_ban(): void
    {
        $restricted = $this->makeUser();
        $newcomer = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($restricted, Restriction::TYPE_ACCOUNT_SUSPENDED, 'previous abuse', 'manual', $admin);

        $this->links()->link($newcomer, $restricted, AccountLink::STRENGTH_STRONG, ['shared_device', 'payment_match'], 'device');

        // The newcomer is flagged for review with a ban-evasion signal…
        $this->assertTrue($newcomer->riskProfile()->first()->manual_review_required);
        $this->assertTrue(
            RiskEvent::where('user_id', $newcomer->id)->where('type', RiskEvent::TYPE_BAN_EVASION)->exists()
        );

        // …but is NOT automatically suspended.
        $this->assertFalse($this->restrictions()->isBlocked($newcomer, []));
        $this->assertSame(RiskProfile::STATUS_ACTIVE, $newcomer->riskProfile()->first()->status);
    }

    public function test_weak_link_to_suspended_account_is_not_ban_evasion(): void
    {
        $restricted = $this->makeUser();
        $newcomer = $this->makeUser();
        $admin = $this->makeUser('admin');

        $this->restrictions()->restrict($restricted, Restriction::TYPE_ACCOUNT_SUSPENDED, 'abuse', 'manual', $admin);

        $this->links()->link($newcomer, $restricted, AccountLink::STRENGTH_WEAK, ['shared_ip'], 'ip');

        $this->assertFalse(
            RiskEvent::where('user_id', $newcomer->id)->where('type', RiskEvent::TYPE_BAN_EVASION)->exists()
        );
    }

    // ------------------------------------------------------------------
    // Anti-cheat incidents
    // ------------------------------------------------------------------

    public function test_open_incident_validates_category_and_severity(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $reporter = $this->makeUser('moderator');

        try {
            $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'speedhax', 'high');
            $this->fail('Expected invalid category to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('category', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'aimbot', 'extreme');
    }

    public function test_confirmed_incident_applies_audited_restriction(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $accused = $this->makeUser();
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $reporter,
            AntiCheatIncident::SOURCE_STAFF,
            AntiCheatService::CATEGORY_AIMBOT,
            'high',
            'wall-tracking footage',
            'evidence/ref-1',
        );

        $this->assertSame(AntiCheatIncident::STATUS_FLAGGED, $incident->status);
        $this->assertSame('evidence/ref-1', $incident->evidence_reference);

        $this->antiCheat()->review($incident, $reporter);
        $this->assertSame(AntiCheatIncident::STATUS_UNDER_REVIEW, $incident->fresh()->status);
        $this->assertSame($reporter->id, $incident->fresh()->reviewer_id);

        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CONFIRMED, 'replay shows tracking');

        $this->assertSame(AntiCheatIncident::STATUS_CONFIRMED, $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);

        // A granular restriction is applied — never an uncontrolled ban.
        $this->assertTrue(
            $this->restrictions()->isBlocked($accused, [Restriction::TYPE_SCORE_SUBMISSION_BLOCKED])
        );

        $this->assertTrue(
            RiskEvent::where('user_id', $accused->id)->where('type', RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED)->exists()
        );
    }

    public function test_cleared_incident_is_false_positive_safe(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $accused = $this->makeUser();
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $reporter,
            AntiCheatIncident::SOURCE_PARTICIPANT,
            AntiCheatService::CATEGORY_TEAMING,
            'low',
        );

        $this->antiCheat()->review($incident, $reporter);
        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CLEARED, 'both teams denied and no evidence');

        $this->assertSame(AntiCheatIncident::STATUS_CLEARED, $incident->fresh()->status);
        $this->assertFalse($this->restrictions()->isBlocked($accused, []));
    }

    public function test_incident_transitions_are_enforced(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $reporter = $this->makeUser('moderator');

        $incident = $this->antiCheat()->openIncident($tournament, null, null, null, $reporter, AntiCheatIncident::SOURCE_STAFF, 'other', 'low');

        // A flagged incident cannot be resolved without review.
        $this->expectException(DomainException::class);
        $this->antiCheat()->resolve($incident, $reporter, AntiCheatIncident::STATUS_CLEARED, 'nope');
    }

    // ------------------------------------------------------------------
    // Match anomalies (deterministic, never "cheating")
    // ------------------------------------------------------------------

    public function test_record_anomaly_validates_kind_and_severity(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $this->makeUser());
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'completed';
        $match->save();

        $anomaly = $this->antiCheat()->recordAnomaly($match, $tournament, MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, MatchAnomaly::SEVERITY_SUSPICIOUS, ['kills' => 61]);

        $this->assertSame(MatchAnomaly::SEVERITY_SUSPICIOUS, $anomaly->status);
        $this->assertSame(MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, $anomaly->kind);

        $this->expectException(DomainException::class);
        $this->antiCheat()->recordAnomaly($match, $tournament, 'impossible_score', MatchAnomaly::SEVERITY_ANOMALY, []);
    }

    public function test_abnormal_kill_ratio_is_detected_on_submission(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $captain = $this->makeUser();
        $team = $this->makeTeam($tournament, $captain);
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 999, 1);

        $this->assertCount(1, $created);
        $this->assertSame(MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, $created->first()->kind);
        $this->assertSame(MatchAnomaly::SEVERITY_SUSPICIOUS, $created->first()->status);

        // An anomaly is an observation, never an auto-accusation.
        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_repeated_pattern_is_detected(): void
    {
        config(['antifraud.anomaly.repeat_pattern_threshold' => 1]);

        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $captain = $this->makeUser();
        $team = $this->makeTeam($tournament, $captain);

        $other = new GameMatch();
        $other->tournament_id = $tournament->id;
        $other->round = 1;
        $other->match_no = 2;
        $other->team1_id = $team->id;
        $other->status = 'completed';
        $other->save();

        $score = new Score();
        $score->match_id = $other->id;
        $score->team_id = $team->id;
        $score->kills = 20;
        $score->placement = 2;
        $score->points = 0;
        $score->save();

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 2;
        $match->match_no = 3;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 20, 2);

        $this->assertCount(1, $created);
        $this->assertSame(MatchAnomaly::KIND_REPEATED_PATTERN, $created->first()->kind);
        $this->assertSame(MatchAnomaly::SEVERITY_ANOMALY, $created->first()->status);
    }

    public function test_normal_submission_creates_no_anomaly(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $this->makeUser());
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->status = 'live';
        $match->save();

        $created = $this->antiCheat()->analyzeScoreSubmission($match, $team, 12, 3);

        $this->assertCount(0, $created);
        $this->assertSame(0, MatchAnomaly::count());
    }
}
```


#### `tests/Feature/AntiFraudSecurityHttpTest.php`

```php
<?php

namespace Tests\Feature;

use App\Exceptions\PayoutReviewRequiredException;
use App\Models\AntiCheatIncident;
use App\Models\IdentityVerification;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FraudRiskService;
use App\Services\PayoutService;
use App\Services\RestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 10 — HTTP authorization (IDOR / role escalation), admin security UI
 * smoke and the payout fraud gate.
 */
class AntiFraudSecurityHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Test Tournament';
        $t->slug = 'test-tournament-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function risk(): FraudRiskService
    {
        return app(FraudRiskService::class);
    }

    // ------------------------------------------------------------------
    // Admin security UI — role gating
    // ------------------------------------------------------------------

    public function test_guest_is_redirected_from_security_routes(): void
    {
        $this->get('/admin/security')->assertRedirect('/login');
        $this->get('/security/incidents')->assertRedirect('/login');
    }

    public function test_player_cannot_access_admin_security(): void
    {
        $this->actingAs($this->makeUser('player'))->get('/admin/security')->assertStatus(403);
    }

    public function test_organizer_cannot_access_admin_security(): void
    {
        $this->actingAs($this->makeUser('organizer'))->get('/admin/security')->assertStatus(403);
    }

    public function test_moderator_cannot_access_admin_security(): void
    {
        // The admin route middleware is admin-only, even though moderators
        // may view the read-only security summary at /moderation/security.
        $this->actingAs($this->makeUser('moderator'))->get('/admin/security')->assertStatus(403);
    }

    public function test_admin_can_access_security_dashboard(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get(route('admin.security.dashboard'))
            ->assertOk()
            ->assertSee('Security Dashboard');
    }

    public function test_admin_can_access_users_and_events_lists(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('admin.security.users'))->assertOk();
        $this->actingAs($admin)->get(route('admin.security.events'))->assertOk();
    }

    public function test_player_cannot_view_another_users_risk_detail(): void
    {
        $player = $this->makeUser('player');
        $victim = $this->makeUser('player');

        $this->actingAs($player)->get(route('admin.security.user', $victim))->assertStatus(403);
    }

    public function test_admin_can_view_another_users_risk_detail(): void
    {
        $admin = $this->makeUser('admin');
        $victim = $this->makeUser('player');

        $this->actingAs($admin)
            ->get(route('admin.security.user', $victim))
            ->assertOk()
            ->assertSee('Risk Profile');
    }

    // ------------------------------------------------------------------
    // Incidents — staff/organizer/player gating
    // ------------------------------------------------------------------

    public function test_player_cannot_view_the_incident_queue(): void
    {
        $this->actingAs($this->makeUser('player'))->get(route('security.incidents.index'))->assertStatus(403);
    }

    public function test_organizer_can_view_incidents(): void
    {
        $this->actingAs($this->makeUser('organizer'))->get(route('security.incidents.index'))->assertOk();
    }

    public function test_moderator_can_view_incidents(): void
    {
        $this->actingAs($this->makeUser('moderator'))->get(route('security.incidents.index'))->assertOk();
    }

    public function test_organizer_cannot_open_an_incident(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($organizer)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'category' => 'aimbot',
            'severity' => 'high',
            'description' => 'suspicious',
        ])->assertStatus(403);

        $this->assertSame(0, AntiCheatIncident::count());
    }

    public function test_moderator_can_open_review_and_resolve_incident(): void
    {
        $moderator = $this->makeUser('moderator');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $accused = $this->makeUser('player');

        $this->actingAs($moderator)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'accused_user_id' => $accused->id,
            'category' => 'teaming',
            'severity' => 'medium',
            'description' => 'coordinated',
        ])->assertRedirect(route('security.incidents.index'));

        $incident = AntiCheatIncident::firstOrFail();
        $this->assertSame(AntiCheatIncident::STATUS_FLAGGED, $incident->status);

        $this->actingAs($moderator)->post(route('security.incidents.review', $incident))
            ->assertRedirect();

        $this->actingAs($moderator)->post(route('security.incidents.resolve', $incident), [
            'resolution' => AntiCheatIncident::STATUS_DISMISSED,
            'resolution_text' => 'insufficient evidence',
        ])->assertRedirect();

        $this->assertSame(AntiCheatIncident::STATUS_DISMISSED, $incident->fresh()->status);
    }

    public function test_player_cannot_resolve_an_incident(): void
    {
        $moderator = $this->makeUser('moderator');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($moderator)->post(route('security.incidents.open'), [
            'tournament_id' => $tournament->id,
            'category' => 'other',
            'severity' => 'low',
        ]);

        $incident = AntiCheatIncident::firstOrFail();

        $this->actingAs($this->makeUser('player'))->post(route('security.incidents.resolve', $incident), [
            'resolution' => AntiCheatIncident::STATUS_CLEARED,
            'resolution_text' => 'hax',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Restrictions + identity — admin-only actions
    // ------------------------------------------------------------------

    public function test_moderator_cannot_apply_a_restriction(): void
    {
        $moderator = $this->makeUser('moderator');
        $victim = $this->makeUser('player');

        $this->actingAs($moderator)->post(route('admin.security.restrict', $victim), [
            'type' => Restriction::TYPE_ACCOUNT_SUSPENDED,
            'reason' => 'test',
        ])->assertStatus(403);

        $this->assertSame(0, Restriction::count());
    }

    public function test_admin_can_restrict_and_lift(): void
    {
        $admin = $this->makeUser('admin');
        $victim = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.restrict', $victim), [
            'type' => Restriction::TYPE_DISPUTE_BLOCKED,
            'reason' => 'dispute abuse',
        ])->assertRedirect();

        $restriction = Restriction::firstOrFail();
        $this->assertTrue($restriction->isActive());

        $this->actingAs($admin)->post(route('admin.security.lift', $restriction))->assertRedirect();

        $this->assertFalse($restriction->fresh()->isActive());
    }

    public function test_player_can_request_verification_for_themselves(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->from('/wallet')->post(route('security.identity.request'))->assertRedirect('/wallet');

        $this->assertSame(IdentityVerification::STATUS_PENDING, $player->identityVerification()->first()->status);
    }

    public function test_player_cannot_verify_themselves(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->post(route('admin.security.verify', $player), ['notes' => 'self'])
            ->assertStatus(403);

        $this->assertSame(
            0,
            IdentityVerification::where('user_id', $player->id)->where('status', IdentityVerification::STATUS_VERIFIED)->count()
        );
    }

    public function test_admin_verifies_identity_and_it_shows_in_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.security.verify', $player), ['notes' => 'manual review passed'])
            ->assertRedirect();

        $this->assertSame(IdentityVerification::STATUS_VERIFIED, $player->identityVerification()->first()->status);

        $this->actingAs($player)->get(route('wallet.index'))->assertOk()->assertSee('Verified');
    }

    // ------------------------------------------------------------------
    // Payout fraud gate (Phase 09 service + Phase 10 gate)
    // ------------------------------------------------------------------

    protected function makePayout(Tournament $tournament, User $recipient): Payout
    {
        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = 'approved';
        $distribution->pool_minor = 10000;
        $distribution->total_allocated_minor = 10000;
        $distribution->save();

        $payout = new Payout();
        $payout->distribution_id = $distribution->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->rank = 1;
        $payout->amount_minor = 10000;
        $payout->currency = 'BDT';
        $payout->status = Payout::STATUS_APPROVED;
        $payout->payout_method = Payout::METHOD_WALLET;
        $payout->provider = 'wallet';
        $payout->save();

        return $payout;
    }

    public function test_low_risk_recipient_payout_processes(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $payout = $this->makePayout($tournament, $recipient);

        $processed = app(PayoutService::class)->process($payout, $admin);

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertSame(10000, $recipient->wallet()->first()->balance_minor);
    }

    public function test_high_risk_recipient_payout_is_held_not_failed(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        // Push the recipient to high risk (2 × high severity = 60).
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $this->assertSame(RiskProfile::LEVEL_HIGH, $recipient->riskProfile()->first()->risk_level);

        $payout = $this->makePayout($tournament, $recipient);

        try {
            app(PayoutService::class)->process($payout, $admin);
            $this->fail('Expected the payout to be held for fraud review.');
        } catch (PayoutReviewRequiredException $e) {
            // Held, not failed, not confiscated.
        }

        $this->assertSame(Payout::STATUS_APPROVED, $payout->fresh()->status);
        $this->assertNull($recipient->wallet()->first());
        $this->assertTrue($recipient->riskProfile()->first()->manual_review_required);
    }

    public function test_authorized_override_processes_held_payout_with_reason(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);
        $this->risk()->recordSignal($recipient, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_HIGH, 'risk', []);

        $payout = $this->makePayout($tournament, $recipient);

        $processed = app(PayoutService::class)->processWithOverride($payout, $admin, 'manual review cleared — prize is legitimate');

        $this->assertSame(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertSame(10000, $recipient->wallet()->first()->balance_minor);

        $event = $payout->events()->where('event', \App\Models\PayoutEvent::EVENT_PROCESSING)->first();
        $this->assertTrue((bool) ($event->metadata['override'] ?? false));
        $this->assertSame('manual review cleared — prize is legitimate', $event->metadata['reason'] ?? null);
    }

    public function test_override_requires_a_reason(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $recipient = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        $payout = $this->makePayout($tournament, $recipient);

        $this->expectException(\DomainException::class);
        app(PayoutService::class)->processWithOverride($payout, $admin, '   ');
    }

    public function test_registration_gate_blocks_suspended_user(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');

        app(RestrictionService::class)->restrict($player, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->actingAs($player)->post(route('teams.store', $tournament), [
            'name' => 'Blocked Team',
            'captain_name' => $player->name,
            'phone' => '01700000000',
            'game_uid' => 'UID123456',
        ])->assertRedirect();

        $this->assertSame(0, $tournament->teams()->count());
        $this->assertSame(RiskProfile::STATUS_SUSPENDED, $player->riskProfile()->first()->status);
    }
}
```

### 5.2 Modified files


#### `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Sensitive fields (role, wallet_balance) are intentionally excluded from
     * mass assignment. `role` must be set explicitly (see AuthController) and
     * can only ever be 'player' or 'organizer' at registration time.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
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
}
```


#### `app/Models/Tournament.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    /**
     * Lifecycle states. These are the single source of truth for the values
     * stored in the `status` column.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that are visible on public listings. DRAFT (not yet published)
     * and CANCELLED tournaments are hidden.
     */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_LIVE,
        self::STATUS_FINISHED,
    ];

    /**
     * Valid state transitions. A tournament may only move along these edges;
     * it can never jump arbitrarily between states. FINISHED and CANCELLED
     * are terminal.
     *
     * draft    → open, cancelled
     * open     → closed, live, cancelled   (open == published + accepting)
     * closed   → live, cancelled
     * live     → finished
     * finished → (terminal)
     * cancelled→ (terminal)
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_CLOSED, self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_CLOSED => [self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_FINISHED],
        self::STATUS_FINISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket formats. Only formats that are actually implemented may be
     * selected; the others are intentionally absent so they never appear
     * as a selectable option.
     */
    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';

    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM,
        self::FORMAT_DOUBLE_ELIM,
    ];

    /**
     * organizer_id, slug and status are set server-side only. They are
     * excluded from mass assignment so a client can never hijack ownership
     * or lifecycle state.
     */
    protected $fillable = [
        'name',
        'game_mode',
        'map',
        'entry_fee',
        'prize_pool',
        'team_slots',
        'team_size',
        'rules',
        'starts_at',
        'check_in_starts_at',
        'check_in_ends_at',
        'format',
        'dispute_window_hours',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
        'bracket_size' => 'integer',
        'dispute_window_hours' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function confirmedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Teams that currently occupy a slot: pending (awaiting payment
     * verification) or confirmed. Withdrawn, rejected, no-show and
     * waitlisted teams do not occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
    }

    /**
     * Teams on the waitlist, in deterministic FIFO order.
     */
    public function waitlistedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_WAITLISTED);
    }

    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scoring rule-set versions for this tournament (Phase 06).
     */
    public function scoringRules()
    {
        return $this->hasMany(ScoringRule::class);
    }

    /**
     * Disputes raised against matches in this tournament (Phase 07).
     */
    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * The participant dispute window in hours (default 24). A value of 0
     * disables participant disputes entirely; staff always bypass.
     */
    public function disputeWindowHours(): int
    {
        return (int) ($this->dispute_window_hours ?? 24);
    }

    /**
     * The entry fee in integer minor units (poisha), computed from the
     * server-side `entry_fee` column — never from client input.
     */
    public function entryFeeMinor(): int
    {
        $raw = $this->getRawOriginal('entry_fee');

        if ($raw === null) {
            $raw = $this->entry_fee;
        }

        try {
            return \App\Support\Money::toMinor($raw);
        } catch (\DomainException $e) {
            return 0;
        }
    }

    /**
     * Number of registration slots still available. Counts both pending and
     * confirmed teams so that a team awaiting payment still reserves its slot.
     */
    public function slotsLeft(): int
    {
        return max(0, (int) $this->team_slots - $this->registeredTeams()->count());
    }

    public function isFull(): bool
    {
        return $this->slotsLeft() <= 0;
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the configured start time has already passed.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    /**
     * Whether the tournament is currently accepting new registrations.
     * Status is the primary authority; the start time acts as a hard deadline.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->hasStarted();
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    /**
     * The declared prize pool in integer minor units (poisha), computed from
     * the server-side `prize_pool` column — never from client input and never
     * using floating-point arithmetic.
     */
    public function prizePoolMinor(): int
    {
        $raw = $this->getRawOriginal('prize_pool');

        if ($raw === null) {
            $raw = $this->prize_pool;
        }

        try {
            return \App\Support\Money::toMinor($raw);
        } catch (\DomainException $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Phase 09 — prize distribution + payouts + settlement
    // ------------------------------------------------------------------

    /**
     * Configurable prize tiers (fixed or percentage), ordered by rank.
     */
    public function prizeTiers()
    {
        return $this->hasMany(PrizeTier::class)->orderBy('position');
    }

    /**
     * Prize-distribution workflow records (one terminal record per settled
     * attempt; a retry after failure/cancellation creates a new record).
     */
    public function prizeDistributions()
    {
        return $this->hasMany(PrizeDistribution::class)->orderByDesc('id');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    public function financialSettlement()
    {
        return $this->hasOne(FinancialSettlement::class);
    }

    public function settlementAdjustments()
    {
        return $this->hasMany(SettlementAdjustment::class);
    }

    /**
     * Anti-cheat incidents opened against matches in this tournament
     * (Phase 10).
     */
    public function antiCheatIncidents()
    {
        return $this->hasMany(AntiCheatIncident::class);
    }

    // ------------------------------------------------------------------
    // Phase 04 — check-in window + bracket eligibility
    // ------------------------------------------------------------------

    /**
     * Check-in is only active when BOTH window timestamps are configured.
     * Tournaments created before this feature (or without a window) simply
     * do not require check-in, preserving the Phase 02 behaviour.
     */
    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null && $this->check_in_ends_at !== null;
    }

    /**
     * Whether the check-in window is currently open.
     */
    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        return now()->between($this->check_in_starts_at, $this->check_in_ends_at);
    }

    /**
     * Whether the check-in window has already closed.
     */
    public function checkInHasClosed(): bool
    {
        return $this->hasCheckIn() && $this->check_in_ends_at->isPast();
    }

    /**
     * The set of teams eligible for competitive bracket generation:
     * confirmed teams that have checked in (when check-in is configured).
     * This is the single source of truth used by BracketService.
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()
            ->where('status', Team::STATUS_CONFIRMED)
            ->when($this->hasCheckIn(), fn ($q) => $q->whereNotNull('checked_in_at'));
    }
}
```


#### `app/Http/Controllers/AuthController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\IpIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:60|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            'role' => 'required|in:player,organizer',
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
        $user->save();

        Auth::login($user);

        // Phase 10 — record pseudonymous device + IP observations for the
        // new account (never throws; observation only).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        return redirect()->route('home')->with('success', 'Welcome to FF Arena, ' . $user->name . '!');
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Phase 10 — refresh pseudonymous device + IP observations.
            $this->devices->register($request, $request->user());
            $this->ipIntel->observe($request, $request->user());

            return redirect()->intended(route('home'));
        }

        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
```


#### `app/Http/Controllers/TeamController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Services\FraudRiskService;
use App\Services\RosterService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
    ) {
    }

    public function showRegistration(Tournament $tournament)
    {
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'This tournament is not open for registration.');
        }

        return view('teams.register', compact('tournament'));
    }

    public function register(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Phase 10 — fraud/risk gate (restriction + risk-level enforcement).
        try {
            $this->risk->evaluateRegistration($tournament, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Fast-fail lifecycle checks with friendly messages. The authoritative
        // checks run again inside the atomic claim below.
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'Registration is closed for this tournament.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        $captainUid = $this->roster->normalizeUid($data['game_uid']);
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];

        $team = null;
        $waitlisted = false;

        try {
            DB::transaction(function () use ($tournament, $user, $data, $captainUid, $members, &$team, &$waitlisted) {
                $fresh = Tournament::findOrFail($tournament->id);

                if (! $fresh->acceptsRegistration()) {
                    if ($fresh->hasStarted()) {
                        throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                    }

                    throw new RegistrationClosedException('Registration is closed for this tournament.');
                }

                // One-team-per-captain. The database unique(tournament_id,
                // captain_id) index is the final backstop.
                if (Team::where('tournament_id', $fresh->id)->where('captain_id', $user->id)->exists()) {
                    throw new RegistrationClosedException('You have already registered a team in this tournament.');
                }

                // Roster integrity (Phase 03): the captain UID must not
                // already belong to another team in this tournament.
                $this->roster->assertUidAvailable($fresh, $captainUid);

                // ATOMIC SLOT CLAIM — SQLite-compatible concurrency guard.
                //
                // A single UPDATE that only succeeds while the tournament is
                // still open, has not started, and has a free slot. In SQLite
                // this statement acquires the write lock, so everything after
                // it in this transaction is race-free.
                $claimed = DB::table('tournaments')
                    ->where('id', $fresh->id)
                    ->where('status', Tournament::STATUS_OPEN)
                    ->where(function ($q) {
                        $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
                    })
                    ->whereRaw(
                        '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                        [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
                    )
                    ->update(['updated_at' => now()]);

                if ($claimed !== 1) {
                    // No slot. Re-check under the write lock: if the
                    // tournament really is full, the team goes to the
                    // waitlist. Otherwise registration is genuinely closed.
                    $fresh2 = Tournament::findOrFail($fresh->id);

                    if (! $fresh2->acceptsRegistration()) {
                        if ($fresh2->hasStarted()) {
                            throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                        }

                        throw new RegistrationClosedException('Registration is closed for this tournament.');
                    }

                    if (! $fresh2->isFull()) {
                        throw new RegistrationClosedException('Registration is not available for this tournament.');
                    }

                    // Full → waitlist (FIFO).
                    $team = new Team();
                    $team->tournament_id = $fresh2->id;
                    $team->captain_id = $user->id;
                    $team->name = $data['name'];
                    $team->captain_name = $data['captain_name'];
                    $team->phone = $data['phone'];
                    $team->game_uid = $captainUid;
                    $team->status = Team::STATUS_WAITLISTED;
                    $team->waitlisted_at = now();
                    $team->save();

                    $waitlisted = true;
                } else {
                    // Slot claimed → pending (awaits payment).
                    $team = new Team();
                    $team->tournament_id = $fresh->id;
                    $team->captain_id = $user->id;
                    $team->name = $data['name'];
                    $team->captain_name = $data['captain_name'];
                    $team->phone = $data['phone'];
                    $team->game_uid = $captainUid;
                    $team->status = Team::STATUS_PENDING;
                    $team->save();
                }

                // Validate + persist roster members (size, duplicates,
                // cross-team clashes) — all inside the same transaction.
                $normalized = $this->roster->validateNewMembers($fresh, $team, $members);

                foreach ($normalized as $member) {
                    $row = new TeamMember();
                    $row->team_id = $team->id;
                    $row->player_name = $member['player_name'];
                    $row->game_uid = $member['game_uid'];
                    $row->save();
                }
            });
        } catch (RegistrationClosedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // Database backstop: unique(tournament_id, captain_id) for the
            // one-team-per-captain rule, or unique(tournament_id, game_uid)
            // for a captain UID clash.
            return back()->with('error', 'A duplicate team or player was detected. Registration was not saved.');
        }

        // Phase 10 — registration-volume signal (non-blocking observation).
        $teamCount = Team::where('captain_id', $user->id)->count();
        $maxTeams = (int) config('antifraud.registration.max_teams', 5);

        if ($teamCount >= $maxTeams) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_REGISTRATION_VOLUME, RiskEvent::SEVERITY_MEDIUM, 'registration', [
                'team_count' => $teamCount,
            ], $tournament);
        }

        if ($waitlisted) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'All slots are full. Your team is on the waitlist (position '.$team->waitlistPosition().').');
        }

        return redirect()->route('payment.show', [$tournament, $team]);
    }

    /**
     * Withdraw a team before the tournament reaches an irreversible stage.
     * No refund logic is invented here: any existing payment is left
     * untouched and must be handled offline/manually.
     */
    public function withdraw(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('withdraw', $team);

        if (in_array($tournament->status, [
            Tournament::STATUS_LIVE,
            Tournament::STATUS_FINISHED,
            Tournament::STATUS_CANCELLED,
        ], true)) {
            return back()->with('error', 'Teams can no longer withdraw from this tournament.');
        }

        if ($team->status === Team::STATUS_WITHDRAWN) {
            return back()->with('error', 'This team has already been withdrawn.');
        }

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
        // Count prior withdrawals before releasing the captain claim.
        $priorWithdrawals = Team::where('captain_id', $request->user()->id)
            ->where('status', Team::STATUS_WITHDRAWN)
            ->count();

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

        $withdrawals = $priorWithdrawals + 1;
        $threshold = (int) config('antifraud.withdrawal.repeat_threshold', 3);

        if ($withdrawals >= $threshold) {
            $this->risk->recordSignal($request->user(), RiskEvent::TYPE_WITHDRAWAL_REPEAT, RiskEvent::SEVERITY_LOW, 'registration', [
                'withdrawal_count' => $withdrawals,
            ], $tournament);
        }

        return back()->with('success', 'Your team has been withdrawn from the tournament.');
    }

    /**
     * Team / roster management page.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('view', $team);

        $team->load(['members', 'captain']);

        $locked = $this->roster->isLocked($team);
        $slotsLeft = $this->roster->maxMembers($team) - $team->members()->count();

        $canEdit = auth()->check() && (auth()->user()->isAdmin() || ($team->isCaptain(auth()->user()) && ! $locked));
        $canCheckIn = auth()->check() && (auth()->user()->isAdmin() || $team->isCaptain(auth()->user()));

        return view('teams.show', compact('tournament', 'team', 'locked', 'slotsLeft', 'canEdit', 'canCheckIn'));
    }

    /**
     * Add a roster member (captain or admin).
     */
    public function addMember(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('addMember', $team);

        $data = $request->validate([
            'player_name' => 'required|string|max:120',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $member = $this->roster->addMember($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This player is already in the team.');
        }

        return back()->with('success', $member->player_name.' added to the roster.');
    }

    /**
     * Remove a roster member (captain or admin).
     */
    public function removeMember(Request $request, Tournament $tournament, Team $team, TeamMember $member)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('removeMember', $team);

        try {
            $this->roster->removeMember($team, $member, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Member removed from the roster.');
    }

    /**
     * Update team profile (captain or admin).
     */
    public function updateProfile(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('updateProfile', $team);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $this->roster->updateProfile($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This Free Fire UID is already used in this tournament.');
        }

        return back()->with('success', 'Team profile updated.');
    }

    /**
     * Team check-in (captain or admin). Idempotent.
     */
    public function checkIn(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'Your team is already checked in.');
        }

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');
    }
}
```


#### `app/Http/Controllers/MatchController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AntiCheatService;
use App\Services\FraudRiskService;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
        protected AntiCheatService $antiCheat,
    ) {
    }

    public function show(Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $match->load([
            'team1',
            'team2',
            'scores.team',
            'scores.adjustments',
            'scores.scoringRule',
            'nextMatch',
            'loserNextMatch',
            'disputes' => fn ($q) => $q->orderByDesc('created_at'),
            'disputes.opener',
        ]);

        // Whether the current user may open a Phase 07 dispute against this
        // match. Only relevant once the match is completed/disputed.
        $canOpenDispute = false;
        if (auth()->check() && in_array($match->status, [GameMatch::STATUS_COMPLETED, GameMatch::STATUS_DISPUTED], true)) {
            $canOpenDispute = auth()->user()->isAdmin()
                || auth()->user()->isModerator()
                || $tournament->organizer_id === auth()->id()
                || $match->participantTeamFor(auth()->user()) !== null;
        }

        return view('matches.show', compact('tournament', 'match', 'canOpenDispute'));
    }

    /**
     * Publish room details and move a ready/pending match to live.
     */
    public function setRoom(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'room_id' => 'required|string|max:30',
            'room_pass' => 'required|string|max:30',
            'scheduled_at' => 'nullable|date',
        ]);

        $match->room_id = $data['room_id'];
        $match->room_pass = $data['room_pass'];
        $match->scheduled_at = $data['scheduled_at'] ?? now();
        $match->save();

        try {
            $this->progression->start($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Room details published. Match is now live.');
    }

    public function submitScore(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'kills' => 'required|integer|min:0',
            'placement' => 'required|integer|min:1|max:' . ScoringRule::MAX_PLACEMENT,
            'screenshot' => 'nullable|image|max:2048',
        ]);

        $team = Team::find($data['team_id']);

        // The submitted team MUST be an actual participant of this match.
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $this->authorize('submitScore', $team);

        // Phase 10 — fraud/risk gate for score submission.
        try {
            $this->risk->gate($request->user(), 'score_submission', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Scoring is only possible while the match is ready or live.
        abort_unless(
            $match->acceptsScoreSubmission(),
            403,
            'Score submission is not open for this match.'
        );

        // Prevent duplicate/unauthorized score replacement.
        if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
            abort(403, 'A score for this team has already been submitted.');
        }

        // A Free Fire placement is unique within a match.
        if (Score::where('match_id', $match->id)->where('placement', (int) $data['placement'])->exists()) {
            abort(403, 'Another team in this match has already claimed that placement.');
        }

        $path = null;
        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->store('scores', 'public');
        }

        try {
            // The server computes every point — the client's values are only
            // raw inputs (kills + placement).
            $this->scoring->submitScore(
                $match,
                $team,
                (int) $data['kills'],
                (int) $data['placement'],
                $path
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Phase 10 — deterministic anomaly analysis (observation only; never
        // an accusation and never throws into the request path).
        try {
            $this->antiCheat->analyzeScoreSubmission($match, $team, (int) $data['kills'], (int) $data['placement']);
        } catch (\Throwable $e) {
            // Anomaly detection must never break score submission.
        }

        return back()->with('success', 'Score submitted! Awaiting verification.');
    }

    /**
     * Apply an auditable bonus/penalty to a team's score (privileged).
     */
    public function addAdjustment(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'type' => 'required|in:bonus,penalty',
            'points' => 'required|integer|min:1|max:1000',
            'reason' => 'required|string|max:255',
        ]);

        $team = Team::find($data['team_id']);
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $score = Score::where('match_id', $match->id)->where('team_id', $team->id)->first();
        abort_unless($score !== null, 404, 'No score found for this team in this match.');

        try {
            $this->scoring->addAdjustment($score, $data['type'], (int) $data['points'], $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Score adjustment applied.');
    }

    /**
     * Record a winner, complete the match and advance the bracket.
     * Idempotent: completing again with the same winner is a no-op.
     */
    public function setWinner(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);

        // Winner must be one of the actual participating teams.
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $result = $this->progression->complete($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'This match is already completed with that winner.');
        }

        return back()->with('success', 'Winner confirmed. Bracket advanced.');
    }

    /**
     * Move a completed match into the disputed state (privileged).
     */
    public function dispute(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        try {
            $this->progression->dispute($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Match marked as disputed.');
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner (privileged).
     */
    public function resolve(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $this->progression->resolve($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');
    }
}
```


#### `app/Http/Controllers/DisputeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\FraudRiskService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $service,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * Show the "open a dispute" form.
     */
    public function create(Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('openDispute', $match);

        $user = auth()->user();
        $userTeam = $match->participantTeamFor($user);
        $existing = Dispute::where('match_id', $match->id)
            ->whereIn('status', Dispute::ACTIONABLE_STATUSES)
            ->first();

        $categories = Dispute::CATEGORIES;

        return view('disputes.create', compact('tournament', 'match', 'userTeam', 'existing', 'categories'));
    }

    /**
     * Open a dispute (optionally with a first piece of evidence).
     */
    public function store(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('openDispute', $match);

        $data = $request->validate([
            'category' => 'required|in:' . implode(',', Dispute::CATEGORIES),
            'description' => 'required|string|max:5000',
            'team_id' => 'nullable|integer|exists:teams,id',
            'evidence_type' => 'nullable|in:' . implode(',', DisputeEvidence::TYPES),
            'evidence_description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:' . DisputeEvidence::MAX_KB
                . '|mimetypes:' . $this->allowedMimeTypes(),
        ]);

        $user = $request->user();
        $team = ! empty($data['team_id']) ? Team::find($data['team_id']) : null;

        try {
            $dispute = $this->service->open($match, $team, $user, $data['category'], $data['description']);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if (! empty($data['evidence_type'])) {
            try {
                $this->service->addEvidence(
                    $dispute,
                    $user,
                    $data['evidence_type'],
                    $data['evidence_description'] ?? null,
                    $request->file('evidence_file')
                );
            } catch (DomainException $e) {
                return redirect()
                    ->route('matches.disputes.show', [$tournament, $match, $dispute])
                    ->with('error', 'Dispute opened, but the evidence was not attached: ' . $e->getMessage());
            }
        }

        // Phase 10 — repeated-dispute signal (non-blocking observation).
        $disputeCount = Dispute::where('opened_by', $user->id)->count();
        $threshold = (int) config('antifraud.dispute.repeat_threshold', 3);

        if ($disputeCount >= $threshold) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_DISPUTE_REPEAT, RiskEvent::SEVERITY_LOW, 'dispute', [
                'dispute_count' => $disputeCount,
            ], $tournament);
        }

        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute opened. It will be reviewed by a moderator.');
    }

    /**
     * Show a dispute: status, description, evidence, timeline and (for
     * authorized actors) the relevant action forms.
     */
    public function show(Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('view', $dispute);

        $dispute->load(['opener', 'assignee', 'resolver', 'team', 'resolutionWinner', 'evidence.submitter', 'events.actor']);
        $match->load(['team1', 'team2', 'scores.team', 'scores.adjustments']);

        $user = auth()->user();
        $isStaff = $user !== null && $this->service->isStaffFor($user, $dispute);
        $isOpener = $user !== null && $dispute->opened_by === $user->id;
        $reviewers = collect();

        if ($isStaff) {
            $reviewers = User::whereIn('role', ['admin', 'moderator'])->orderBy('name')->get();
        }

        return view('disputes.show', compact('tournament', 'match', 'dispute', 'isStaff', 'isOpener', 'reviewers'));
    }

    /**
     * Attach evidence to an actionable dispute.
     */
    public function addEvidence(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('addEvidence', $dispute);

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', DisputeEvidence::TYPES),
            'description' => 'nullable|string|max:2000',
            'evidence_file' => 'nullable|file|max:' . DisputeEvidence::MAX_KB
                . '|mimetypes:' . $this->allowedMimeTypes(),
        ]);

        try {
            $this->service->addEvidence(
                $dispute,
                $request->user(),
                $data['type'],
                $data['description'] ?? null,
                $request->file('evidence_file')
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Evidence added.');
    }

    /**
     * Stream a piece of evidence from private storage (authorized only).
     */
    public function evidence(Tournament $tournament, GameMatch $match, Dispute $dispute, DisputeEvidence $evidence)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        abort_unless($evidence->dispute_id === $dispute->id, 404);
        $this->authorize('viewEvidence', [$dispute, $evidence]);

        if ($evidence->path === null || ! Storage::disk('local')->exists($evidence->path)) {
            abort(404);
        }

        return Storage::disk('local')->response($evidence->path);
    }

    /**
     * Cancel a dispute (staff, or the opener while it is still open).
     */
    public function cancel(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('cancel', $dispute);

        try {
            $this->service->cancel($dispute, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute cancelled. The original result stands.');
    }

    /**
     * Move an open dispute to under review (staff).
     */
    public function review(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('review', $dispute);

        try {
            $this->service->markUnderReview($dispute, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute is now under review.');
    }

    /**
     * Assign a reviewer to the dispute (staff).
     */
    public function assign(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('assign', $dispute);

        $data = $request->validate([
            'reviewer_id' => 'required|integer|exists:users,id',
        ]);

        $reviewer = User::findOrFail($data['reviewer_id']);

        try {
            $this->service->assign($dispute, $reviewer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute assigned to ' . $reviewer->name . '.');
    }

    /**
     * Resolve a dispute (staff): confirm/correct the winner, optionally
     * correct score inputs through the scoring engine, and finalize.
     */
    public function resolve(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('resolve', $dispute);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
            'resolution' => 'required|string|max:5000',
            'corrections' => 'nullable|array',
            'corrections.*.team_id' => 'required_with:corrections|integer|exists:teams,id',
            'corrections.*.kills' => 'nullable|integer|min:0',
            'corrections.*.placement' => 'nullable|integer|min:1|max:' . \App\Models\ScoringRule::MAX_PLACEMENT,
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);

        // The confirmed winner must be a participant — never trusted blindly.
        abort_unless($match->hasParticipant($winner), 403, 'The confirmed winner must be a participating team.');

        try {
            $this->service->resolve(
                $dispute,
                $request->user(),
                $winner,
                $data['resolution'],
                $data['corrections'] ?? []
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matches.disputes.show', [$tournament, $match, $dispute])
            ->with('success', 'Dispute resolved and result finalized.');
    }

    /**
     * Reject a dispute (staff): the existing result is upheld.
     */
    public function reject(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        $this->authorize('reject', $dispute);

        $data = $request->validate([
            'resolution' => 'required|string|max:5000',
        ]);

        try {
            $this->service->reject($dispute, $request->user(), $data['resolution']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute rejected. The original result stands.');
    }

    /**
     * Remove a piece of evidence (privileged moderation action).
     */
    public function removeEvidence(Request $request, Tournament $tournament, GameMatch $match, Dispute $dispute, DisputeEvidence $evidence)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        abort_unless($dispute->match_id === $match->id, 404);
        abort_unless($evidence->dispute_id === $dispute->id, 404);
        $this->authorize('removeEvidence', [$dispute, $evidence]);

        $this->service->removeEvidence($evidence, $request->user());

        return back()->with('success', 'Evidence removed.');
    }

    /**
     * The MIME whitelist for evidence uploads (used by request validation).
     */
    protected function allowedMimeTypes(): string
    {
        return implode(',', array_merge(
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_IMAGE],
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_VIDEO],
            DisputeEvidence::ALLOWED_MIME_TYPES[DisputeEvidence::TYPE_DOCUMENT],
        ));
    }
}
```


#### `app/Http/Controllers/PaymentController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\FraudRiskService;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * bKash payment page for a team's entry fee.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        // Reuse the existing pending payment instead of letting the user
        // start a duplicate one.
        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            return redirect()->route('payment.pending', [$tournament, $team, $existing]);
        }

        return view('payment.show', compact('tournament', 'team'));
    }

    /**
     * Simulated bKash "Send Money" verification.
     * The server computes the amount from the tournament's entry fee; client
     * amounts are never accepted.
     */
    public function verify(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'bkash_number' => 'required|string|min:11|max:15',
            'trx_id' => 'required|string|max:40',
        ]);

        // Phase 10 — fraud/risk gate for payment creation.
        try {
            $this->risk->evaluatePayment($request->user(), $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                'bkash',
                $data['trx_id'],
            );
        } catch (DomainException $e) {
            // Duplicate active payment → point at the existing one.
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return redirect()->route('payment.pending', [$tournament, $team, $existing]);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }

    public function pending(Tournament $tournament, Team $team, Payment $payment)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        abort_unless($payment->belongsToTournament($tournament) && $payment->belongsToTeam($team), 404);
        $this->authorize('view', $payment);

        return view('payment.pending', compact('tournament', 'team', 'payment'));
    }
}
```


#### `app/Http/Controllers/AdminController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FraudRiskService;
use App\Services\PaymentService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected WalletService $wallets,
        protected FraudRiskService $risk,
    ) {
    }

    public function dashboard()
    {
        $stats = [
            'tournaments' => Tournament::count(),
            'teams' => Team::count(),
            'verified_payments' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->count(),
            'revenue' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount'),
            'commission' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount') * 0.08,
        ];

        $pendingPayments = Payment::with(['team', 'tournament'])->where('status', 'pending')->latest()->limit(20)->get();
        $moderators = User::where('role', 'moderator')->orderBy('name')->get();

        return view('admin.dashboard', compact('stats', 'pendingPayments', 'moderators'));
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Payment list with status/tournament filters.
     */
    public function payments(Request $request)
    {
        $payments = Payment::query()
            ->with(['team', 'tournament', 'payer', 'refund'])
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $payments->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $payments->where('tournament_id', $tournamentId);
        }

        $payments = $payments->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);
        $statuses = [
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING,
            Payment::STATUS_PAID,
            Payment::STATUS_VERIFIED,
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ];

        return view('admin.payments', compact('payments', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    /**
     * Verify a pending payment (manual bKash verification) and confirm the
     * team — the legacy admin flow, now routed through the PaymentService.
     */
    public function verifyPayment(Payment $payment)
    {
        try {
            $this->payments->verifyManually($payment, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment verified. Team confirmed.');
    }

    /**
     * Fail a pending payment.
     */
    public function failPayment(Request $request, Payment $payment)
    {
        $reason = (string) $request->input('reason', '');

        try {
            $this->payments->markFailed($payment, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Phase 10 — record a failed-payment signal for the payer (additive;
        // never mutates the Phase 08 payment/wallet state).
        $this->risk->recordPaymentFailure($payment);

        return back()->with('success', 'Payment marked as failed.');
    }

    /**
     * Refund a settled payment (full amount), crediting the payer's wallet.
     */
    public function refundPayment(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $refund = $this->payments->refund($payment, auth()->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment refunded (৳' . \App\Support\Money::toDecimal($refund->amount_minor) . ' credited to the payer).');
    }

    // ------------------------------------------------------------------
    // Wallets + ledger
    // ------------------------------------------------------------------

    /**
     * A user's wallet with its ledger history.
     */
    public function wallet(User $user)
    {
        $wallet = $this->wallets->walletFor($user);
        $ledger = $wallet->ledgerEntries()->with('actor')->limit(200)->get();
        $delta = $this->wallets->reconciliationDelta($wallet);

        return view('admin.wallet', compact('user', 'wallet', 'ledger', 'delta'));
    }

    /**
     * Credit a user's wallet (admin manual credit/deposit).
     */
    public function creditWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = \App\Support\Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->credit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Wallet credited.');
    }

    /**
     * Debit a user's wallet (admin manual adjustment).
     */
    public function debitWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = \App\Support\Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->debit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Wallet debited.');
    }

    // ------------------------------------------------------------------
    // Moderation roles (Phase 07)
    // ------------------------------------------------------------------

    /**
     * Promote a user to moderator (admin only — the route sits behind the
     * `admin` middleware, and `role` is never mass-assignable).
     */
    public function makeModerator(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isAdmin()) {
            return back()->with('error', 'Admins are already staff.');
        }

        if ($user->isModerator()) {
            return back()->with('error', $user->name . ' is already a moderator.');
        }

        $user->role = 'moderator';
        $user->save();

        return back()->with('success', $user->name . ' is now a moderator.');
    }

    /**
     * Demote a moderator back to a regular player (admin only).
     */
    public function removeModerator(User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot demote an admin.');
        }

        if (! $user->isModerator()) {
            return back()->with('error', 'This user is not a moderator.');
        }

        $user->role = 'player';
        $user->save();

        return back()->with('success', $user->name . ' is no longer a moderator.');
    }
}
```


#### `app/Http/Controllers/PayoutController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Tournament;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin payout management (Phase 09).
 *
 * List payouts and drive the payout state machine. All routes sit behind the
 * `admin` middleware and call the PayoutPolicy.
 */
class PayoutController extends Controller
{
    public function __construct(
        protected PayoutService $payouts,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payout::class);

        $payouts = Payout::query()
            ->with(['tournament', 'recipient', 'team', 'processedBy'])
            ->orderByDesc('created_at');

        $status = $request->query('status');

        if ($status !== null && $status !== '') {
            $payouts->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');

        if ($tournamentId > 0) {
            $payouts->where('tournament_id', $tournamentId);
        }

        $payouts = $payouts->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        $statuses = [
            Payout::STATUS_PENDING,
            Payout::STATUS_APPROVED,
            Payout::STATUS_PROCESSING,
            Payout::STATUS_COMPLETED,
            Payout::STATUS_FAILED,
            Payout::STATUS_CANCELLED,
        ];

        return view('admin.payouts', compact('payouts', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    public function approve(Payout $payout)
    {
        $this->authorize('approve', $payout);

        try {
            $this->payouts->approve($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout approved.');
    }

    public function process(Payout $payout)
    {
        $this->authorize('process', $payout);

        try {
            $this->payouts->process($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout processed.');
    }

    public function processOverride(Request $request, Payout $payout)
    {
        $this->authorize('process', $payout);

        $reason = (string) $request->input('reason', '');

        try {
            $this->payouts->processWithOverride($payout, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout processed with fraud-review override.');
    }

    public function complete(Request $request, Payout $payout)
    {
        $this->authorize('complete', $payout);

        $reference = (string) $request->input('reference', '');

        try {
            $this->payouts->completeManually($payout, auth()->user(), $reference);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout marked completed.');
    }

    public function fail(Request $request, Payout $payout)
    {
        $this->authorize('fail', $payout);

        $reason = (string) $request->input('reason', '');

        try {
            $this->payouts->markFailed($payout, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout marked failed.');
    }

    public function cancel(Payout $payout)
    {
        $this->authorize('cancel', $payout);

        try {
            $this->payouts->cancel($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout cancelled.');
    }
}
```


#### `app/Http/Controllers/WalletController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Payment;
use App\Services\IdentityVerificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated user's wallet: balance, ledger history, payment history
 * (Phase 08), their own prize-payout history (Phase 09) and identity
 * verification status (Phase 10).
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
        protected IdentityVerificationService $identity,
    ) {
    }

    public function index()
    {
        $user = auth()->user();
        $wallet = $this->wallets->walletFor($user);
        $identity = $this->identity->effectiveStatus($user);

        $ledger = $wallet->ledgerEntries()->with('actor')->limit(100)->get();

        $payments = Payment::query()
            ->where(function ($q) use ($user) {
                $q->where('payer_user_id', $user->id)
                    ->orWhereHas('team', fn ($t) => $t->where('captain_id', $user->id));
            })
            ->with(['tournament', 'team', 'refund'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Only the authenticated user's own payouts are ever shown.
        $payouts = Payout::query()
            ->where('recipient_user_id', $user->id)
            ->with(['tournament', 'team'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('wallet.index', compact('wallet', 'ledger', 'payments', 'payouts', 'identity'));
    }
}
```


#### `app/Http/Controllers/ModerationController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Dispute;
use App\Models\RiskProfile;
use App\Models\Tournament;
use Illuminate\Http\Request;

/**
 * Moderation queue (Phase 07) + security review (Phase 10).
 *
 * Admins and moderators see the global queue; organizers see only their own
 * tournaments' disputes. Players can never access the queue or the security
 * review.
 */
class ModerationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator() || $user->isOrganizer()),
            403,
            'Staff access only.'
        );

        $disputes = Dispute::query()
            ->with(['match.tournament', 'opener', 'assignee', 'team'])
            ->orderByDesc('created_at');

        // Organizers are scoped to their own tournaments.
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $disputes->whereHas('tournament', fn ($q) => $q->where('organizer_id', $user->id));
        }

        $status = $request->query('status');
        if ($status !== null && in_array($status, Dispute::STATUSES, true)) {
            $disputes->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $disputes->where('tournament_id', $tournamentId);
        }

        $disputes = $disputes->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name', 'organizer_id']);
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $tournaments = $tournaments->where('organizer_id', $user->id);
        }

        return view('moderation.index', compact('disputes', 'tournaments', 'status', 'tournamentId'));
    }

    /**
     * Security review (Phase 10) — read-only for admins and moderators:
     * accounts flagged for review plus open anti-cheat incidents. Players
     * and organizers cannot access this page.
     */
    public function security(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator()),
            403,
            'Staff access only.'
        );

        $flaggedUsers = RiskProfile::query()
            ->with('user')
            ->where('manual_review_required', true)
            ->orWhereIn('risk_level', [RiskProfile::LEVEL_HIGH, RiskProfile::LEVEL_CRITICAL])
            ->orderByDesc('risk_score')
            ->limit(50)
            ->get();

        $openIncidents = AntiCheatIncident::query()
            ->with(['tournament', 'team', 'accusedUser', 'reviewer'])
            ->whereIn('status', [AntiCheatIncident::STATUS_FLAGGED, AntiCheatIncident::STATUS_UNDER_REVIEW])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('moderation.security', compact('flaggedUsers', 'openIncidents'));
    }
}
```


#### `app/Services/PayoutService.php`

```php
<?php

namespace App\Services;

use App\Exceptions\PayoutReviewRequiredException;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Payout lifecycle + wallet integration (Phase 09).
 *
 * The single authority for payout state transitions and disbursement.
 * Internal (wallet) payouts credit the recipient's wallet through
 * WalletService inside the same transaction that marks the payout completed,
 * so the payout record and the wallet/ledger can never diverge. External
 * (manual) payouts move to `processing` and are completed by hand — no
 * external success is ever faked.
 *
 * Idempotency: processing an already-completed payout returns the current
 * state; the unique (distribution_id, rank) and idempotency-key constraints
 * are the race-condition backstops.
 */
class PayoutService
{
    public function __construct(
        protected WalletService $wallets,
        protected PayoutGatewayManager $gateways,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * Process an approved payout.
     *
     *  - Internal wallet provider: credit the recipient's wallet (with the
     *    matching ledger entry) and mark the payout completed, atomically.
     *  - Manual provider: move to `processing`; an admin completes it by hand
     *    (completeManually).
     *
     * The recipient's fraud risk is evaluated first (Phase 10): a payout that
     * requires review is HELD (PayoutReviewRequiredException) — it is never
     * silently paid to a flagged recipient, and never auto-confiscated. An
     * authorized admin can process it via processWithOverride().
     */
    public function process(Payout $payout, User $actor): Payout
    {
        $action = $this->risk->evaluatePayout($payout);

        if ($action !== FraudRiskService::ACTION_ALLOW) {
            throw new PayoutReviewRequiredException(
                'This payout requires fraud review before it can be processed (recipient risk action: ' . $action . ').'
            );
        }

        return $this->processInternal($payout, $actor);
    }

    /**
     * Process a payout with an authorized fraud-review override. The override
     * is audited (reason + actor) so the exemption is always explainable.
     */
    public function processWithOverride(Payout $payout, User $actor, string $reason): Payout
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('An override reason is required.');
        }

        $this->recordEvent($payout, $actor, PayoutEvent::EVENT_PROCESSING, $payout->amountMinor(), [
            'override' => true,
            'reason' => $reason,
        ]);

        return $this->processInternal($payout, $actor);
    }

    /**
     * The shared disbursement path (gate already applied).
     */
    protected function processInternal(Payout $payout, User $actor): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if (! $gateway->isInternal()) {
            return $this->advanceToProcessing($payout, $actor);
        }

        if ($payout->recipient_user_id === null) {
            throw new DomainException('This payout has no recipient.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            // Idempotency: a completed payout is simply reported back.
            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $recipient = $fresh->recipient;

            if ($recipient === null) {
                throw new DomainException('This payout has no recipient.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            // The wallet credit and its ledger entry commit atomically with
            // the payout state below — the two can never diverge.
            $wallet = $this->wallets->walletFor($recipient);

            $this->wallets->credit(
                $wallet,
                $fresh->amountMinor(),
                LedgerEntry::TYPE_PAYOUT,
                'Prize payout — ' . ($fresh->tournament?->name ?? 'Tournament') . ' (' . $this->ordinal((int) $fresh->rank) . ' place)',
                $actor,
                'payout',
                $fresh->id,
            );

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Manually complete an external (non-internal) payout, recording the
     * provider reference. Internal wallet payouts complete automatically
     * during process() and can never be completed by hand.
     */
    public function completeManually(Payout $payout, User $actor, ?string $reference = null): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if ($gateway->isInternal()) {
            throw new DomainException('Internal wallet payouts complete automatically during processing.');
        }

        return DB::transaction(function () use ($payout, $actor, $reference) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PROCESSING) {
                throw new DomainException('Only processing payouts can be marked completed.');
            }

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();

            if ($reference !== null && trim($reference) !== '') {
                $fresh->provider_reference = trim($reference);
            }

            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor(), ['reference' => $reference]);

            return $fresh;
        });
    }

    /**
     * Approve a pending payout.
     */
    public function approve(Payout $payout, User $actor): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new DomainException('Only pending payouts can be approved.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_APPROVED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PENDING) {
                throw new DomainException('Only pending payouts can be approved.');
            }

            $fresh->status = Payout::STATUS_APPROVED;
            $fresh->approved_by = $actor->id;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_APPROVED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Mark a payout failed with a reason.
     */
    public function markFailed(Payout $payout, User $actor, string $reason = ''): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
            throw new DomainException('This payout cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor, $reason) {
            $payout->status = Payout::STATUS_FAILED;
            $payout->failure_reason = $reason !== '' ? $reason : null;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_FAILED, $payout->amountMinor(), ['reason' => $reason]);

            return $payout;
        });
    }

    /**
     * Cancel a payout that has not started paying out.
     */
    public function cancel(Payout $payout, User $actor): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true)) {
            throw new DomainException('This payout cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $payout->status = Payout::STATUS_CANCELLED;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_CANCELLED, $payout->amountMinor());

            return $payout;
        });
    }

    /**
     * Move an external payout into processing (no disbursement yet).
     */
    protected function advanceToProcessing(Payout $payout, User $actor): Payout
    {
        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_PROCESSING, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Append a row to the payout audit trail.
     */
    public function recordEvent(Payout $payout, ?User $actor, string $event, int $amountMinor, array $metadata = []): PayoutEvent
    {
        $record = new PayoutEvent();
        $record->payout_id = $payout->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    /**
     * English ordinal for display ("1st", "2nd", "3rd", "4th", …).
     */
    protected function ordinal(int $rank): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($rank % 100) >= 11 && ($rank % 100) <= 13) {
            return $rank . 'th';
        }

        return $rank . $suffixes[$rank % 10];
    }
}
```


#### `app/Services/PrizeDistributionService.php`

```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\GameMatch;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prize distribution workflow (Phase 09).
 *
 * Orchestrates prize-tier configuration, the draft → calculated → approved →
 * processing → completed/failed/cancelled state machine, the immutable prize
 * snapshot and the payout records. Final standings come exclusively from the
 * Phase 06 ScoringService; eligibility is gated on the Phase 05/07 lifecycle
 * and dispute state.
 *
 * Amounts are integer poisha throughout; percentage tiers resolve against the
 * frozen prize pool with integer arithmetic (no floats).
 */
class PrizeDistributionService
{
    public function __construct(
        protected ScoringService $scoring,
        protected PayoutService $payouts,
        protected ReconciliationService $reconciliation,
        protected PayoutGatewayManager $gateways,
    ) {
    }

    /**
     * The configured prize tiers for a tournament, ordered by rank.
     *
     * @return Collection<int, PrizeTier>
     */
    public function tiers(Tournament $tournament): Collection
    {
        return $tournament->prizeTiers()->orderBy('position')->get();
    }

    /**
     * The currently live (non-terminal) distribution, or null.
     */
    public function activeDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()
            ->whereIn('status', PrizeDistribution::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent distribution (any state), or null.
     */
    public function latestDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------------
    // Prize configuration
    // ------------------------------------------------------------------

    /**
     * Replace a tournament's prize tiers with a validated set.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     */
    public function saveTiers(Tournament $tournament, array $rows, User $admin): void
    {
        $this->assertTiersEditable($tournament);

        $pool = $tournament->prizePoolMinor();
        $normalized = $this->normalizeTiers($rows, $pool);

        DB::transaction(function () use ($tournament, $normalized) {
            $tournament->prizeTiers()->delete();

            foreach ($normalized as $row) {
                $tier = new PrizeTier();
                $tier->tournament_id = $tournament->id;
                $tier->position = $row['position'];
                $tier->type = $row['type'];
                $tier->amount_minor = $row['amount_minor'];
                $tier->percentage_bp = $row['percentage_bp'];
                $tier->save();
            }
        });
    }

    /**
     * Validate and normalise prize-tier rows into a deterministic,
     * position-ordered structure. Server-authoritative: rejects negative
     * amounts/percentages, duplicate positions, invalid positions, totals
     * over 100% and allocations that exceed the available prize pool.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     * @return array<int, array{position:int, type:string, amount_minor:?int, percentage_bp:?int}>
     */
    public function normalizeTiers(array $rows, int $pool): array
    {
        $seen = [];
        $normalized = [];
        $fixedSum = 0;
        $basisPointsSum = 0;

        foreach ($rows as $row) {
            $position = (int) ($row['position'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));

            // Blank rows are ignored (fixed-row forms submit empty slots).
            if ($value === '') {
                continue;
            }

            if ($position < 1 || $position > PrizeTier::MAX_POSITION) {
                throw new DomainException('Prize positions must be between 1 and ' . PrizeTier::MAX_POSITION . '.');
            }

            if (isset($seen[$position])) {
                throw new DomainException('Duplicate prize position: ' . $position . '.');
            }

            $seen[$position] = true;

            if (! in_array($type, PrizeTier::TYPES, true)) {
                throw new DomainException('Prize type must be fixed or percentage.');
            }

            if ($type === PrizeTier::TYPE_FIXED) {
                $amountMinor = Money::toMinor($value);

                if ($amountMinor <= 0) {
                    throw new DomainException('Fixed prize amounts must be positive.');
                }

                $fixedSum += $amountMinor;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => $amountMinor,
                    'percentage_bp' => null,
                ];
            } else {
                $basisPoints = Money::toBasisPoints($value);

                if ($basisPoints <= 0 || $basisPoints > 10000) {
                    throw new DomainException('Prize percentages must be between 0 and 100.');
                }

                $basisPointsSum += $basisPoints;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => null,
                    'percentage_bp' => $basisPoints,
                ];
            }
        }

        if ($normalized === []) {
            throw new DomainException('At least one prize tier is required.');
        }

        usort($normalized, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        if ($basisPointsSum > 10000) {
            throw new DomainException('Prize percentages cannot total more than 100%.');
        }

        $total = $fixedSum;

        foreach ($normalized as $row) {
            if ($row['type'] === PrizeTier::TYPE_PERCENTAGE) {
                $total += intdiv($pool * $row['percentage_bp'], 10000);
            }
        }

        if ($total > $pool) {
            throw new DomainException(
                'The configured prize allocation exceeds the available prize pool (৳' . Money::toDecimal($pool) . ').'
            );
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Distribution workflow
    // ------------------------------------------------------------------

    /**
     * Calculate the prize distribution: snapshot the tiers and the final
     * standings into immutable rows and move to `calculated`.
     *
     * Idempotent: re-running on an already-calculated distribution returns it
     * unchanged. A draft distribution is recomputed in place.
     */
    public function calculate(Tournament $tournament, User $admin): PrizeDistribution
    {
        $this->assertEligible($tournament);

        return DB::transaction(function () use ($tournament, $admin) {
            // Serialize concurrent calculations against the tournament row.
            Tournament::query()->where('id', $tournament->id)->lockForUpdate()->first();

            $active = $this->activeDistribution($tournament);

            if ($active === null) {
                if ($tournament->prizeDistributions()->where('status', PrizeDistribution::STATUS_COMPLETED)->exists()) {
                    throw new DomainException('This tournament has already been settled.');
                }

                $active = new PrizeDistribution();
                $active->tournament_id = $tournament->id;
                $active->status = PrizeDistribution::STATUS_DRAFT;
                $active->created_by = $admin->id;
                $active->idempotency_key = (string) Str::uuid();
                $active->save();
            } elseif ($active->status !== PrizeDistribution::STATUS_DRAFT) {
                // Already calculated/approved/processing — idempotent no-op.
                return $active;
            }

            $tiers = $this->tiers($tournament);

            if ($tiers->isEmpty()) {
                throw new DomainException('No prize tiers configured. Configure prizes first.');
            }

            $standings = $this->scoring->standings($tournament);

            if ($standings->isEmpty()) {
                throw new DomainException('Final standings are not available for this tournament.');
            }

            $pool = $tournament->prizePoolMinor();
            $tierByPosition = $tiers->keyBy('position');
            $maxPosition = (int) $tiers->max('position');

            // Recalculate a draft in place (snapshot rows are replaced).
            $active->snapshotItems()->delete();

            $total = 0;

            foreach ($standings as $row) {
                $rank = (int) $row->rank;

                if ($rank > $maxPosition) {
                    break;
                }

                $tier = $tierByPosition->get($rank);

                if ($tier === null) {
                    continue;
                }

                $team = $row->team;

                if (! $team instanceof Team || $team->captain_id === null) {
                    throw new DomainException('Ranked team #' . $rank . ' has no captain to receive the prize.');
                }

                $amountMinor = $this->resolveAmount($tier, $pool);

                $item = new PrizeSnapshotItem();
                $item->distribution_id = $active->id;
                $item->tournament_id = $tournament->id;
                $item->position = $rank;
                $item->team_id = $team->id;
                $item->type = $tier->type;
                $item->amount_minor = $amountMinor;
                $item->save();

                $total += $amountMinor;
            }

            $active->pool_minor = $pool;
            $active->total_allocated_minor = $total;
            $active->status = PrizeDistribution::STATUS_CALCULATED;
            $active->save();

            return $active;
        });
    }

    /**
     * Approve a calculated distribution, creating the payout records from the
     * snapshot. Idempotent on an already-approved distribution.
     */
    public function approve(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists. Calculate it first.');
        }

        if (in_array($distribution->status, [
            PrizeDistribution::STATUS_APPROVED,
            PrizeDistribution::STATUS_PROCESSING,
            PrizeDistribution::STATUS_COMPLETED,
        ], true)) {
            return $distribution;
        }

        if ($distribution->status !== PrizeDistribution::STATUS_CALCULATED) {
            throw new DomainException('Only a calculated distribution can be approved.');
        }

        return DB::transaction(function () use ($distribution, $admin) {
            $items = $distribution->snapshotItems()->with('team')->orderBy('position')->get();

            if ($items->isEmpty()) {
                throw new DomainException('No prizes were allocated; nothing to approve.');
            }

            $provider = $this->gateways->defaultProvider();

            foreach ($items as $item) {
                if (Payout::where('distribution_id', $distribution->id)->where('rank', $item->position)->exists()) {
                    continue;
                }

                $payout = new Payout();
                $payout->distribution_id = $distribution->id;
                $payout->tournament_id = $distribution->tournament_id;
                $payout->recipient_team_id = $item->team_id;
                $payout->recipient_user_id = $item->team?->captain_id;
                $payout->rank = $item->position;
                $payout->amount_minor = $item->amount_minor;
                $payout->currency = 'BDT';
                $payout->status = Payout::STATUS_APPROVED;
                $payout->payout_method = $provider === 'wallet' ? Payout::METHOD_WALLET : Payout::METHOD_MANUAL;
                $payout->provider = $provider;
                $payout->idempotency_key = (string) Str::uuid();
                $payout->approved_by = $admin->id;
                $payout->save();

                $this->payouts->recordEvent($payout, $admin, \App\Models\PayoutEvent::EVENT_APPROVED, $payout->amountMinor());
            }

            $distribution->status = PrizeDistribution::STATUS_APPROVED;
            $distribution->approved_by = $admin->id;
            $distribution->approved_at = now();
            $distribution->save();

            return $distribution;
        });
    }

    /**
     * Process an approved distribution: disburse every payout, then complete
     * the distribution and freeze the financial settlement.
     *
     * Idempotent: a completed distribution is returned unchanged; a
     * `processing` distribution resumes its remaining payouts. If a payout
     * fails, the payout and the distribution are marked failed.
     */
    public function process(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            // Idempotency: an already-completed distribution is returned.
            $latest = $this->latestDistribution($tournament);

            if ($latest !== null && $latest->status === PrizeDistribution::STATUS_COMPLETED) {
                return $latest;
            }

            throw new DomainException('No prize distribution exists.');
        }

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {
            return $distribution;
        }

        if ($distribution->status === PrizeDistribution::STATUS_PROCESSING) {
            // Resume — fall through to process remaining payouts.
        } elseif ($distribution->status === PrizeDistribution::STATUS_APPROVED) {
            $distribution->status = PrizeDistribution::STATUS_PROCESSING;
            $distribution->save();
        } else {
            throw new DomainException('Only an approved distribution can be processed.');
        }

        $remaining = $distribution->payouts()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
            ->orderBy('rank')
            ->get();

        foreach ($remaining as $payout) {
            try {
                $this->payouts->process($payout, $admin);
            } catch (\App\Exceptions\PayoutReviewRequiredException $e) {
                // Phase 10 — a payout held for fraud review stops the run
                // WITHOUT failing it. The distribution stays `processing`
                // until an admin overrides or clears the hold.
                break;
            } catch (DomainException $e) {
                $this->payouts->markFailed($payout, $admin, $e->getMessage());

                DB::transaction(function () use ($distribution, $e) {
                    $distribution->status = PrizeDistribution::STATUS_FAILED;
                    $distribution->failure_reason = 'A payout failed: ' . $e->getMessage();
                    $distribution->save();
                });

                return $distribution;
            }
        }

        // Internal (wallet) payouts complete during processing; manual payouts
        // stay in `processing` until an admin marks them completed by hand.
        $unfinished = $distribution->payouts()
            ->where('status', '!=', Payout::STATUS_COMPLETED)
            ->exists();

        if ($unfinished) {
            return $distribution;
        }

        DB::transaction(function () use ($distribution, $tournament, $admin) {
            $distribution->status = PrizeDistribution::STATUS_COMPLETED;
            $distribution->completed_at = now();
            $distribution->save();

            $this->reconciliation->finalize($tournament, $admin, $distribution);
        });

        return $distribution;
    }

    /**
     * Cancel a distribution that has not started paying out, cancelling its
     * not-yet-terminal payouts.
     */
    public function cancel(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists.');
        }

        if (! in_array($distribution->status, [
            PrizeDistribution::STATUS_DRAFT,
            PrizeDistribution::STATUS_CALCULATED,
            PrizeDistribution::STATUS_APPROVED,
        ], true)) {
            throw new DomainException('This distribution cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($distribution) {
            $distribution->payouts()
                ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
                ->update(['status' => Payout::STATUS_CANCELLED]);

            $distribution->status = PrizeDistribution::STATUS_CANCELLED;
            $distribution->save();

            return $distribution;
        });
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /**
     * Assert that a tournament is eligible for prize distribution:
     * finished, not cancelled, all matches resolved (no live/pending/disputed
     * matches) and no actionable disputes. Standings availability is checked
     * in calculate().
     */
    public function assertEligible(Tournament $tournament): void
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED) {
            throw new DomainException('Prize distribution requires a finished tournament.');
        }

        $unresolved = $tournament->matches()
            ->whereNotIn('status', [
                GameMatch::STATUS_COMPLETED,
                GameMatch::STATUS_BYE,
                GameMatch::STATUS_CANCELLED,
            ])
            ->exists();

        if ($unresolved) {
            throw new DomainException('All matches must be completed before prize distribution.');
        }

        if ($tournament->disputes()->whereIn('status', Dispute::ACTIONABLE_STATUSES)->exists()) {
            throw new DomainException('Unresolved disputes must be resolved before prize distribution.');
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Resolve a tier into an integer minor-unit amount against the pool.
     */
    protected function resolveAmount(PrizeTier $tier, int $pool): int
    {
        if ($tier->type === PrizeTier::TYPE_FIXED) {
            return (int) $tier->amount_minor;
        }

        return intdiv($pool * (int) $tier->percentage_bp, 10000);
    }

    /**
     * Prize tiers can only be edited while there is no active distribution or
     * the active distribution is still a draft. Once calculated, the snapshot
     * is authoritative and the tiers are locked.
     */
    protected function assertTiersEditable(Tournament $tournament): void
    {
        $active = $this->activeDistribution($tournament);

        if ($active !== null && $active->status !== PrizeDistribution::STATUS_DRAFT) {
            throw new DomainException('Prize tiers are locked once the distribution is calculated. Cancel it to reconfigure.');
        }
    }
}
```


#### `routes/web.php`

```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Moderation security review (admin + moderator only, Phase 10)
    Route::get('/moderation/security', [ModerationController::class, 'security'])->name('moderation.security');

    // Security — anti-cheat incidents + identity request (policy-guarded)
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents.index');
    Route::post('/security/incidents', [SecurityController::class, 'openIncident'])->name('security.incidents.open');
    Route::post('/security/incidents/{incident}/review', [SecurityController::class, 'reviewIncident'])->name('security.incidents.review');
    Route::post('/security/incidents/{incident}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
    Route::post('/security/identity/request', [SecurityController::class, 'requestVerification'])->name('security.identity.request');

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Payments (Phase 08)
        Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
        Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

        // Wallets + ledger (Phase 08)
        Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
        Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
        Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

        // Prize distribution + payouts + settlement (Phase 09)
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'show'])->name('settlements.show');
        Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
        Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
        Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
        Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
        Route::post('/payouts/{payout}/process-override', [PayoutController::class, 'processOverride'])->name('payouts.processOverride');
        Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
        Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

        // Anti-fraud security administration (Phase 10)
        Route::get('/security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
        Route::get('/security/users', [SecurityController::class, 'users'])->name('security.users');
        Route::get('/security/users/{user}', [SecurityController::class, 'user'])->name('security.user');
        Route::get('/security/events', [SecurityController::class, 'events'])->name('security.events');
        Route::post('/security/users/{user}/restrict', [SecurityController::class, 'restrict'])->name('security.restrict');
        Route::post('/security/restrictions/{restriction}/lift', [SecurityController::class, 'liftRestriction'])->name('security.lift');
        Route::post('/security/users/{user}/verify', [SecurityController::class, 'verifyIdentity'])->name('security.verify');
        Route::post('/security/users/{user}/reject-identity', [SecurityController::class, 'rejectIdentity'])->name('security.reject');

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');
    });
});
```


#### `resources/views/layouts/app.blade.php`

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FF Arena') — Bangladesh Free Fire Tournaments</title>
    <style>
        :root {
            --bg: #0b0e1a;
            --panel: #141a2e;
            --panel2: #1b2340;
            --line: #28335a;
            --txt: #e8ecff;
            --muted: #8a93b8;
            --cyan: #22d3ee;
            --purple: #a855f7;
            --green: #34d399;
            --red: #f87171;
            --amber: #fbbf24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--txt);
            min-height: 100vh;
            background-image: radial-gradient(1200px 600px at 80% -10%, rgba(168,85,247,.14), transparent),
                              radial-gradient(900px 500px at -10% 110%, rgba(34,211,238,.12), transparent);
        }
        a { color: var(--cyan); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        nav {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 0; border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: .5px; }
        .brand span { color: var(--cyan); }
        .nav-links { display: flex; gap: 18px; align-items: center; margin-left: auto; flex-wrap: wrap; }
        .btn {
            display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line);
            background: var(--panel2); color: var(--txt); font-weight: 600; font-size: 14px; cursor: pointer;
            transition: .15s;
        }
        .btn:hover { border-color: var(--cyan); text-decoration: none; }
        .btn-primary { background: linear-gradient(90deg, #7c3aed, #2563eb); border: none; color: #fff; }
        .btn-primary:hover { filter: brightness(1.12); }
        .btn-cyan { background: rgba(34,211,238,.12); border: 1px solid var(--cyan); color: var(--cyan); }
        .btn-green { background: rgba(52,211,153,.12); border: 1px solid var(--green); color: var(--green); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 14px;
            padding: 20px; margin-bottom: 18px;
        }
        .grid { display: grid; gap: 18px; }
        .cols-3 { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        h1 { font-size: 26px; margin-bottom: 8px; }
        h2 { font-size: 20px; margin-bottom: 12px; }
        h3 { font-size: 16px; margin-bottom: 6px; }
        .muted { color: var(--muted); }
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
        }
        .pill.open { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.live { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.closed, .pill.finished, .pill.disputed { background: rgba(248,113,113,.15); color: var(--red); }
        .pill.draft { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.pending { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.ready { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.confirmed, .pill.verified, .pill.checked { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.waitlisted { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed, .pill.no_show, .pill.bye { background: rgba(148,163,184,.15); color: var(--muted); }
        form label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
        input, select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line);
            background: #0d1226; color: var(--txt); font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--cyan); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .flash { padding: 12px 16px; border-radius: 10px; margin: 16px 0; font-weight: 600; }
        .flash.success { background: rgba(52,211,153,.15); color: var(--green); border: 1px solid rgba(52,211,153,.4); }
        .flash.error { background: rgba(248,113,113,.15); color: var(--red); border: 1px solid rgba(248,113,113,.4); }
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .stat .num { font-size: 26px; font-weight: 800; color: var(--cyan); }
        .bracket-col { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; overflow-x: auto; padding-bottom: 10px; }
        .bracket-round { display: flex; flex-direction: column; gap: 14px; min-width: 190px; }
        .bracket-match { background: var(--panel2); border: 1px solid var(--line); border-radius: 10px; padding: 8px; }
        .bracket-team { padding: 7px 10px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
        .bracket-team.win { background: rgba(52,211,153,.12); color: var(--green); font-weight: 700; }
        .bracket-team.bye { color: var(--muted); }
        .divider { height: 1px; background: var(--line); margin: 4px 0; }
        footer { border-top: 1px solid var(--line); margin-top: 50px; padding: 22px 0; color: var(--muted); font-size: 13px; }
        .tag { color: var(--purple); font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <nav>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                <a href="{{ route('wallet.index') }}" class="btn btn-sm">Wallet</a>
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                    <a href="{{ route('moderation.index') }}" class="btn btn-sm">Moderation</a>
                @endif
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator())
                    <a href="{{ route('moderation.security') }}" class="btn btn-sm">Security</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm">Admin</a>
                @endif
                <span class="muted">{{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-sm">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-sm">Login</a>
                <a href="{{ route('register') }}" class="btn btn-sm btn-primary">Register</a>
            @endauth
        </div>
    </nav>

    @if(session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash error">
            <ul style="list-style:none;padding:0;margin:0">
                @foreach($errors->all() as $e) <li>• {{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @yield('content')

    <footer>
        <div class="container" style="padding:0">
            <strong class="tag">FF Arena</strong> — Bangladesh's Free Fire tournament platform.
            Legit. Smart. Profitable. No hacks, ever. 🤝
        </div>
    </footer>
</div>
</body>
</html>
```


#### `resources/views/wallet/index.blade.php`

```php
@extends('layouts.app')
@section('title', 'My Wallet — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">👛 My Wallet</h1>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color:var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Currency</div>
            <div class="num">{{ $wallet->currency }}</div>
        </div>
    </div>

    <div class="card">
        <h3>🪪 Identity Verification</h3>
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
            <div>
                <span class="pill {{ $identity->statusPill() }}">{{ $identity->statusLabel() }}</span>
                @if($identity->expires_at && $identity->status === 'verified')
                    <span class="muted" style="font-size:12px"> · expires {{ $identity->expires_at->format('d M Y') }}</span>
                @endif
                @if($identity->notes)
                    <div class="muted" style="font-size:12px; margin-top:4px">{{ $identity->notes }}</div>
                @endif
            </div>
            @if(in_array($identity->status, ['unverified', 'rejected', 'expired'], true))
                <form method="POST" action="{{ route('security.identity.request') }}">
                    @csrf
                    <button class="btn btn-sm btn-cyan">Request Verification</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🧾 Wallet Transactions</h3>
            @if($ledger->isEmpty())
                <p class="muted">No wallet transactions yet.</p>
            @else
                <table>
                    <tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr>
                    @foreach($ledger as $entry)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ $entry->created_at->format('d M, h:i A') }}</td>
                            <td><span class="pill {{ $entry->isCredit() ? 'confirmed' : 'finished' }}">{{ strtoupper($entry->type) }}</span></td>
                            <td style="{{ $entry->isCredit() ? 'color:var(--green)' : 'color:var(--red)' }}">
                                {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                            </td>
                            <td class="muted" style="font-size:13px">{{ $entry->description }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>💳 Payment History</h3>
            @if($payments->isEmpty())
                <p class="muted">No payments yet.</p>
            @else
                <table>
                    <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>Status</th></tr>
                    @foreach($payments as $payment)
                        <tr>
                            <td>{{ $payment->tournament?->name ?? '—' }}</td>
                            <td>{{ $payment->team?->name ?? '—' }}</td>
                            <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                            <td>
                                <span class="pill {{ $payment->statusPill() }}">{{ strtoupper($payment->status) }}</span>
                                @if($payment->refund)
                                    <span class="muted" style="font-size:12px">(refunded)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>🏆 Prize Payouts</h3>
            @if($payouts->isEmpty())
                <p class="muted">No prize payouts yet.</p>
            @else
                <table>
                    <tr><th>Tournament</th><th>Rank</th><th>Amount</th><th>Status</th><th>Paid</th></tr>
                    @foreach($payouts as $payout)
                        <tr>
                            <td>{{ $payout->tournament?->name ?? '—' }}</td>
                            <td>#{{ $payout->rank }}</td>
                            <td style="color:var(--green)">+৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                            <td><span class="pill {{ $payout->statusPill() }}">{{ $payout->statusLabel() }}</span></td>
                            <td class="muted" style="font-size:12px">{{ $payout->processed_at?->format('d M, h:i A') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
@endsection
```


#### `resources/views/admin/dashboard.blade.php`

```php
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Admin Dashboard</h1>

    <div class="card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <span class="muted">Financials:</span>
        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">Payments</a>
        <a href="{{ route('admin.settlements.index') }}" class="btn btn-sm btn-cyan">Settlements</a>
        <a href="{{ route('admin.payouts.index') }}" class="btn btn-sm">Payouts</a>
        <span class="muted">Security:</span>
        <a href="{{ route('admin.security.dashboard') }}" class="btn btn-sm">Security</a>
    </div>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color:var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}" style="display:flex; gap:10px; align-items:end">
            @csrf
            <div style="flex:1; max-width:320px">
                <label>Promote a user to moderator (by email)</label>
                <input type="email" name="email" placeholder="user@example.com" required>
            </div>
            <button class="btn btn-cyan btn-sm">Promote</button>
        </form>
        @if($moderators->isEmpty())
            <p class="muted" style="margin-top:12px">No moderators yet.</p>
        @else
            <table style="margin-top:12px">
                <tr><th>Name</th><th>Email</th><th></th></tr>
                @foreach($moderators as $moderator)
                    <tr>
                        <td>{{ $moderator->name }}</td>
                        <td class="muted">{{ $moderator->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Demote</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card" style="margin-top:18px">
        <h3>💸 Pending Payments</h3>
        @if($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <table>
                <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>TrxID</th><th>Action</th></tr>
                @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->tournament->name }}</td>
                        <td>{{ $p->team->name }}</td>
                        <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                        <td>{{ $p->trx_id }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.payments.verify', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-green btn-sm">Verify</button>
                            </form>
                            <form method="POST" action="{{ route('admin.payments.fail', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-sm">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:13px; margin-top:12px">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </div>
@endsection
```

