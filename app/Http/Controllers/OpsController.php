<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use App\Services\HealthService;
use App\Services\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpsController extends Controller
{
    public function dashboard(OperationsService $ops, BackupService $backups): View
    {
        $stats = $ops->dashboard();
        $backupList = $backups->list();

        return view('admin.ops.dashboard', [
            'stats' => $stats,
            'backups' => $backupList,
            // The dashboard renders the failed-jobs table; without this the
            // page raised "Undefined variable $failedJobs" (HTTP 500).
            'failedJobs' => $ops->failedJobs(),
        ]);
    }

    public function failedJobs(OperationsService $ops): View
    {
        $failedJobs = $ops->failedJobs();

        return view('admin.ops.failed-jobs', compact('failedJobs'));
    }

    public function retryFailedJob(string $id, OperationsService $ops): RedirectResponse
    {
        $ops->retryFailedJob($id);

        return back()->with('status', "Failed job #{$id} queued for retry.");
    }

    public function retryAllFailed(OperationsService $ops): RedirectResponse
    {
        $ops->retryAllFailed();

        return back()->with('status', 'All failed jobs queued for retry.');
    }

    public function deleteFailedJob(string $id, OperationsService $ops): RedirectResponse
    {
        $ops->deleteFailedJob($id);

        return back()->with('status', "Failed job #{$id} deleted.");
    }

    public function flushCache(Request $request, OperationsService $ops): RedirectResponse
    {
        $namespace = (string) $request->input('namespace', 'all');

        // An unknown namespace is a refusal, not a silent no-op: the operator
        // must never believe a flush happened (and nothing is audited when
        // nothing was flushed).
        if (! $ops->flushCache($namespace)) {
            return back()->with('error', "Unknown cache namespace '{$namespace}'. Nothing was flushed.");
        }

        return back()->with('status', "Cache flushed for namespace '{$namespace}'.");
    }

    public function backup(BackupService $backups): RedirectResponse
    {
        $result = $backups->create();

        if (empty($result['ok'])) {
            // The attempt is already audited (ops.backup_created with
            // failed = true) — surface the honest outcome instead of a
            // success message for a backup that does not exist on disk.
            return back()->with('error', 'Backup failed: '.($result['error'] ?? 'unknown error'));
        }

        return back()->with('status', 'Backup archive created: '.($result['name'] ?? 'success'));
    }

    public function verifyBackup(Request $request, BackupService $backups): RedirectResponse
    {
        $name = $request->input('name') ? (string) $request->input('name') : null;
        $result = $backups->verify($name);

        if (! empty($result['ok'])) {
            return back()->with('status', 'Backup verified successfully.');
        }

        return back()->with('error', 'Backup verification failed: '.($result['error'] ?? 'Corrupted'));
    }

    public function health(HealthService $health): JsonResponse
    {
        // The admin endpoint reports the readiness checks FLATTENED, so the
        // dashboard and `AdminOpsTest` can read `database` / `cache` directly.
        // The public probe (/health/ready) keeps the nested
        // `{status, checks}` envelope.
        $ready = $health->ready();

        return response()->json(array_merge(
            ['status' => $ready['status'] ?? 'not_ready'],
            (array) ($ready['checks'] ?? []),
        ));
    }
}
