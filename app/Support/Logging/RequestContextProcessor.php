<?php
namespace App\Support\Logging;
use App\Support\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
class RequestContextProcessor implements ProcessorInterface {public function __invoke(LogRecord $record): LogRecord{$snapshot=RequestContext::snapshot(); $record->extra=array_merge($record->extra,$snapshot); return $record;}}
