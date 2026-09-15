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
