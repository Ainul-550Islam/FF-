<?php

namespace App\Services\Push;

use App\Models\Notification;
use App\Models\NotificationPreference;

/**
 * Phase 19 — builds safe, redacted push payloads from an in-app notification.
 *
 * Responsibilities:
 *   * map a notification type to a preference category and a priority;
 *   * redact sensitive bodies (payments, payouts, disputes, security) to a
 *     generic phrase — push must never reveal wallet balances, amounts,
 *     dispute evidence or internal risk reasoning;
 *   * derive a deep link ONLY from server-authored entity hints already in
 *     the notification's data (never invented by the client or guessed);
 *   * attach the notification id so clients can deduplicate a push against
 *     the in-app row and against repeated deliveries.
 */
final class PushPayloadBuilder
{
    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_NORMAL = 'normal';

    /** Notification types that must reach the user with high priority. */
    private const SECURITY_HIGH = [
        'auth.password_changed',
        'auth.password_reset',
        'auth.session_revoked',
        'auth.suspicious_login',
        'auth.account_deactivated',
        'restriction.applied',
    ];

    /** Sensitive categories whose push body is always redacted. */
    private const SENSITIVE_CATEGORIES = ['payment', 'payout', 'dispute', 'security'];

    /** Generic (safe) bodies per sensitive category. */
    private const GENERIC_BODIES = [
        'payment' => 'Your payment status was updated. Open the app for details.',
        'payout' => 'Your payout status was updated. Open the app for details.',
        'dispute' => 'Your dispute status changed. Open the app for details.',
        'security' => 'A security event occurred on your account. Open the app for details.',
    ];

    private const GENERIC_BODY_DEFAULT = 'New activity on your account. Open the app for details.';

    /** Entity keys (in order of precedence) found in a notification's data. */
    private const ENTITY_KEYS = [
        'payment_id' => 'payment',
        'payout_id' => 'payout',
        'dispute_id' => 'dispute',
        'ticket_id' => 'support',
        'support_ticket_id' => 'support',
        'match_id' => 'match',
        'team_id' => 'team',
        'tournament_id' => 'tournament',
    ];

    public function build(Notification $notification): PushMessage
    {
        $category = $this->categoryFor($notification->type) ?? 'system';

        return new PushMessage(
            title: $this->titleFor($notification),
            body: $this->bodyFor($notification),
            category: $category,
            priority: $this->priorityFor($notification->type),
            data: $this->dataFor($notification),
        );
    }

    /**
     * Map a notification type to its preference category, or null for types
     * with no toggle (always delivered).
     */
    public function categoryFor(string $type): ?string
    {
        return match (true) {
            str_starts_with($type, 'payment.') => NotificationPreference::CATEGORY_PAYMENT,
            str_starts_with($type, 'payout.') => NotificationPreference::CATEGORY_PAYOUT,
            str_starts_with($type, 'settlement.') => NotificationPreference::CATEGORY_PAYOUT,
            str_starts_with($type, 'dispute.') => NotificationPreference::CATEGORY_DISPUTE,
            str_starts_with($type, 'support.') => NotificationPreference::CATEGORY_SUPPORT,
            str_starts_with($type, 'team.') => NotificationPreference::CATEGORY_TEAM,
            str_starts_with($type, 'tournament.') => NotificationPreference::CATEGORY_TOURNAMENT,
            str_starts_with($type, 'match.') => NotificationPreference::CATEGORY_MATCH,
            str_starts_with($type, 'auth.') => NotificationPreference::CATEGORY_SECURITY,
            str_starts_with($type, 'restriction.') => NotificationPreference::CATEGORY_SECURITY,
            str_starts_with($type, 'identity.') => NotificationPreference::CATEGORY_SECURITY,
            str_starts_with($type, 'anti_cheat.') => NotificationPreference::CATEGORY_SECURITY,
            default => null,
        };
    }

    public function priorityFor(string $type): string
    {
        return in_array($type, self::SECURITY_HIGH, true)
            ? self::PRIORITY_HIGH
            : self::PRIORITY_NORMAL;
    }

    public function isSensitive(string $type): bool
    {
        $category = $this->categoryFor($type);

        if ($category === null) {
            // Unmapped future types default to redacted — fail safe.
            return true;
        }

        return in_array($category, self::SENSITIVE_CATEGORIES, true);
    }

    private function titleFor(Notification $notification): string
    {
        $title = trim((string) $notification->title);

        return $title === '' ? 'FF Arena' : $title;
    }

    private function bodyFor(Notification $notification): string
    {
        if (! $this->isSensitive($notification->type)) {
            $body = trim((string) $notification->body);

            if ($body !== '') {
                return $body;
            }
        }

        $category = $this->categoryFor($notification->type);

        return self::GENERIC_BODIES[$category] ?? self::GENERIC_BODY_DEFAULT;
    }

    /**
     * The structured data payload. Contains the notification id (for client
     * deduplication), the type/category, and — only when the server already
     * authored entity hints in the notification data — an entity_type/id and
     * a deep link. Never contains secrets.
     */
    private function dataFor(Notification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        [$entityType, $entityId] = $this->entityFor($data);

        $payload = [
            'notification_id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'category' => $this->categoryFor($notification->type) ?? 'system',
        ];

        if ($entityType !== null && $entityId !== null) {
            $payload['entity_type'] = $entityType;
            $payload['entity_id'] = (string) $entityId;
            $payload['deep_link'] = $this->deepLinkFor($entityType, $entityId);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:?string,1:?string}
     */
    private function entityFor(array $data): array
    {
        foreach (self::ENTITY_KEYS as $key => $entityType) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $id = is_numeric($value) ? (int) $value : null;

            if ($id !== null && $id > 0) {
                return [$entityType, (string) $id];
            }
        }

        return [null, null];
    }

    private function deepLinkFor(string $entityType, string $entityId): string
    {
        $scheme = (string) config('mobile.deep_link_scheme', 'ffarena');

        return $scheme.'://'.$entityType.'/'.$entityId;
    }
}
