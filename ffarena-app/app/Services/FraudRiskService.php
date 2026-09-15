<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Payment;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The fraud/risk rule engine (Phase 10).
 *
 * The single place where risk signals are recorded, scores are calculated,
 * levels are derived and actions are determined. Controllers call this
 * service's gates; they never compute risk themselves.
 *
 * Scoring is fully deterministic: score = min(100, Σ event score_contribution);
 * the level is derived from the configured thresholds. Defaults are
 * conservative — a fresh account is `low` and every action is allowed.
 */
class FraudRiskService
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_FLAG = 'flag';
    public const ACTION_REQUIRE_REVIEW = 'require_review';
    public const ACTION_RESTRICT = 'restrict';

    /**
     * Get (or lazily create) a user's risk profile.
     */
    public function profileFor(User $user): RiskProfile
    {
        $profile = $user->riskProfile()->first();

        if ($profile !== null) {
            return $profile;
        }

        $profile = new RiskProfile();
        $profile->user_id = $user->id;
        $profile->risk_score = 0;
        $profile->risk_level = RiskProfile::LEVEL_LOW;
        $profile->status = RiskProfile::STATUS_ACTIVE;
        $profile->save();

        return $profile;
    }

    /**
     * Record an immutable risk signal and recalculate the user's score.
     * Null users are accepted (signals with no attributable account) and
     * simply persist the event.
     */
    public function recordSignal(
        ?User $user,
        string $type,
        string $severity,
        string $source,
        array $metadata = [],
        ?Tournament $tournament = null,
    ): RiskEvent {
        if (! in_array($severity, RiskEvent::SEVERITIES, true)) {
            throw new DomainException('Unknown risk severity.');
        }

        $score = $this->scoreForSeverity($severity);

        return DB::transaction(function () use ($user, $type, $severity, $source, $metadata, $tournament, $score) {
            $event = new RiskEvent();
            $event->user_id = $user?->id;
            $event->tournament_id = $tournament?->id;
            $event->type = $type;
            $event->severity = $severity;
            $event->score_contribution = $score;
            $event->source = $source;
            $event->metadata = $metadata;
            $event->save();

            if ($user !== null) {
                $this->recalculate($user);
            }

            return $event;
        });
    }

    /**
     * Deterministically recompute a user's risk score and level.
     */
    public function recalculate(User $user): RiskProfile
    {
        $profile = $this->profileFor($user);

        $score = (int) RiskEvent::where('user_id', $user->id)->sum('score_contribution');
        $score = min(100, $score);

        $profile->risk_score = $score;
        $profile->risk_level = $this->levelFromScore($score);
        $profile->last_risk_calculation_at = now();
        $profile->save();

        return $profile;
    }

    /**
     * Map a score to a deterministic risk level.
     */
    public function levelFromScore(int $score): string
    {
        $thresholds = config('antifraud.risk.thresholds', [
            'medium' => 30,
            'high' => 60,
            'critical' => 90,
        ]);

        if ($score >= (int) $thresholds['critical']) {
            return RiskProfile::LEVEL_CRITICAL;
        }

        if ($score >= (int) $thresholds['high']) {
            return RiskProfile::LEVEL_HIGH;
        }

        if ($score >= (int) $thresholds['medium']) {
            return RiskProfile::LEVEL_MEDIUM;
        }

        return RiskProfile::LEVEL_LOW;
    }

    /**
     * Default score contribution for a severity label.
     */
    public function scoreForSeverity(string $severity): int
    {
        $map = config('antifraud.risk.severity_scores', [
            'info' => 0,
            'low' => 5,
            'medium' => 15,
            'high' => 30,
            'critical' => 50,
        ]);

        return (int) ($map[$severity] ?? 0);
    }

    /**
     * The configured action for a user's current risk level.
     */
    public function actionFor(User $user): string
    {
        $profile = $this->profileFor($user);

        $actions = config('antifraud.risk.actions', [
            'low' => 'allow',
            'medium' => 'flag',
            'high' => 'require_review',
            'critical' => 'restrict',
        ]);

        return (string) ($actions[$profile->risk_level] ?? self::ACTION_ALLOW);
    }

    /**
     * Server-side enforcement gate for a protected action context.
     *
     * Contexts map to the restriction types that block them. `restrict`
     * actions (or any matching active restriction) throw; `flag` /
     * `require_review` record an audit signal without blocking.
     *
     * @throws DomainException when the action is blocked.
     */
    public function gate(User $user, string $context, ?Tournament $tournament = null, ?array $restrictionTypes = null): string
    {
        $types = $restrictionTypes ?? $this->restrictionTypesFor($context);

        if ($types !== [] && app(RestrictionService::class)->isBlocked($user, $types)) {
            throw new DomainException('This account is restricted from this action.');
        }

        $action = $this->actionFor($user);

        if ($action === self::ACTION_RESTRICT) {
            throw new DomainException('This action requires fraud review before it can proceed.');
        }

        if ($action === self::ACTION_REQUIRE_REVIEW) {
            $this->flagForReview($user, $context, $tournament);
        } elseif ($action === self::ACTION_FLAG) {
            $this->recordSignal($user, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'risk', ['context' => $context], $tournament);
        }

        return $action;
    }

    /**
     * Mark a profile for manual review (idempotent) and audit the trigger.
     */
    public function flagForReview(User $user, string $context, ?Tournament $tournament = null): RiskProfile
    {
        $profile = $this->profileFor($user);

        if (! $profile->manual_review_required) {
            $profile->manual_review_required = true;
            $profile->save();
        }

        $this->recordSignal($user, RiskEvent::TYPE_RISK_REVIEW, RiskEvent::SEVERITY_INFO, 'risk', ['context' => $context], $tournament);

        return $profile;
    }

    // ------------------------------------------------------------------
    // Context-specific evaluations
    // ------------------------------------------------------------------

    /**
     * Evaluate tournament registration for a user.
     */
    public function evaluateRegistration(Tournament $tournament, User $user): string
    {
        return $this->gate($user, 'registration', $tournament);
    }

    /**
     * Evaluate a payment attempt for a user.
     */
    public function evaluatePayment(User $user, ?Tournament $tournament = null): string
    {
        return $this->gate($user, 'payment', $tournament);
    }

    /**
     * Evaluate a payout for its recipient.
     *
     * @return string allow | require_review | restrict
     */
    public function evaluatePayout(Payout $payout): string
    {
        $user = $payout->recipient;

        if ($user === null) {
            return self::ACTION_ALLOW;
        }

        // Repeated-win pattern check (deterministic, non-confiscatory).
        $wins = Payout::where('recipient_user_id', $user->id)
            ->where('status', Payout::STATUS_COMPLETED)
            ->count();

        $threshold = (int) config('antifraud.payout.repeat_wins_threshold', 3);

        if ($wins >= $threshold) {
            $this->recordSignal($user, RiskEvent::TYPE_PRIZE_WIN_PATTERN, RiskEvent::SEVERITY_MEDIUM, 'payout', [
                'payout_id' => $payout->id,
                'completed_wins' => $wins,
            ], $payout->tournament);
        }

        $actions = config('antifraud.payout.actions', [
            'low' => 'allow',
            'medium' => 'allow',
            'high' => 'require_review',
            'critical' => 'restrict',
        ]);

        $profile = $this->profileFor($user);

        $action = (string) ($actions[$profile->risk_level] ?? self::ACTION_ALLOW);

        if ($action !== self::ACTION_ALLOW) {
            $this->recordSignal($user, RiskEvent::TYPE_PAYOUT_RECIPIENT, RiskEvent::SEVERITY_INFO, 'payout', [
                'payout_id' => $payout->id,
                'risk_level' => $profile->risk_level,
                'action' => $action,
            ], $payout->tournament);

            // A held payout always flags the recipient for manual review.
            $this->flagForReview($user, 'payout', $payout->tournament);
        }

        return $action;
    }

    /**
     * Record a failed payment signal for a payer (additive; never mutates
     * the Phase 08 payment/wallet state).
     */
    public function recordPaymentFailure(Payment $payment): void
    {
        $user = $payment->payer;

        if ($user === null) {
            return;
        }

        $this->recordSignal($user, RiskEvent::TYPE_PAYMENT_FAILED, RiskEvent::SEVERITY_LOW, 'payment', [
            'payment_id' => $payment->id,
        ], $payment->tournament);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The restriction types that block each action context.
     *
     * @return string[]
     */
    protected function restrictionTypesFor(string $context): array
    {
        return match ($context) {
            'registration', 'payment' => [
                \App\Models\Restriction::TYPE_REGISTRATION_BLOCKED,
                \App\Models\Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'checkin' => [
                \App\Models\Restriction::TYPE_CHECKIN_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'score_submission' => [
                \App\Models\Restriction::TYPE_SCORE_SUBMISSION_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            'dispute' => [
                \App\Models\Restriction::TYPE_DISPUTE_BLOCKED,
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
            default => [
                \App\Models\Restriction::TYPE_ACCOUNT_SUSPENDED,
            ],
        };
    }
}
