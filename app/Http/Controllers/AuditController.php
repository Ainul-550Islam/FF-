<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tournament;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(Request $request, AuditLogService $service): View
    {
        $filters = $request->all();
        $logs = $service->search($filters);
        $actions = AuditLogService::ACTIONS;
        $entityTypes = ['user', 'tournament', 'team', 'match', 'dispute', 'payout', 'settlement', 'support_ticket', 'restriction'];
        $tournaments = Tournament::select('id', 'name')->orderBy('name')->get();

        return view('admin.audit', compact('logs', 'filters', 'actions', 'entityTypes', 'tournaments'));
    }

    public function export(Request $request): StreamedResponse
    {
        $logs = AuditLog::query()->with('actor')->latest()->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-log-export.csv"',
        ];

        return response()->stream(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Action', 'Actor', 'Entity Type', 'Entity ID', 'IP Address', 'Created At']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->action,
                    $log->actor?->name ?? 'System',
                    $log->entity_type,
                    $log->entity_id,
                    $log->ip_address,
                    $log->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
