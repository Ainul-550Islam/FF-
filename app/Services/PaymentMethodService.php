<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use DomainException;

/**
 * Saved payment-method management (Phase 14).
 *
 * Methods belong to exactly one user; every operation is ownership-checked.
 * Only a masked identifier is ever stored — card numbers, full phone numbers
 * and account details never reach this table.
 */
class PaymentMethodService
{
    public function __construct(
        protected AuditLogService $audit,
    ) {
    }

    /**
     * The user's saved payment methods (active first, default first).
     */
    public function listFor(User $user)
    {
        return $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Save a new payment method for the user.
     */
    public function add(User $user, string $provider, string $label, string $identifier): PaymentMethod
    {
        if (! in_array($provider, PaymentMethod::PROVIDERS, true)) {
            throw new DomainException('Unknown payment provider.');
        }

        $digits = preg_replace('/\D/', '', $identifier) ?? '';

        if (strlen($digits) < 6 || strlen($digits) > 20) {
            throw new DomainException('Enter a valid account identifier.');
        }

        $hasDefault = $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->where('is_default', true)
            ->exists();

        $method = new PaymentMethod();
        $method->user_id = $user->id;
        $method->provider = $provider;
        $method->label = mb_substr(trim($label), 0, 60);
        $method->masked_identifier = $this->mask($identifier);
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->is_default = ! $hasDefault;
        $method->save();

        $this->audit->recordQuietly($user, 'payment_method.added', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $provider],
        ]);

        return $method;
    }

    /**
     * Remove (soft-delete) a payment method. If it was the default, promote
     * another method or clear the default.
     */
    public function remove(User $user, PaymentMethod $method): PaymentMethod
    {
        $this->assertOwned($user, $method);

        if (! $method->isActive()) {
            throw new DomainException('This payment method has already been removed.');
        }

        $wasDefault = (bool) $method->is_default;

        $method->status = PaymentMethod::STATUS_REMOVED;
        $method->is_default = false;
        $method->save();

        if ($wasDefault) {
            $next = $user->paymentMethods()
                ->where('status', PaymentMethod::STATUS_ACTIVE)
                ->orderByDesc('id')
                ->first();

            if ($next !== null) {
                $next->is_default = true;
                $next->save();
            }
        }

        $this->audit->recordQuietly($user, 'payment_method.removed', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $method->provider],
        ]);

        return $method;
    }

    /**
     * Mark a payment method as the default (exactly one default per user).
     */
    public function setDefault(User $user, PaymentMethod $method): PaymentMethod
    {
        $this->assertOwned($user, $method);

        if (! $method->isActive()) {
            throw new DomainException('This payment method has been removed.');
        }

        $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->update(['is_default' => false]);

        $method->is_default = true;
        $method->save();

        $this->audit->recordQuietly($user, 'payment_method.default', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $method->provider],
        ]);

        return $method;
    }

    /**
     * Mask an identifier, keeping only its last four digits.
     */
    public function mask(string $identifier): string
    {
        $digits = preg_replace('/\D/', '', $identifier) ?? '';
        $last = substr($digits, -4);

        return '****' . str_repeat('*', max(0, strlen($digits) - 4)) . $last;
    }

    protected function assertOwned(User $user, PaymentMethod $method): void
    {
        if ($method->user_id !== $user->id) {
            throw new DomainException('This payment method does not belong to you.');
        }
    }
}
