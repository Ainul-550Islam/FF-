<?php

namespace App\Services;

use App\Models\MarketingAutomation;
use App\Models\MarketingEvent;
use App\Models\Notification;
use App\Models\User;
use Throwable;

/**
 * Phase 21 — lifecycle automation engine.
 *
 * Automations are data rows (trigger → audience filter → action payload).
 * Delivery reuses the existing NotificationService (in-app row + best-effort
 * email + push) — no second delivery stack. Every send is cooldown-ledgered
 * in marketing_events so a trigger fired twice never sends twice inside the
 * window, each automation fails independently and quietly (a broken one can
 * never block the auth/payment flow that fired it), and evaluate() always
 * returns an honest count of what was actually sent.
 */
class MarketingAutomationService
{
    /** marketing_events ledger name for sends (the cooldown source of truth). */
    public const SEND_EVENT = 'automation_sent';

    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Fire every enabled automation bound to a trigger for one user.
     *
     * @return int the number of sends that actually happened
     */
    public function evaluate(string $trigger, ?User $user, array $context = []): int
    {
        if (! config('marketing.events.enabled', true) || $user === null) {
            return 0;
        }

        $automations = MarketingAutomation::query()
            ->where('enabled', true)
            ->where('trigger', $trigger)
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($automations as $automation) {
            try {
                if (! $this->matchesAudience($automation, $user)) {
                    continue;
                }

                if ($this->wasRecentlySent($automation, $user, (int) $automation->cooldown_hours)) {
                    continue;
                }

                $this->deliver($automation, $user, $context);

                $this->ledgerSend($automation, $user, $context);

                $sent++;
            } catch (Throwable $e) {
                // Quiet-fail: one broken automation never blocks the others
                // and never escapes into the caller's flow.
                report($e);
            }
        }

        return $sent;
    }

    /**
     * The optional audience filter (e.g. {"role":"organizer"}). No filter
     * means everyone the trigger hands over.
     */
    protected function matchesAudience(MarketingAutomation $automation, User $user): bool
    {
        $audience = $automation->audience;

        if (! is_array($audience) || $audience === []) {
            return true;
        }

        foreach ($audience as $attribute => $expected) {
            if ((string) ($user->{$attribute} ?? '') !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cooldown check against the send ledger: one ledger row per
     * (automation, user) inside the window blocks a repeat send.
     */
    protected function wasRecentlySent(MarketingAutomation $automation, User $user, int $cooldownHours): bool
    {
        $last = MarketingEvent::query()
            ->where('name', self::SEND_EVENT)
            ->where('user_id', $user->id)
            ->where('properties->automation', $automation->key)
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            return false;
        }

        return $last->created_at !== null
            && $last->created_at->greaterThan(now()->subHours(max($cooldownHours, 0)));
    }

    /**
     * Deliver through the existing notification infrastructure. The action
     * payload is server-owned data (type, title, body, optional route).
     */
    protected function deliver(MarketingAutomation $automation, User $user, array $context): Notification
    {
        $action = $automation->action ?? [];

        $type = (string) ($action['type'] ?? Notification::TYPE_MARKETING);
        $title = mb_substr((string) ($action['title'] ?? $automation->name), 0, 120);
        $body = mb_substr((string) ($action['body'] ?? ''), 0, 500);
        $link = isset($action['link']) && is_string($action['link']) ? $action['link'] : null;

        return $this->notifications->send($user, $type, $title, $body, $link, [
            'automation' => $automation->key,
            'trigger' => $automation->trigger,
            'context' => $context,
        ]);
    }

    /**
     * Append the cooldown-ledger row (marketing_events — the same funnel
     * ledger every other marketing moment uses).
     */
    protected function ledgerSend(MarketingAutomation $automation, User $user, array $context): void
    {
        MarketingEvent::create([
            'anonymous_id' => null,
            'user_id' => $user->id,
            'attribution_id' => null,
            'name' => self::SEND_EVENT,
            'properties' => [
                'automation' => $automation->key,
                'trigger' => $automation->trigger,
                'context' => $context,
            ],
            'url' => null,
            'created_at' => now(),
        ]);
    }
}
