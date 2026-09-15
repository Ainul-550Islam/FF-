<?php

namespace App\Services;

use App\Contracts\PhoneOtpProviderInterface;
use App\Models\OtpChallenge;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phone one-time-code lifecycle (Phase 14).
 *
 * Issues, re-issues and verifies single-use OTP challenges. Codes are never
 * stored — only a keyed hash is persisted — and delivery goes through the
 * injected PhoneOtpProviderInterface, which is honest about whether it can
 * actually deliver (log provider in dev/test, SMS gateway in production).
 *
 * Rate/abuse controls (resend cooldown, per-phone daily cap, verification
 * attempt cap, expiry) are all enforced here, so every caller gets them for
 * free.
 */
class PhoneOtpService
{
    public function __construct(
        protected PhoneOtpProviderInterface $provider,
    ) {
    }

    /**
     * Normalize a Bangladeshi phone number to E.164 (+8801XXXXXXXXX).
     * Accepts 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX and common
     * formatting characters.
     *
     * @throws DomainException when the number is not a valid BD mobile.
     */
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Local 11-digit form: 01XXXXXXXXX.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '880' . substr($digits, 1);
        }

        $normalized = '+' . $digits;

        if (! preg_match('/^\+8801\d{9}$/', $normalized)) {
            throw new DomainException('Enter a valid Bangladeshi mobile number (e.g. 01712345678).');
        }

        return $normalized;
    }

    /**
     * Issue a new OTP challenge and deliver the code.
     *
     * @throws DomainException on invalid phone/purpose, cooldown, daily cap
     *                          or delivery failure.
     */
    public function issue(?User $user, string $phone, string $purpose): OtpChallenge
    {
        $normalized = $this->normalize($phone);

        if (! in_array($purpose, OtpChallenge::PURPOSES, true)) {
            throw new DomainException('Unknown OTP purpose.');
        }

        if (! $this->provider->isConfigured()) {
            throw new DomainException('Phone verification is not configured.');
        }

        $cooldown = (int) config('account.otp.resend_cooldown_seconds', 60);
        $recent = OtpChallenge::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first();

        if ($recent !== null && $recent->created_at->gt(now()->subSeconds($cooldown))) {
            throw new DomainException('Please wait before requesting another code.');
        }

        $dailyCap = (int) config('account.otp.daily_cap', 10);
        $todayCount = OtpChallenge::where('phone', $normalized)
            ->whereDate('created_at', today())
            ->count();

        if ($todayCount >= $dailyCap) {
            throw new DomainException('Too many verification codes requested today. Try again tomorrow.');
        }

        $code = $this->generateCode();
        $expiresSeconds = (int) config('account.otp.expires_seconds', 300);

        $challenge = DB::transaction(function () use ($user, $normalized, $purpose, $code, $expiresSeconds) {
            $challenge = new OtpChallenge();
            $challenge->user_id = $user?->id;
            $challenge->phone = $normalized;
            $challenge->purpose = $purpose;
            $challenge->code_hash = $this->hash($code);
            $challenge->attempts = 0;
            $challenge->expires_at = now()->addSeconds($expiresSeconds);
            $challenge->status = OtpChallenge::STATUS_PENDING;
            $challenge->save();

            try {
                $this->provider->send($normalized, $code);
            } catch (\Throwable $e) {
                // Never leave an undeliverable challenge behind.
                $challenge->delete();

                throw new DomainException('Could not send the verification code. Please try again.');
            }

            return $challenge;
        });

        return $challenge;
    }

    /**
     * Verify a code for the given phone + purpose.
     *
     * @return OtpChallenge the verified challenge
     *
     * @throws DomainException on invalid, expired or exhausted challenges.
     */
    public function verify(?User $user, string $phone, string $purpose, string $code): OtpChallenge
    {
        $normalized = $this->normalize($phone);

        $challenge = OtpChallenge::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->first();

        if ($challenge === null || ! $challenge->isPending()) {
            throw new DomainException('This code has expired. Request a new one.');
        }

        $maxAttempts = (int) config('account.otp.max_attempts', 5);

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->status = OtpChallenge::STATUS_CONSUMED;
            $challenge->save();

            throw new DomainException('Too many incorrect attempts. Request a new code.');
        }

        if (! hash_equals($challenge->code_hash, $this->hash($code))) {
            $challenge->attempts = $challenge->attempts + 1;

            if ($challenge->attempts >= $maxAttempts) {
                $challenge->status = OtpChallenge::STATUS_CONSUMED;
            }

            $challenge->save();

            throw new DomainException('Incorrect code.');
        }

        // Single use: mark verified so the same code can never be replayed.
        $challenge->status = OtpChallenge::STATUS_VERIFIED;
        $challenge->verified_at = now();
        $challenge->save();

        return $challenge;
    }

    /**
     * Generate a random numeric code.
     */
    public function generateCode(): string
    {
        $length = (int) config('account.otp.length', 6);

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Keyed hash of a code (the only thing ever persisted).
     */
    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
