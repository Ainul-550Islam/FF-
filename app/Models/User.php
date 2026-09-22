<?php

namespace App\Models;

use App\Models\AccountLink;
use App\Models\AntiCheatIncident;
use App\Models\IpLink;
use App\Models\OtpChallenge;
use App\Models\PaymentMethod;
use App\Models\RiskEvent;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'display_name',
        'email',
        'password',
        'is_admin',
        'is_staff',
        'is_active',
        'phone',
        'phone_verified_at',
        'email_verified_at',
        'avatar_path',
        'bio',
        'date_of_birth',
        'gender',
        'country',
        'timezone',
        'locale',
        'last_seen_at',
        'username_changed_at',
        'is_banned',
        'banned_at',
        'ban_reason',
        'account_status',
        'deactivated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'is_staff' => 'boolean',
        'is_active' => 'boolean',
        'is_banned' => 'boolean',
        'date_of_birth' => 'date',
        'last_seen_at' => 'datetime',
        'username_changed_at' => 'datetime',
        'banned_at' => 'datetime',
    ];

    protected $appends = [
        'avatar_url',
        'initials',
        'display_name_or_name',
    ];

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Payouts where this user is the recipient.
     *
     * The Phase 09 schema keys recipients by `recipient_user_id`; the legacy
     * financial-architecture column is `user_id`, so recipients are matched
     * through both.
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_user_id');
    }

    /**
     * Payouts addressed to the legacy `user_id` column.
     */
    public function payoutsAsUser()
    {
        return $this->hasMany(Payout::class, 'user_id');
    }

    /**
     * Every payout this user receives, across both column generations.
     */
    public function allPayouts()
    {
        return Payout::query()
            ->where(function ($query) {
                $query->where('recipient_user_id', $this->id)
                    ->orWhere('user_id', $this->id);
            });
    }

    public function personalAccessTokens()
    {
        return $this->morphMany(\Laravel\Sanctum\PersonalAccessToken::class, 'tokenable');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function identities()
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function deviceLinks()
    {
        return $this->hasMany(DeviceLink::class);
    }

    public function mobileDevices()
    {
        return $this->hasMany(MobileDevice::class);
    }

    /**
     * Teams this user captains (teams are keyed to their captain).
     */
    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }

    /**
     * Authentication history for this account (login/logout/link events).
     */
    public function loginEvents()
    {
        return $this->hasMany(LoginEvent::class)->orderByDesc('created_at');
    }

    /**
     * Fraud/anti-cheat restrictions placed on this account (Phase 10).
     */
    public function restrictions()
    {
        return $this->hasMany(Restriction::class);
    }

    /**
     * Explicit restrictions by their Phase 10 status column.
     */
    public function activeRestrictions()
    {
        return $this->restrictions()->where('status', 'active');
    }

    /**
     * Tournaments this user organizes.
     */
    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    /**
     * Risk events attributable to this account (append-only).
     */
    public function riskEvents()
    {
        return $this->hasMany(RiskEvent::class);
    }

    /**
     * Hashed IP observations for this account (anti-fraud, never raw IPs).
     */
    public function ipLinks()
    {
        return $this->hasMany(IpLink::class);
    }

    /**
     * Anti-cheat incidents where this user is the accused or reporter.
     */
    public function antiCheatIncidents()
    {
        return $this->hasMany(AntiCheatIncident::class, 'accused_user_id');
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
     * Phone OTP challenges issued for this account.
     */
    public function otpChallenges()
    {
        return $this->hasMany(OtpChallenge::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function riskProfile()
    {
        return $this->hasOne(RiskProfile::class);
    }

    public function identityVerification()
    {
        return $this->hasOne(IdentityVerification::class);
    }

    public function userIdentities()
    {
        return $this->hasMany(UserIdentity::class);
    }

    // Profile / Avatar helpers
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar_path) {
            // Private disk served via controller for security, fallback to storage url
            if (Storage::disk('local')->exists($this->avatar_path)) {
                return route('avatar.show', ['user' => $this->id], false);
            }
            // Public disk fallback
            if (Storage::disk('public')->exists($this->avatar_path)) {
                return Storage::disk('public')->url($this->avatar_path);
            }
        }
        // Gravatar fallback or initial avatar
        $hash = md5(strtolower(trim($this->email ?? $this->id)));
        return "https://www.gravatar.com/avatar/{$hash}?d=identicon&s=200";
    }

    public function getInitialsAttribute(): string
    {
        $name = $this->display_name ?: $this->name ?: $this->email;
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    public function getDisplayNameOrNameAttribute(): string
    {
        return $this->display_name ?: $this->name ?: Str::before($this->email, '@');
    }

    public function hasAvatar(): bool
    {
        return !empty($this->avatar_path) && (
            Storage::disk('local')->exists($this->avatar_path) ||
            Storage::disk('public')->exists($this->avatar_path)
        );
    }

    public function isAdmin(): bool
    {
        // Union of both generations: the Phase-04 is_admin flag and the
        // Phase-14 role column ('admin').
        return (bool) $this->is_admin || $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        // Platform staff: admin/moderator flag or admin/moderator role.
        return (bool) ($this->is_staff || $this->is_admin)
            || in_array($this->role, ['admin', 'moderator'], true);
    }

    public function isModerator(): bool
    {
        // Platform staff are admins and users carrying the moderator role
        // (see DisputeService staff query: role in [admin, moderator]).
        return $this->is_admin || in_array($this->role, ['admin', 'moderator'], true);
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Union of both account generations: the legacy `is_active` / `is_banned`
     * flags and the Phase 14 `account_status` lifecycle column. A `null`
     * status means "never set" and is treated as active.
     */
    public function isActive(): bool
    {
        return (bool) ($this->is_active ?? true)
            && ! $this->is_banned
            && ! $this->isDeactivated()
            && ! $this->isDeleted();
    }

    public function isBanned(): bool
    {
        return (bool) $this->is_banned;
    }

    /**
     * Deactivated (self-service or admin) or awaiting deletion.
     */
    public function isDeactivated(): bool
    {
        return in_array($this->account_status, ['deactivated', 'deletion_pending', 'suspended'], true);
    }

    /**
     * A deleted account is an anonymizing tombstone: authentication, listing
     * and every mutation are refused.
     */
    public function isDeleted(): bool
    {
        return $this->account_status === 'deleted';
    }

    /**
     * Human-readable reason an account cannot authenticate, or null when it
     * can. Used by the API middleware/controllers for an honest error code.
     */
    public function inactiveReason(): ?string
    {
        if ($this->isBanned()) {
            return 'banned';
        }

        if ($this->isDeactivated()) {
            return 'deactivated';
        }

        if ($this->isDeleted()) {
            return 'deleted';
        }

        if (! (bool) ($this->is_active ?? true)) {
            return 'inactive';
        }

        return null;
    }

    public function canChangeUsername(): bool
    {
        if (!$this->username_changed_at) {
            return true;
        }
        return $this->username_changed_at->diffInDays(now()) >= 30;
    }

    public function daysUntilUsernameChange(): int
    {
        if (!$this->username_changed_at) {
            return 0;
        }
        $next = $this->username_changed_at->addDays(30);
        if ($next->isPast()) {
            return 0;
        }
        return (int) now()->diffInDays($next);
    }

    public function getPrimaryWallet(): ?Wallet
    {
        return $this->wallets()->where('currency', 'BDT')->first()
            ?? $this->wallets()->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where(function ($q) {
            $q->whereNull('is_banned')->orWhere('is_banned', false);
        });
    }

    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true);
    }

    public function scopeStaff($query)
    {
        return $query->where(function ($q) {
            $q->where('is_staff', true)->orWhere('is_admin', true);
        });
    }

    public function updateLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function routeNotificationForMail()
    {
        return $this->email;
    }
}
