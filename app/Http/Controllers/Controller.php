<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function isAdmin($user = null): bool
    {
        $u = $user ?? auth()->user();

        return $u && $u->isAdmin();
    }

    protected function isStaff($user = null): bool
    {
        $u = $user ?? auth()->user();

        return $u && $u->isStaff();
    }

    protected function auditLog(string $action, array $context = []): void
    {
        try {
            if (app()->bound(AuditLogService::class)) {
                app(AuditLogService::class)->log($action, $context);
            } else {
                Log::channel('audit')->info($action, $context + [
                    'user_id' => auth()->id(),
                    'ip' => request()->ip(),
                    'request_id' => request()->header('X-Request-ID'),
                ]);
            }
        } catch (\Throwable $e) {
            // Never break main flow for audit failure
            Log::warning('audit_log_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
