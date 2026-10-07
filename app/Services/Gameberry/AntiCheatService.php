<?php

namespace App\Services\Gameberry;

use App\Models\AntiCheatIncident;
use App\Models\GameMatch;
use App\Models\GameSession;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AntiCheatService as CoreAntiCheatService;
use InvalidArgumentException;
use Throwable;

/**
 * GAP-10 A8 (tracker row 018) — Gameberry-side anti-cheat.
 *
 * Composes two layers:
 *
 *  1. This service owns the *deterministic, offline* evaluation of a
 *     Gameberry session: impossible action sequences and timings measured
 *     from the submitted action log (sub-human intervals, regressing or
 *     missing timestamps, duplicate action ids, actions outside the session
 *     window, floods, rate exceedance, results claimed without actions).
 *     It returns a value-object decision and writes nothing.
 *
 *  2. App\Services\AntiCheatService (Phase 10) owns the *persistent* incident
 *     and anomaly workflow: incidents, risk signals, the staff review queue
 *     and the auditable restriction path. This service delegates escalation
 *     to it when a tournament context exists, so a Gameberry flag lands in
 *     exactly the same queue as a tournament flag — one workflow, one audit
 *     trail, no parallel "shadow" anti-cheat system.
 *
 * Hard guarantees (asserted by tests):
 *
 *  - `evaluate()` performs no writes at all: no wallets, no ledger, no
 *    incidents, no restrictions. A pure function of its inputs plus config.
 *  - `escalate()` writes only through the core Phase 10 service, which never
 *    touches wallets either.
 *  - Money is never moved by either path. A confirmed cheat ends in the
 *    existing moderation/restriction workflow, which is separate from
 *    settlement and payout code and cannot credit or debit a balance.
 *
 * Note on the composition target: the original specification also referenced
 * a `Gameberry\ActionValidationService`. That class does not exist in this
 * revision of the repository, so the action-sequence rules live here, behind
 * a single `rules()` seam that can be extracted into that service later
 * without changing this class's public API. The deviation is recorded in
 * docs/GAP-10-FINDINGS-REGISTER.md.
 */
class AntiCheatService
{
    /**
     * Rule weights. Each rule contributes at most once per session (counting
     * *did* it happen), except the flood/rate rules whose severity scales with
     * how far past the threshold the log is.
     */
    public const RULE_INVALID_TIMESTAMP = 'invalid_timestamp';

    public const RULE_REGRESSING_TIMESTAMP = 'regressing_timestamp';

    public const RULE_IMPOSSIBLE_SPEED = 'impossible_action_speed';

    public const RULE_DUPLICATE_ACTION = 'duplicate_action_id';

    public const RULE_OUTSIDE_WINDOW = 'action_outside_session';

    public const RULE_ACTION_FLOOD = 'action_flood';

    public const RULE_RATE_EXCEEDED = 'action_rate_exceeded';

    public const RULE_MISSING_START = 'missing_session_start';

    public const RULE_RESULT_WITHOUT_ACTIONS = 'result_without_actions';

    public const RULE_SESSION_OVERLONG = 'session_overlong';

    public const RULES = [
        self::RULE_INVALID_TIMESTAMP,
        self::RULE_REGRESSING_TIMESTAMP,
        self::RULE_IMPOSSIBLE_SPEED,
        self::RULE_DUPLICATE_ACTION,
        self::RULE_OUTSIDE_WINDOW,
        self::RULE_ACTION_FLOOD,
        self::RULE_RATE_EXCEEDED,
        self::RULE_MISSING_START,
        self::RULE_RESULT_WITHOUT_ACTIONS,
        self::RULE_SESSION_OVERLONG,
    ];

    public function __construct(
        protected CoreAntiCheatService $core,
    ) {}

    /**
     * Evaluate one Gameberry session against the action log.
     *
     * Deterministic and read-only: the same inputs always produce the same
     * decision, and nothing in the database is written.
     *
     * @param  array<int, array<string, mixed>>  $actions
     *         Each action: `['type' => string, 'at' => int|string, 'id' => ?string]`.
     *         `at` is epoch milliseconds, epoch seconds, or an ISO-8601 string.
     * @param  array<string, mixed>  $context
     *         Optional: `['has_session_start' => bool]`.
     */
    public function evaluate(GameSession $session, array $actions = [], array $context = []): AntiCheatDecision
    {
        if (! (bool) config('gameberry.anti_cheat.enabled', true)) {
            return new AntiCheatDecision(
                AntiCheatDecision::ALLOW,
                0,
                [],
                [],
                $session->id,
                count($actions),
                now()->toIso8601String(),
            );
        }

        $signals = [];
        $reasons = [];

        /** @var array<int, int> $timestamps  index => epoch milliseconds */
        $timestamps = [];
        $invalidTimestamps = 0;
        $seenIds = [];
        $duplicateIds = 0;

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                $invalidTimestamps++;

                continue;
            }

            $raw = $action['at'] ?? $action['timestamp'] ?? null;
            $normalised = $this->normaliseTimestamp($raw);

            if ($normalised === null) {
                $invalidTimestamps++;

                continue;
            }

            $timestamps[$index] = $normalised;

            $id = $action['id'] ?? $action['action_id'] ?? null;

            if (is_scalar($id) && (string) $id !== '') {
                $key = (string) $id;

                if (isset($seenIds[$key])) {
                    $duplicateIds++;
                }

                $seenIds[$key] = true;
            }
        }

        $count = count($actions);

        // ---- rule: unusable timestamps -------------------------------------
        if ($invalidTimestamps > 0) {
            $weight = $invalidTimestamps >= max(2, (int) ceil($count / 2)) ? 3 : 1;

            $signals[] = $this->signal(self::RULE_INVALID_TIMESTAMP, $weight, "{$invalidTimestamps} action(s) carry no usable timestamp");
            $reasons[] = 'Action log contains entries without a usable timestamp.';
        }

        // ---- rule: regressing timestamps ------------------------------------
        $regressions = 0;
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if ($previous !== null && $timestamp < $previous) {
                $regressions++;
            }

            $previous = $timestamp;
        }

        if ($regressions > 0) {
            $signals[] = $this->signal(self::RULE_REGRESSING_TIMESTAMP, 3, "{$regressions} action(s) went backwards in time");
            $reasons[] = 'Action timestamps are not monotonically increasing.';
        }

        // ---- rule: impossible action speed ---------------------------------
        $minInterval = (int) config('gameberry.anti_cheat.min_action_interval_ms', 120);
        $tooFast = 0;
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if ($previous !== null) {
                $delta = $timestamp - $previous;

                if ($delta >= 0 && $delta < $minInterval) {
                    $tooFast++;
                }
            }

            $previous = $timestamp;
        }

        if ($tooFast > 0) {
            // One impossible interval can be clock jitter; a pattern cannot.
            $weight = $tooFast >= 3 ? 3 : 1;

            $signals[] = $this->signal(self::RULE_IMPOSSIBLE_SPEED, $weight, "{$tooFast} action interval(s) below {$minInterval}ms");
            $reasons[] = 'Actions were submitted faster than a human can input them.';
        }

        // ---- rule: duplicate action ids -------------------------------------
        if ($duplicateIds > 0) {
            $signals[] = $this->signal(self::RULE_DUPLICATE_ACTION, 2, "{$duplicateIds} duplicate action id(s)");
            $reasons[] = 'The same action id was submitted more than once.';
        }

        // ---- rule: actions outside the session window ------------------------
        $startedAt = $session->started_at?->getTimestampMs();
        $finishedAt = $session->finished_at?->getTimestampMs();
        $outside = 0;

        foreach ($timestamps as $timestamp) {
            if ($startedAt !== null && $timestamp < $startedAt - 1000) {
                $outside++;
            } elseif ($finishedAt !== null && $timestamp > $finishedAt + 1000) {
                $outside++;
            }
        }

        if ($outside > 0) {
            $signals[] = $this->signal(self::RULE_OUTSIDE_WINDOW, 2, "{$outside} action(s) fall outside the session window");
            $reasons[] = 'Actions were reported outside the session window.';
        }

        // ---- rule: absolute flood -------------------------------------------
        $maxActions = (int) config('gameberry.anti_cheat.max_actions_per_session', 2000);

        if ($count > $maxActions) {
            $signals[] = $this->signal(self::RULE_ACTION_FLOOD, 3, "{$count} actions exceeds the {$maxActions} ceiling");
            $reasons[] = 'The session contains more actions than the game mode can produce.';
        }

        // ---- rule: rate exceedance ------------------------------------------
        $maxPerMinute = (int) config('gameberry.anti_cheat.max_actions_per_minute', 120);
        $busiestMinute = $this->busiestWindowCount(array_values($timestamps), 60_000);

        if ($busiestMinute > $maxPerMinute) {
            $signals[] = $this->signal(self::RULE_RATE_EXCEEDED, 2, "{$busiestMinute} actions inside 60s (limit {$maxPerMinute})");
            $reasons[] = 'Action rate exceeded the plausible input rate.';
        }

        // ---- rule: missing session start ------------------------------------
        $hasStart = (bool) ($context['has_session_start'] ?? true);

        if (! $hasStart && $count > 0) {
            $signals[] = $this->signal(self::RULE_MISSING_START, 2, 'actions submitted without a session start');
            $reasons[] = 'Actions were submitted without a session start event.';
        }

        // ---- rule: result without actions ------------------------------------
        $claimsResult = in_array((string) $session->result, ['win', 'loss'], true);

        if ($claimsResult && $count === 0) {
            $signals[] = $this->signal(self::RULE_RESULT_WITHOUT_ACTIONS, 3, 'result recorded with an empty action log');
            $reasons[] = 'A result was recorded for a session with no actions.';
        }

        // ---- rule: overlong session ------------------------------------------
        $maxMinutes = (int) config('gameberry.anti_cheat.max_session_minutes', 90);
        $durationSeconds = (int) ($session->duration_seconds ?? 0);

        if ($durationSeconds > $maxMinutes * 60) {
            $signals[] = $this->signal(self::RULE_SESSION_OVERLONG, 2, "duration {$durationSeconds}s exceeds {$maxMinutes} minutes");
            $reasons[] = 'The session ran far longer than the game mode allows.';
        }

        return $this->decide($session->id, $count, $signals, $reasons);
    }

    /**
     * Evaluate and, when the decision warrants it and a tournament context is
     * available, escalate to the persistent Phase 10 incident queue.
     *
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array{tournament?: Tournament, match?: GameMatch, team?: Team, accused?: User, reporter?: User}  $context
     * @return array{decision: AntiCheatDecision, incident: AntiCheatIncident|null}
     */
    public function evaluateAndEscalate(GameSession $session, array $actions, array $context): array
    {
        $decision = $this->evaluate($session, $actions, [
            'has_session_start' => (bool) ($context['has_session_start'] ?? true),
        ]);

        if (! $decision->shouldEscalate()) {
            return ['decision' => $decision, 'incident' => null];
        }

        return ['decision' => $decision, 'incident' => $this->escalate($session, $decision, $context)];
    }

    /**
     * Open an anti-cheat incident for a flagged/blocked Gameberry session.
     *
     * Requires a tournament (the incident workflow is tournament-scoped) and a
     * reporter; returns null when the decision does not warrant an incident or
     * the context is incomplete, so a caller can never create an incident by
     * accident.
     *
     * @param  array{tournament?: Tournament, match?: GameMatch, team?: Team, accused?: User, reporter?: User}  $context
     */
    public function escalate(GameSession $session, AntiCheatDecision $decision, array $context): ?AntiCheatIncident
    {
        if (! $decision->shouldEscalate()) {
            return null;
        }

        $tournament = $context['tournament'] ?? null;
        $reporter = $context['reporter'] ?? null;

        if (! $tournament instanceof Tournament || ! $reporter instanceof User) {
            // An incident is tournament-scoped and must name who escalated it.
            // Without both, the flag stays in the log rather than becoming an
            // unattributed accusation.
            return null;
        }

        try {
            return $this->core->openIncident(
                tournament: $tournament,
                match: $context['match'] ?? null,
                team: $context['team'] ?? null,
                accusedUser: $context['accused'] ?? $session->user,
                reporter: $reporter,
                source: AntiCheatIncident::SOURCE_SYSTEM,
                category: CoreAntiCheatService::CATEGORY_SCORE_MANIPULATION,
                // Severity values come from AntiCheatIncident::SEVERITIES.
                severity: $decision->isBlocked() ? 'high' : 'medium',
                description: 'Gameberry session '.$session->id.' flagged: '.implode(' ', $decision->reasons),
                evidenceReference: 'game_session:'.$session->id,
            );
        } catch (Throwable) {
            // Escalation must never break the caller's flow; the decision is
            // still returned and the caller decides what to do with it.
            return null;
        }
    }

    /**
     * Build the decision from the accumulated signals.
     *
     * @param  array<int, array{rule: string, weight: int, detail: string}>  $signals
     * @param  array<int, string>  $reasons
     */
    protected function decide(?int $sessionId, int $actionCount, array $signals, array $reasons): AntiCheatDecision
    {
        $score = 0;

        foreach ($signals as $signal) {
            $score += (int) $signal['weight'];
        }

        $flagScore = (int) config('gameberry.anti_cheat.flag_score', 3);
        $blockScore = (int) config('gameberry.anti_cheat.block_score', 6);

        if ($signals === []) {
            $decision = AntiCheatDecision::ALLOW;
        } elseif ($score >= $blockScore) {
            $decision = AntiCheatDecision::BLOCK;
        } elseif ($score >= $flagScore) {
            $decision = AntiCheatDecision::FLAG;
        } else {
            // Low weight: one anomaly, recorded but not escalated. The human
            // queue is deliberately reserved for corroborated patterns.
            $decision = AntiCheatDecision::ALLOW;
        }

        return new AntiCheatDecision(
            $decision,
            $score,
            array_values($reasons),
            $signals,
            $sessionId,
            $actionCount,
            now()->toIso8601String(),
        );
    }

    /**
     * @param  array<int, int>  $timestamps  sorted epoch milliseconds
     */
    protected function busiestWindowCount(array $timestamps, int $windowMs): int
    {
        sort($timestamps);

        $busiest = 0;
        $left = 0;
        $total = count($timestamps);

        for ($right = 0; $right < $total; $right++) {
            while ($timestamps[$right] - $timestamps[$left] > $windowMs) {
                $left++;
            }

            $busiest = max($busiest, $right - $left + 1);
        }

        return $busiest;
    }

    /**
     * Normalise a timestamp to epoch milliseconds, or null when unusable.
     */
    protected function normaliseTimestamp(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            return $this->scaleNumeric((float) $raw);
        }

        if (is_string($raw)) {
            if (is_numeric($raw)) {
                return $this->scaleNumeric((float) $raw);
            }

            $parsed = strtotime($raw);

            return $parsed === false ? null : $parsed * 1000;
        }

        return null;
    }

    /**
     * Epoch seconds (10 digits), milliseconds (13) and microseconds (16) are
     * all accepted; anything else is rejected rather than guessed at.
     */
    protected function scaleNumeric(float $value): ?int
    {
        if ($value <= 0) {
            return null;
        }

        if ($value < 1e11) {
            return (int) round($value * 1000);
        }

        if ($value < 1e14) {
            return (int) round($value);
        }

        if ($value < 1e17) {
            return (int) round($value / 1000);
        }

        return null;
    }

    /**
     * @return array{rule: string, weight: int, detail: string}
     */
    protected function signal(string $rule, int $weight, string $detail): array
    {
        if (! in_array($rule, self::RULES, true)) {
            throw new InvalidArgumentException("Unknown anti-cheat rule [{$rule}].");
        }

        return ['rule' => $rule, 'weight' => $weight, 'detail' => $detail];
    }
}
