<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Central admin/security audit trail (Phase 13).
 *
 * The single authority for writing `audit_logs`. Records are append-only
 * (the model refuses updates/deletes), carry the acting user, a closed
 * vocabulary of actions, optional entity/tournament/target references,
 * whitelisted before/after state, a request correlation id and the source
 * route. All payloads are redacted and size-capped before they are written.
 *
 * `recordQuietly()` is the integration seam: business flows call it so an
 * audit failure can never break the originating action.
 */
class AuditLogService
{
    /**
     * The closed action vocabulary. Keeping this list explicit prevents
     * typos, arbitrary actions and unreadable trails.
     */
    public const ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.register',
        'auth.password_reset',
        'auth.password_changed',
        'auth.email_verified',
        'auth.phone_verified',
        'auth.phone_unlinked',
        'auth.google_linked',
        'auth.google_unlinked',
        'auth.otp_requested',
        'auth.otp_verified',
        'auth.sessions_revoked',
        'auth.account_deactivated',
        'auth.account_reactivated',
        'auth.account_deletion_requested',
        'auth.account_deleted',
        'auth.suspicious_login',
        'profile.updated',
        'profile.username_changed',
        'profile.privacy_changed',
        'payment_method.added',
        'payment_method.removed',
        'payment_method.default',
        'payment.initiated',
        'role.change',
        'wallet.credited',
        'wallet.debited',
        'payment.verified',
        'payment.failed',
        'payment.refunded',
        'payout.approved',
        'payout.processed',
        'payout.override',
        'payout.completed',
        'payout.failed',
        'payout.cancelled',
        'settlement.tiers_saved',
        'settlement.calculated',
        'settlement.approved',
        'settlement.processed',
        'settlement.cancelled',
        'settlement.adjusted',
        'tournament.published',
        'tournament.registration_closed',
        'tournament.started',
        'tournament.completed',
        'tournament.cancelled',
        'tournament.noshows',
        'tournament.waitlist_promoted',
        'team.registered',
        'team.withdrawn',
        'team.member_added',
        'team.member_removed',
        'team.updated',
        'team.checked_in',
        'match.score_adjusted',
        'match.winner_set',
        'match.disputed',
        'match.resolved',
        'dispute.under_review',
        'dispute.assigned',
        'dispute.resolved',
        'dispute.rejected',
        'dispute.cancelled',
        'dispute.evidence_removed',
        'restriction.applied',
        'restriction.lifted',
        'identity.verified',
        'identity.rejected',
        'anti_cheat.opened',
        'anti_cheat.resolved',
        'support.created',
        'support.replied',
        'support.assigned',
        'support.status_changed',
        'support.internal_note',
        'support.reopened',
        'auth.api_token_issued',
        'auth.api_token_revoked',
        'auth.api_client_created',
        'auth.api_client_revoked',
        'webhook.endpoint_created',
        'webhook.secret_rotated',
        'webhook.endpoint_status',
        'ops.backup_created',
        'ops.backup_verified',
        'ops.backup_restored',
        'ops.cache_flushed',
        'ops.failed_job_retried',
        'ops.failed_jobs_retried',
        'ops.failed_job_deleted',
    ];

    /**
     * Record an audit entry. Throws on failure — business flows should prefer
     * recordQuietly().
     */
    public function record(
        ?User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown audit action [{$action}].");
        }

        $log = new AuditLog();
        $log->actor_user_id = $actor?->id;
        $log->action = $action;
        $log->entity_type = $entityType;
        $log->entity_id = $entityId;
        $log->tournament_id = ($options['tournament'] ?? null) instanceof Tournament
            ? $options['tournament']->id
            : ($options['tournament_id'] ?? null);
        $log->target_user_id = ($options['target_user'] ?? null) instanceof User
            ? $options['target_user']->id
            : ($options['target_user_id'] ?? null);
        $log->before = $this->capPayload(array_key_exists('before', $options) ? $this->redact((array) $options['before']) : null);
        $log->after = $this->capPayload(array_key_exists('after', $options) ? $this->redact((array) $options['after']) : null);
        $log->metadata = $this->capPayload(array_key_exists('metadata', $options) ? $this->redact((array) $options['metadata']) : null);
        $log->request_id = $this->requestId();
        $log->source = $this->source();
        $log->save();

        return $log;
    }

    /**
     * Record without ever throwing into the caller (integration seam).
     */
    public function recordQuietly(
        ?User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): ?AuditLog {
        try {
            return $this->record($actor, $action, $entityType, $entityId, $options);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * An admin action with a user-facing label (same as record, named for
     * readability at call sites).
     */
    public function recordAdminAction(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        return $this->record($actor, $action, $entityType, $entityId, $options);
    }

    /**
     * A security/trust & safety action (same as record, named for
     * readability at call sites).
     */
    public function recordSecurityAction(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        return $this->record($actor, $action, $entityType, $entityId, $options);
    }

    /**
     * Record a change against an Eloquent model. `entity_type` is derived
     * from the model class; the model's `tournament_id` (when present) is
     * captured automatically.
     */
    public function recordModelChange(
        ?User $actor,
        string $action,
        Model $model,
        ?array $before = null,
        ?array $after = null,
        array $options = [],
    ): AuditLog {
        if (! array_key_exists('tournament_id', $options)) {
            $options['tournament_id'] = $model->getAttribute('tournament_id');
        }

        return $this->record($actor, $action, Str::snake(class_basename($model)), $model->getKey(), $options + [
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Build the whitelisted filter query shared by the index and the CSV
     * export. Filter fields and the sort column are whitelisted — no raw SQL
     * is ever assembled from user input.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = AuditLog::query();

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', (string) $filters['entity_type']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', (int) $filters['entity_id']);
        }

        if (! empty($filters['tournament_id'])) {
            $query->where('tournament_id', (int) $filters['tournament_id']);
        }

        if (! empty($filters['target_user_id'])) {
            $query->where('target_user_id', (int) $filters['target_user_id']);
        }

        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * Filtered, paginated audit search.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at';

        if (! in_array($sort, ['created_at'], true)) {
            $sort = 'created_at';
        }

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->query($filters)
            ->with(['actor:id,name,username,role', 'targetUser:id,name,username,role', 'tournament:id,name'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);
    }

    /**
     * The most recent audit entries for one entity (for "related history").
     *
     * @return Collection<int, AuditLog>
     */
    public function relatedHistory(string $entityType, int $entityId, int $limit = 50): Collection
    {
        return AuditLog::query()
            ->with(['actor:id,name,username,role'])
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The per-request correlation id (set by AssignAuditRequestId middleware).
     */
    public function requestId(): string
    {
        return (string) request()->attributes->get('audit_request_id', Str::uuid());
    }

    /**
     * The route name (or path) that produced this entry.
     */
    public function source(): ?string
    {
        try {
            return request()->route()?->getName() ?? request()->path();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Recursively redact sensitive keys and cap individual values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $keys = (array) config('audit.redact_keys', []);

        $result = [];

        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, $keys, true)) {
                $result[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }

            $result[$key] = $this->capValue($value);
        }

        return $result;
    }

    /**
     * Cap an individual string value.
     */
    protected function capValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $max = (int) config('audit.max_value_chars', 500);

        return mb_strlen($value) > $max
            ? mb_substr($value, 0, $max) . '…'
            : $value;
    }

    /**
     * Cap a whole JSON payload; oversized payloads become a truncated preview.
     *
     * @return array<string, mixed>|null
     */
    protected function capPayload(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data);
        $max = (int) config('audit.max_payload_chars', 4000);

        if ($json !== false && strlen($json) <= $max) {
            return $data;
        }

        return [
            '_truncated' => true,
            '_note' => 'Payload exceeded the size cap and was truncated.',
            'preview' => $json === false ? '' : substr($json, 0, $max),
        ];
    }
}
