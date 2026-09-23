<?php

namespace App\Services;

use App\Models\AntiCheatIncident;
use App\Models\GameMatch;
use App\Models\MatchAnomaly;
use App\Models\Notification;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Defensive anti-cheat incidents + match anomaly detection (Phase 10).
 *
 * Anomalies are deterministic, server-side observations and are NEVER
 * auto-labelled "cheating"; the moderation decision is a separate, human
 * workflow. Incidents move through flagged → under_review → cleared /
 * confirmed / restricted / dismissed. Confirmed/restricted outcomes apply
 * auditable restrictions via RestrictionService; cleared/dismissed outcomes
 * are false-positive-safe.
 */
class AntiCheatService
{
    public const CATEGORY_AIMBOT = 'aimbot';

    public const CATEGORY_WALLHACK = 'wallhack';

    public const CATEGORY_SPEED_HACK = 'speed_hack';

    public const CATEGORY_TEAMING = 'teaming';

    public const CATEGORY_SCORE_MANIPULATION = 'score_manipulation';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_AIMBOT,
        self::CATEGORY_WALLHACK,
        self::CATEGORY_SPEED_HACK,
        self::CATEGORY_TEAMING,
        self::CATEGORY_SCORE_MANIPULATION,
        self::CATEGORY_OTHER,
    ];

    public function __construct(
        protected FraudRiskService $risk,
        protected RestrictionService $restrictions,
        protected NotificationService $notifications,
    ) {}

    /**
     * Open an anti-cheat incident.
     */
    public function openIncident(
        Tournament $tournament,
        ?GameMatch $match,
        ?Team $team,
        ?User $accusedUser,
        User $reporter,
        string $source,
        string $category,
        string $severity,
        ?string $description = null,
        ?string $evidenceReference = null,
    ): AntiCheatIncident {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new DomainException('Unknown anti-cheat category.');
        }

        if (! in_array($severity, AntiCheatIncident::SEVERITIES, true)) {
            throw new DomainException('Unknown anti-cheat severity.');
        }

        $incident = new AntiCheatIncident();
        $incident->tournament_id = $tournament->id;
        $incident->match_id = $match?->id;
        $incident->team_id = $team?->id;
        $incident->accused_user_id = $accusedUser?->id;
        $incident->reporter_user_id = $reporter->id;
        $incident->source = in_array($source, [
            AntiCheatIncident::SOURCE_SYSTEM,
            AntiCheatIncident::SOURCE_PARTICIPANT,
            AntiCheatIncident::SOURCE_STAFF,
        ], true) ? $source : AntiCheatIncident::SOURCE_SYSTEM;
        $incident->category = $category;
        $incident->severity = $severity;
        $incident->description = $description !== null && trim($description) !== '' ? trim($description) : null;
        $incident->evidence_reference = $evidenceReference;
        $incident->status = AntiCheatIncident::STATUS_FLAGGED;
        $incident->save();

        return $incident;
    }

    /**
     * Move a flagged incident under review (staff).
     */
    public function review(AntiCheatIncident $incident, User $reviewer): AntiCheatIncident
    {
        if (! $incident->canTransitionTo(AntiCheatIncident::STATUS_UNDER_REVIEW)) {
            throw new DomainException('Only flagged incidents can move under review.');
        }

        $incident->status = AntiCheatIncident::STATUS_UNDER_REVIEW;
        $incident->reviewer_id = $reviewer->id;
        $incident->save();

        return $incident;
    }

    /**
     * Resolve an incident (staff). Confirmed/restricted outcomes apply an
     * auditable restriction; cleared/dismissed are false-positive-safe.
     */
    public function resolve(AntiCheatIncident $incident, User $reviewer, string $resolution, string $resolutionText): AntiCheatIncident
    {
        if (! in_array($resolution, AntiCheatIncident::RESOLUTIONS, true)) {
            throw new DomainException('Unknown incident resolution.');
        }

        if (! $incident->canTransitionTo($resolution)) {
            throw new DomainException('This incident cannot be resolved from its current state.');
        }

        $resolutionText = trim($resolutionText);

        if ($resolutionText === '') {
            throw new DomainException('A resolution reason is required.');
        }

        return DB::transaction(function () use ($incident, $reviewer, $resolution, $resolutionText) {
            $incident->status = $resolution;
            $incident->reviewer_id = $reviewer->id;
            $incident->resolution = $resolutionText;
            $incident->resolved_at = now();
            $incident->save();

            $accused = $incident->accusedUser;

            if ($accused !== null) {
                if ($resolution === AntiCheatIncident::STATUS_CONFIRMED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_HIGH, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_SCORE_SUBMISSION_BLOCKED,
                        'Confirmed anti-cheat incident #'.$incident->id.' ('.$incident->category.')',
                        'anti_cheat',
                        $reviewer,
                        now()->addDays(30),
                    );
                } elseif ($resolution === AntiCheatIncident::STATUS_RESTRICTED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_CRITICAL, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
                        'Restricted for anti-cheat incident #'.$incident->id.' ('.$incident->category.')',
                        'anti_cheat',
                        $reviewer,
                    );
                } else {
                    // cleared / dismissed — false-positive-safe close.
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'resolution' => $resolution,
                    ], $incident->tournament);
                }
            }

            // Phase 11 — notify the accused and the reporter of the outcome.
            $link = NotificationService::link('security.incidents.index');

            if ($accused !== null) {
                $this->notifications->send(
                    $accused,
                    Notification::TYPE_ANTI_CHEAT_RESOLVED,
                    'Anti-cheat incident resolved',
                    'An anti-cheat incident about you was resolved: '.$resolutionText,
                    $link,
                    ['incident_id' => $incident->id, 'resolution' => $resolution],
                );
            }

            $reporter = $incident->reporter;

            if ($reporter !== null && $reporter->id !== $accused?->id) {
                $this->notifications->send(
                    $reporter,
                    Notification::TYPE_ANTI_CHEAT_RESOLVED,
                    'Anti-cheat incident resolved',
                    'An anti-cheat incident you reported was resolved: '.$resolutionText,
                    $link,
                    ['incident_id' => $incident->id, 'resolution' => $resolution],
                );
            }

            return $incident;
        });
    }

    /**
     * Record a deterministic match anomaly. Never throws for business
     * reasons — observation only.
     */
    public function recordAnomaly(
        GameMatch $match,
        Tournament $tournament,
        string $kind,
        string $severity,
        array $metadata = [],
    ): MatchAnomaly {
        if (! in_array($kind, [
            MatchAnomaly::KIND_ABNORMAL_KILL_RATIO,
            MatchAnomaly::KIND_REPEATED_PATTERN,
            MatchAnomaly::KIND_UNEXPECTED_PARTICIPATION,
        ], true)) {
            throw new DomainException('Unknown anomaly kind.');
        }

        if (! in_array($severity, MatchAnomaly::SEVERITIES, true)) {
            throw new DomainException('Unknown anomaly severity.');
        }

        $anomaly = new MatchAnomaly();
        $anomaly->match_id = $match->id;
        $anomaly->tournament_id = $tournament->id;
        $anomaly->kind = $kind;
        $anomaly->severity = $severity;
        $anomaly->status = $severity;
        $anomaly->metadata = $metadata;
        $anomaly->save();

        // A low-severity risk signal for the participating captains (never
        // an accusation — an observation feeding the review queue).
        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;
            if ($captain !== null) {
                $this->risk->recordSignal($captain, RiskEvent::TYPE_MATCH_ANOMALY, RiskEvent::SEVERITY_LOW, 'anti_cheat', [
                    'match_id' => $match->id,
                    'anomaly_id' => $anomaly->id,
                    'kind' => $kind,
                ], $tournament);
            }
        }

        return $anomaly;
    }

    /**
     * Deterministic score-submission analysis. Returns the anomalies created.
     * Never throws for business reasons.
     *
     * @return Collection<int, MatchAnomaly>
     */
    public function analyzeScoreSubmission(GameMatch $match, Team $team, int $kills, int $placement): Collection
    {
        $created = new Collection();
        $maxKills = (int) config('antifraud.anomaly.max_kills_per_match', 60);

        if ($kills > $maxKills) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, MatchAnomaly::SEVERITY_SUSPICIOUS, [
                'team_id' => $team->id,
                'kills' => $kills,
                'threshold' => $maxKills,
            ]));
        }

        $patternThreshold = (int) config('antifraud.anomaly.repeat_pattern_threshold', 3);

        $identical = Score::where('team_id', $team->id)
            ->where('kills', $kills)
            ->where('placement', $placement)
            ->where('id', '!=', Score::where('match_id', $match->id)->where('team_id', $team->id)->value('id'))
            ->count();

        if ($identical >= $patternThreshold) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_REPEATED_PATTERN, MatchAnomaly::SEVERITY_ANOMALY, [
                'team_id' => $team->id,
                'kills' => $kills,
                'placement' => $placement,
                'identical_count' => $identical,
            ]));
        }

        return $created;
    }
}
