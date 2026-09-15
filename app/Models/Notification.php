<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A personal, in-app notification (Phase 11).
 *
 * Notifications are server-generated only: recipients, content and links are
 * assigned by NotificationService, never by a client. All fields are excluded
 * from mass assignment. `read_at` records the in-app read state; email is a
 * separate, best-effort delivery on top of this row.
 */
class Notification extends Model
{
    use HasFactory;

    public const TYPE_PAYMENT_VERIFIED = 'payment.verified';
    public const TYPE_PAYMENT_FAILED = 'payment.failed';
    public const TYPE_PAYMENT_REFUNDED = 'payment.refunded';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_RESOLVED = 'dispute.resolved';
    public const TYPE_PAYOUT_PROCESSED = 'payout.processed';
    public const TYPE_PAYOUT_FAILED = 'payout.failed';
    public const TYPE_SETTLEMENT_COMPLETED = 'settlement.completed';
    public const TYPE_RESTRICTION_APPLIED = 'restriction.applied';
    public const TYPE_RESTRICTION_LIFTED = 'restriction.lifted';
    public const TYPE_IDENTITY_VERIFIED = 'identity.verified';
    public const TYPE_IDENTITY_REJECTED = 'identity.rejected';
    public const TYPE_ANTI_CHEAT_RESOLVED = 'anti_cheat.resolved';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_SUPPORT_CREATED = 'support.created';
    public const TYPE_SUPPORT_REPLY = 'support.reply';
    public const TYPE_SUPPORT_ASSIGNED = 'support.assigned';
    public const TYPE_SUPPORT_RESOLVED = 'support.resolved';
    public const TYPE_SUPPORT_REOPENED = 'support.reopened';
    public const TYPE_SUPPORT_STATUS = 'support.status';
    public const TYPE_SYSTEM = 'system';

    // Phase 14 — account/auth/security notifications.
    public const TYPE_WELCOME = 'auth.welcome';
    public const TYPE_EMAIL_VERIFY = 'auth.email_verify';
    public const TYPE_PASSWORD_CHANGED = 'auth.password_changed';
    public const TYPE_PASSWORD_RESET = 'auth.password_reset';
    public const TYPE_GOOGLE_LINKED = 'auth.google_linked';
    public const TYPE_GOOGLE_UNLINKED = 'auth.google_unlinked';
    public const TYPE_PHONE_LINKED = 'auth.phone_linked';
    public const TYPE_PHONE_CHANGED = 'auth.phone_changed';
    public const TYPE_SUSPICIOUS_LOGIN = 'auth.suspicious_login';
    public const TYPE_SESSION_REVOKED = 'auth.session_revoked';
    public const TYPE_ACCOUNT_DEACTIVATED = 'auth.account_deactivated';
    public const TYPE_PAYMENT_INITIATED = 'payment.initiated';
    public const TYPE_PAYMENT_PROVIDER_ISSUE = 'payment.provider_issue';

    protected $fillable = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Human label for the notification type (display only).
     */
    public function typeLabel(): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $this->type));
    }
}
