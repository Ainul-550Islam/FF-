<?php

namespace App\Services\Gameberry;

/**
 * GAP-10 A8 (tracker row 018) — the decision object returned by the
 * Gameberry-side anti-cheat evaluation.
 *
 * It is intentionally inert: a value object with no database handle and no
 * services. Reading a decision can never change state, so the evaluation is
 * safe to run on every session review, in a queue, or in a dry run.
 *
 * Decisions:
 *   allow  — nothing observed; the session is ordinary.
 *   flag   — suspicious observations, handed to the human review queue.
 *   block  — the action must be refused by the caller (a blocked decision is
 *            still only a refusal of *the submitted action*, never a
 *            unilateral account restriction and never a wallet change).
 *   review — a human must decide before the action proceeds (used when the
 *            inputs themselves are not trustworthy, e.g. unusable timestamps).
 */
final class AntiCheatDecision
{
    public const ALLOW = 'allow';

    public const FLAG = 'flag';

    public const BLOCK = 'block';

    public const REVIEW = 'review';

    public const DECISIONS = [self::ALLOW, self::FLAG, self::BLOCK, self::REVIEW];

    /**
     * @param  array<int, string>  $reasons  human-readable reasons, may be empty
     * @param  array<int, array{rule: string, weight: int, detail: string}>  $signals
     */
    public function __construct(
        public readonly string $decision,
        public readonly int $score,
        public readonly array $reasons = [],
        public readonly array $signals = [],
        public readonly ?int $sessionId = null,
        public readonly int $actionCount = 0,
        public readonly ?string $evaluatedAt = null,
    ) {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException("Unknown anti-cheat decision [{$decision}].");
        }
    }

    public function isAllowed(): bool
    {
        return $this->decision === self::ALLOW;
    }

    public function isFlagged(): bool
    {
        return $this->decision === self::FLAG;
    }

    public function isBlocked(): bool
    {
        return $this->decision === self::BLOCK;
    }

    public function requiresReview(): bool
    {
        return $this->decision === self::REVIEW;
    }

    /**
     * Whether this decision should be escalated to the persistent anti-cheat
     * incident queue (staff-visible). `allow` and `review` are not incidents:
     * review only means the inputs were unusable.
     */
    public function shouldEscalate(): bool
    {
        return in_array($this->decision, [self::FLAG, self::BLOCK], true);
    }

    /**
     * The rule names that fired, deduplicated and ordered by first occurrence.
     *
     * @return array<int, string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->signals as $signal) {
            $rule = (string) ($signal['rule'] ?? '');

            if ($rule !== '' && ! in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * A safe, loggable representation. Contains no wallet balances, no raw
     * payloads and no personal data — only rule names, weights and counts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'score' => $this->score,
            'rules' => $this->rules(),
            'reasons' => array_values($this->reasons),
            'session_id' => $this->sessionId,
            'action_count' => $this->actionCount,
            'evaluated_at' => $this->evaluatedAt,
        ];
    }
}
