<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only security/login event (Phase 14).
 *
 * Records the closed vocabulary of authentication-relevant events with only
 * pseudonymous context: HMAC'd IP and device hashes and a derived device
 * label. Raw IPs, raw fingerprints and credentials are never stored, and
 * events are never edited or deleted.
 */
class LoginEvent extends Model
{
    use HasFactory;

    public const EVENT_LOGIN_PASSWORD = 'login.password';
    public const EVENT_LOGIN_GOOGLE = 'login.google';
    public const EVENT_LOGIN_PHONE = 'login.phone';
    public const EVENT_LOGIN_FAILED = 'login.failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_PASSWORD_RESET = 'password.reset';
    public const EVENT_PASSWORD_CHANGED = 'password.changed';
    public const EVENT_EMAIL_VERIFIED = 'email.verified';
    public const EVENT_PHONE_VERIFIED = 'phone.verified';
    public const EVENT_ACCOUNT_LINKED = 'account.linked';
    public const EVENT_ACCOUNT_UNLINKED = 'account.unlinked';
    public const EVENT_SESSION_REVOKED = 'session.revoked';
    public const EVENT_SESSIONS_REVOKED = 'sessions.revoked';
    public const EVENT_ACCOUNT_DEACTIVATED = 'account.deactivated';
    public const EVENT_ACCOUNT_REACTIVATED = 'account.reactivated';
    public const EVENT_DELETION_REQUESTED = 'account.deletion_requested';

    public const EVENTS = [
        self::EVENT_LOGIN_PASSWORD,
        self::EVENT_LOGIN_GOOGLE,
        self::EVENT_LOGIN_PHONE,
        self::EVENT_LOGIN_FAILED,
        self::EVENT_LOGOUT,
        self::EVENT_PASSWORD_RESET,
        self::EVENT_PASSWORD_CHANGED,
        self::EVENT_EMAIL_VERIFIED,
        self::EVENT_PHONE_VERIFIED,
        self::EVENT_ACCOUNT_LINKED,
        self::EVENT_ACCOUNT_UNLINKED,
        self::EVENT_SESSION_REVOKED,
        self::EVENT_SESSIONS_REVOKED,
        self::EVENT_ACCOUNT_DEACTIVATED,
        self::EVENT_ACCOUNT_REACTIVATED,
        self::EVENT_DELETION_REQUESTED,
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
