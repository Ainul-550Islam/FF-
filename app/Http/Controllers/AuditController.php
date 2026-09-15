<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Support\CsvExport;
use Illuminate\Http\Request;

/**
 * Admin audit trail (Phase 13) — read-only, admin-only.
 *
 * There are intentionally no store/update/delete actions here: audit rows
 * are written by AuditLogService and can never be mutated through the UI.
 */
class AuditController extends Controller
{
    public function __construct(
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Searchable, paginated audit log with whitelisted filters.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        $logs = $this->audit->search($filters, 30);

        $actions = AuditLogService::ACTIONS;
        $entityTypes = [
            'user', 'tournament', 'team', 'team_member', 'match', 'payment',
            'payout', 'dispute', 'settlement', 'restriction', 'identity_verification',
            'anti_cheat_incident', 'wallet', 'support_ticket',
        ];
        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.audit', compact('logs', 'filters', 'actions', 'entityTypes', 'tournaments'));
    }

    /**
     * Chunked CSV export of the filtered audit log (admin only). The export
     * enforces the same whitelisted filters as the index and never includes
     * secrets or personal identifiers (payloads are already redacted).
     */
    public function export(Request $request)
    {
        $this->authorize('export', AuditLog::class);

        $filters = $this->filters($request);

        $headers = [
            'id', 'created_at', 'action', 'actor', 'entity_type', 'entity_id',
            'tournament_id', 'target_user_id', 'before', 'after', 'metadata',
            'request_id', 'source',
        ];

        return CsvExport::download('audit-log', $headers, function () use ($filters) {
            $query = $this->audit->query($filters)
                ->with(['actor:id,name,username', 'targetUser:id,name,username'])
                ->orderByDesc('id');

            foreach ($query->cursor() as $log) {
                yield [
                    $log->id,
                    optional($log->created_at)->toIso8601String(),
                    $log->action,
                    $log->actor?->name,
                    $log->entity_type,
                    $log->entity_id,
                    $log->tournament_id,
                    $log->target_user_id,
                    json_encode($log->before),
                    json_encode($log->after),
                    json_encode($log->metadata),
                    $log->request_id,
                    $log->source,
                ];
            }
        });
    }

    /**
     * Whitelisted, typed filters — no raw SQL is ever built from user input.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        return [
            'action' => $request->query('action'),
            'entity_type' => $request->query('entity_type'),
            'entity_id' => $request->query('entity_id'),
            'tournament_id' => $request->query('tournament_id'),
            'target_user_id' => $request->query('target_user_id'),
            'actor_user_id' => $request->query('actor_user_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }
}
