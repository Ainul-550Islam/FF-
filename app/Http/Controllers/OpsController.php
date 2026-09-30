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
        $ops->flushCache($namespace);

        return back()->with('status', "Cache flushed for namespace '{$namespace}'.");
    }

    public function backup(BackupService $backups): RedirectResponse
    {
        $result = $backups->create();

        return back()->with('status', 'Backup archive created: '.($result['filename'] ?? 'success'));
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
        return response()->json($health->ready());
    }
}
