<?php
namespace App\Support\Logging;
use Monolog\LogRecord;
class RedactSensitiveDataProcessor
{
    private const SENSITIVE_KEYS=['password','secret','token','jwt','api_key','private_key','DATABASE_URL','REDIS_URL','DB_PASSWORD','REDIS_PASSWORD','authorization','cookie','x-api-key'];
    public function __invoke(LogRecord $record): LogRecord
    {
        $message=$record->message; $context=$record->context;
        foreach(self::SENSITIVE_KEYS as $key){$pattern='/'.preg_quote($key,'/').'["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i'; $message=preg_replace($pattern,$key.'=***REDACTED***',$message);}
        $redactedContext=$this->redactArray($context);
        return $record->with(message:$message,context:$redactedContext);
    }
    private function redactArray(array $data): array
    {
        foreach($data as $k=>$v){
            $lower=strtolower((string)$k);
            foreach(self::SENSITIVE_KEYS as $sensitive){if(str_contains($lower,strtolower($sensitive))){$data[$k]='***REDACTED***'; continue 2;}}
            if(is_array($v))$data[$k]=$this->redactArray($v);
        }
        return $data;
    }
}
