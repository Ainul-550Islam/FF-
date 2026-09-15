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
