<?php

namespace App\Support\Logging;

use App\Support\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Phase 16 — attaches the safe per-request correlation context to every log
 * record as structured `extra` fields: request_id, method, path, route and,
 * where present, numeric user/token ids. Console processes (queue workers,
 * scheduler) simply log without those fields.
 *
 * Registered as a Monolog processor on the structured channels in
 * config/logging.php.
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if (RequestContext::requestId() !== null) {
            return $record->with(extra: array_merge($record->extra, RequestContext::snapshot()));
        }

        return $record;
    }
}
