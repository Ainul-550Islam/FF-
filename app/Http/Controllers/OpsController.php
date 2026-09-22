<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use App\Services\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 16 — admin-only infrastructure operations.
 *
 * Every route here is behind the `admin` middleware. All mutating actions are
 * audited (Phase 13) and return safe, redacted output; queue and backup
 * internals are never exposed to non-admin roles.
 */
class OpsController extends Controller
{
    public function __construct(
        protected OperationsService $ops,
        protected BackupService $backups,
    ) {}

    public function dashboard(): View
    {
        return view('admin.ops.dashboard', [
            'stats' => $this->ops->dashboard(),
            'failedJobs' => $this->ops->failedJobs(10),
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json($this->ops->dashboard()['health']['checks']);
    }

    public function failedJobs(): View
    {
        return view('admin.ops.failed-jobs', [
            'failedJobs' => $this->ops->failedJobs(25),
        ]);
    }

    public function retryFailedJob(Request $request, string $id): RedirectResponse
    {
        $this->ops->retryFailedJob($id);

        return back()->with('status', 'Failed job '.$id.' queued for retry.');
    }

    public function retryAllFailed(): RedirectResponse
    {
        $this->ops->retryAllFailed();

        return back()->with('status', 'All failed jobs queued for retry.');
    }

    public function deleteFailedJob(string $id): RedirectResponse
    {
        $this->ops->deleteFailedJob($id);

        return back()->with('status', 'Failed job '.$id.' removed from the failed table.');
    }

    public function flushCache(Request $request): RedirectResponse
    {
        $namespace = (string) $request->input('namespace', 'providers');

        $ok = $this->ops->flushCache($namespace);

        return back()->with($ok ? 'status' : 'error', $ok
            ? 'Cache namespace ['.$namespace.'] flushed.'
            : 'Unknown cache namespace ['.$namespace.'].');
    }

    public function backup(): RedirectResponse
    {
        $result = $this->backups->create();

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok']
            ? 'Backup created: '.$result['name']
            : 'Backup FAILED: '.($result['error'] ?? 'unknown error'));
    }

    public function verifyBackup(): RedirectResponse
    {
        $result = $this->backups->verify();

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok']
            ? 'Backup verified: '.$result['name']
            : 'Backup verification FAILED: '.($result['error'] ?? 'unknown error'));
    }
}
